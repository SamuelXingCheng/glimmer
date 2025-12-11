<?php
// glimmer/daily_greeting.php
// 每日自動推送問候 (配合 runner.php 同樣的資料結構)

// 顯示錯誤以便除錯 (上線後可註解掉)
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Encryption.php';
require_once __DIR__ . '/src/GeminiService.php'; // 假設主要使用 Gemini

// --- 1. 輔助函式：發送 Push Message ---
function pushMessage($userId, $text) {
    if (!defined('LINE_CHANNEL_ACCESS_TOKEN')) return false;
    
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
    $result = curl_exec($ch);
    curl_close($ch);
    return true;
}

// --- 2. 主程式開始 ---

$db = Database::getInstance()->getConnection();
$geminiService = new GeminiService();

// 每日任務指令
$taskInstruction = "請為今天的用戶生成一句簡短、每日的問候訊息。訊息內容必須**少於 40 個中文字**，並包含**一句鼓勵、提醒或溫馨的祝福**。請直接輸出問候訊息，不要包含其他解釋或開頭語。請務必嚴格遵循你的人設與個性來撰寫。";

try {
    // 步驟 A: 撈取所有有設定人設的用戶
    // 🚨 修正：配合 save_persona.php，資料表名稱為 'users'
    // 同時撈取 line_user_id 和 加密後的 persona_prompt
    $sql = "SELECT line_user_id, persona_prompt FROM users WHERE persona_prompt IS NOT NULL";
    $stmt = $db->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($users) . " users.\n";

    foreach ($users as $user) {
        $userId = $user['line_user_id'];
        $encryptedPersona = $user['persona_prompt'];
        $decryptedPersona = "";

        // 步驟 B: 解密人設
        try {
            $decryptedPersona = Encryption::decrypt($encryptedPersona);
        } catch (Exception $e) {
            error_log("User $userId 人設解密失敗: " . $e->getMessage());
            continue;
        }

        // 步驟 C: (選用) 撈取長時記憶 LTM，讓問候更貼心
        // 配合 runner.php 的結構，從 user_ltm_summaries 撈取
        $ltmSummary = "";
        try {
            $stmtLtm = $db->prepare("SELECT summary_text FROM user_ltm_summaries WHERE line_user_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmtLtm->execute([$userId]);
            $encryptedLtm = $stmtLtm->fetchColumn();
            if ($encryptedLtm) {
                $ltmSummary = Encryption::decrypt($encryptedLtm);
            }
        } catch (Exception $e) {
            // LTM 失敗不影響發送，僅記錄 Log
            error_log("User $userId LTM 讀取失敗: " . $e->getMessage());
        }

        // 步驟 D: 組合最終 Prompt (人設 + LTM + 任務)
        $fullPrompt = $decryptedPersona;
        if (!empty($ltmSummary)) {
            $fullPrompt .= "\n\n【用戶長時記憶摘要(可參考此內容來客製化問候)】：\n$ltmSummary";
        }
        $fullPrompt .= "\n\n--- [今日問候任務指令] ---\n" . $taskInstruction;

        // 步驟 E: 生成問候語
        // 參數: (空User Msg, 空歷史, System Prompt, 溫度1.0)
        $greeting = $geminiService->generateReply('', [], $fullPrompt, 1.0);

        // 簡單過濾錯誤訊息
        if (mb_strlen($greeting) < 5 || strpos($greeting, '錯誤') !== false) {
            error_log("User $userId 生成失敗: $greeting");
            continue;
        }

        // 步驟 F: 發送與紀錄
        pushMessage($userId, $greeting);
        
        // 🚨 重要：將 AI 主動發送的問候也存入 chat_logs，這樣歷史紀錄才完整
        // 配合 runner.php，AI 的回覆需要加密存入
        try {
            $encryptedGreeting = Encryption::encrypt($greeting);
            $stmtLog = $db->prepare("INSERT INTO chat_logs (line_user_id, role, message, status) VALUES (?, 'model', ?, 'completed')");
            $stmtLog->execute([$userId, $encryptedGreeting]);
        } catch (Exception $e) {
            error_log("User $userId 寫入 chat_logs 失敗: " . $e->getMessage());
        }

        echo "Sent to $userId: $greeting\n";
        
        // 休息一下，避免觸發 Rate Limit
        usleep(200000); // 0.2秒
    }

} catch (Exception $e) {
    error_log("Daily Greeting Critical Error: " . $e->getMessage());
}
?>