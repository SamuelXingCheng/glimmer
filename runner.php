<?php
// glimmer/runner.php (後台執行機)

// 允許長時間執行 (AI 思考時間)
ignore_user_abort(true);
set_time_limit(120); 

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/OpenAIService.php';

$db = Database::getInstance()->getConnection();
$aiService = new OpenAIService();

// 1. 撈取待處理的工作 (一次只處理一筆)
// 我們在這裡使用 status = 'pending' 來判斷
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
$userMsg = $task['message'];

// 2. 標記為處理中 (防止其他 runner 重複處理)
$db->prepare("UPDATE chat_logs SET status = 'processing' WHERE id = ?")->execute([$id]);

try {
    // 3. 準備對話歷史與人設
    // 需要重新實現 getChatHistory，確保只讀取 completed 的歷史紀錄
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

    // 4. 呼叫 OpenAI (這裡會花 3~5 秒，但不會卡住 Webhook)
    $aiReply = $aiService->generateReply($userMsg, $history, $personaPrompt);

    if ($aiReply) {
        // 5. 使用 Push API 主動推播回覆
        pushMessage($userId, $aiReply);
        
        // 6. 存入 AI 回覆
        $db->prepare("INSERT INTO chat_logs (line_user_id, role, message, status) VALUES (?, 'model', ?, 'completed')")->execute([$userId, $aiReply]);
    }

    // 7. 標記完成
    $db->prepare("UPDATE chat_logs SET status = 'completed' WHERE id = ?")->execute([$id]);

} catch (Exception $e) {
    // 發生錯誤，標記為 error
    $db->prepare("UPDATE chat_logs SET status = 'error' WHERE id = ?")->execute([$id]);
    error_log("Runner 處理失敗 (ID: $id): " . $e->getMessage());
}

echo "Task $id completed.";


// --- 輔助函式 (專為 Runner 設計) ---

function pushMessage($userId, $text) {
    // PUSH API 實現 (請從之前的 webhook.php 複製並修改為 Push 邏輯)
    $url = "https://api.line.me/v2/bot/message/push";
    $data = [
        'to' => $userId,
        'messages' => [['type' => 'text', 'text' => $text]]
    ];
    // 這裡你需要使用 cURL 來發送 Push API 請求
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
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getUserDataForRunner($pdo, $userId) {
    // 獲取人設 Prompt
    $stmt = $pdo->prepare("SELECT persona_prompt FROM users WHERE line_user_id = ?");
    $stmt->execute([$userId]);
    $data = $stmt->fetch();
    
    if (!$data) return null;

    // 🚨 新增：撈取最新的長時記憶摘要
    $ltm_stmt = $pdo->prepare("SELECT summary_text FROM user_ltm_summaries WHERE line_user_id = ? ORDER BY created_at DESC LIMIT 1");
    $ltm_stmt->execute([$userId]);
    $ltm_summary = $ltm_stmt->fetchColumn();

    $data['ltm_summary'] = $ltm_summary ?: ''; // 如果沒有摘要，則為空字串

    return $data;
}
?>