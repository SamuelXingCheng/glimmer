<?php
// glimmer/runner.php (後台執行機)

// 允許長時間執行 (AI 思考時間)
ignore_user_abort(true);
set_time_limit(120); 

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Encryption.php'; // 🚨 確保 Encryption 類別被載入

// 🚨 1. 動態初始化 AI 服務 (服務切換邏輯)
$aiService = null; 
if (ACTIVE_AI_SERVICE === 'gemini') {
    require_once __DIR__ . '/src/GeminiService.php';
    $aiService = new GeminiService();
    error_log("使用GeminiService！");
} elseif (ACTIVE_AI_SERVICE === 'openai') {
    require_once __DIR__ . '/src/OpenAIService.php';
    $aiService = new OpenAIService();
    error_log("使用OpenAIService！"); // 修正 Log 輸出
} else {
    error_log("FATAL: 未知的 AI 服務設定！");
    exit;
}


$db = Database::getInstance()->getConnection();

// 1. 撈取待處理的工作 (一次只處理一筆)
$stmt = $db->prepare("SELECT * FROM chat_logs WHERE status = 'pending' AND role = 'user' ORDER BY created_at ASC LIMIT 1");
$stmt->execute();
$task = $stmt->fetch();

if (!$task) {
    // 沒工作，退出
    echo "No task found.";
    exit;
}

$id = $task['id'];
$userId = $task['line_user_id'];
$userMsgEncrypted = $task['message']; // 🚨 訊息是加密的

// 🚨 2. 解密當前使用者訊息
try {
    $userMsg = Encryption::decrypt($userMsgEncrypted);
} catch (Exception $e) {
    error_log("Runner 解密使用者訊息失敗 (ID: $id): " . $e->getMessage());
    $db->prepare("UPDATE chat_logs SET status = 'error' WHERE id = ?")->execute([$id]);
    exit;
}


// 3. 標記為處理中 (防止其他 runner 重複處理)
$db->prepare("UPDATE chat_logs SET status = 'processing' WHERE id = ?")->execute([$id]);

try {
    // 4. 準備對話歷史與人設
    $history = getChatHistoryForRunner($db, $userId, 10);
    $userData = getUserDataForRunner($db, $userId);
    $personaPrompt = $userData ? $userData['persona_prompt'] : null;

    // 🚨 關鍵修改：組裝最終 System Prompt
    $finalSystemPrompt = $personaPrompt;

    // 如果有長時記憶，將其放在最前面，提供給 AI 參考
    if (!empty($userData['ltm_summary'])) {
        $finalSystemPrompt .= "\n\n【用戶長時記憶摘要】：\n";
        $finalSystemPrompt .= $userData['ltm_summary'];
        $finalSystemPrompt .= "\n";
    }

    // 5. 呼叫 AI 服務
    $aiReply = $aiService->generateReply($userMsg, $history, $finalSystemPrompt); // 🚨 傳入已解密的使用者訊息 $userMsg

    if ($aiReply) {
        // 6. 使用 Push API 主動推播回覆
        pushMessage($userId, $aiReply);
        
        // 7. 🚨 存入 AI 回覆 (AI 回覆需加密)
        $aiReplyEncrypted = Encryption::encrypt($aiReply); 
        $db->prepare("INSERT INTO chat_logs (line_user_id, role, message, status) VALUES (?, 'model', ?, 'completed')")->execute([$userId, $aiReplyEncrypted]);
    }

    // 8. 標記完成
    $db->prepare("UPDATE chat_logs SET status = 'completed' WHERE id = ?")->execute([$id]);

} catch (Exception $e) {
    // 發生錯誤，標記為 error
    $db->prepare("UPDATE chat_logs SET status = 'error' WHERE id = ?")->execute([$id]);
    error_log("Runner 處理失敗 (ID: $id): " . $e->getMessage());
}

echo "Task $id completed.";


// --- 輔助函式 (專為 Runner 設計) ---

function pushMessage($userId, $text) {
    // ... (pushMessage 函式保持不變) ...
    $url = "https://api.line.me/v2/bot/message/push";
    $data = [
        'to' => $userId,
        'messages' => [['type' => 'text', 'text' => $text]]
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function getChatHistoryForRunner($pdo, $userId, $limit = 10) {
    // 確保只讀取已經完成 (completed) 的對話
    $sql = "SELECT role, message FROM chat_logs  
            WHERE line_user_id = ? AND status = 'completed'
            ORDER BY created_at DESC 
            LIMIT ? OFFSET 1"; // 忽略剛剛存的 pending 訊息
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(1, $userId);
    $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    // 🚨 解密歷史對話內容
    foreach ($results as &$row) {
        try {
            $row['message'] = Encryption::decrypt($row['message']);
        } catch (Exception $e) {
             error_log("Chat history 解密失敗: " . $e->getMessage());
             $row['message'] = ''; // 解密失敗則清空
        }
    }
    return $results;
}

function getUserDataForRunner($pdo, $userId) {
    // 獲取人設 Prompt
    $stmt = $pdo->prepare("SELECT persona_prompt FROM users WHERE line_user_id = ?");
    $stmt->execute([$userId]);
    $data = $stmt->fetch();
    
    if (!$data) return null;

    // 🚨 解密人設 Prompt
    try {
        $data['persona_prompt'] = Encryption::decrypt($data['persona_prompt']);
    } catch (Exception $e) {
         error_log("Persona Prompt 解密失敗: " . $e->getMessage());
         $data['persona_prompt'] = '';
    }

    // 🚨 新增：撈取最新的長時記憶摘要 (summary_text 也是加密的)
    $ltm_stmt = $pdo->prepare("SELECT summary_text FROM user_ltm_summaries WHERE line_user_id = ? ORDER BY created_at DESC LIMIT 1");
    $ltm_stmt->execute([$userId]);
    $ltm_summary_encrypted = $ltm_stmt->fetchColumn();

    $ltm_summary = '';
    if ($ltm_summary_encrypted) {
        try {
            $ltm_summary = Encryption::decrypt($ltm_summary_encrypted);
        } catch (Exception $e) {
             error_log("LTM 摘要解密失敗: " . $e->getMessage());
        }
    }

    $data['ltm_summary'] = $ltm_summary ?: ''; // 如果沒有摘要，則為空字串

    return $data;
}
?>