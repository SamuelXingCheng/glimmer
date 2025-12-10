<?php
// glimmer/webhook.php

// 1. 設定腳本執行時間
set_time_limit(60); 

// 2. 開啟錯誤紀錄 (方便除錯)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 【Debug Log】
error_log("------------------------------------------------");
error_log("【Webhook】程式開始執行...");

require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/OpenAIService.php'; 

// 接收 LINE 資料
$content = file_get_contents('php://input');
$events = json_decode($content, true);

// 握手測試
if (empty($events['events'])) {
    echo "OK";
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log("DB Error: " . $e->getMessage());
    exit;
}

$aiService = new OpenAIService();

foreach ($events['events'] as $event) {
    
    // 只處理文字訊息
    if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
        
        $userId = $event['source']['userId'];
        $userMsg = trim($event['message']['text']);
        $replyToken = $event['replyToken'];

        error_log("MSG: $userMsg (User: $userId)");

        // ==========================================
        // 🚀 指令區
        // ==========================================

        // 指令 1: 觸發 LIFF 設定頁面
        if ($userMsg == '開始設定' || $userMsg == '設定人設' || $userMsg == '修改人設') {
            
            // 🔴 請將這裡換成你的 LIFF URL (例如 https://liff.line.me/1657xxxx-xxxx)
            $liffUrl = "https://liff.line.me/2008670429-XlQ1dMMK"; 

            $msg = "想要打造專屬的知心好友嗎？\n\n👇 點擊下方連結開始「捏臉」：\n$liffUrl";
            replyText($replyToken, $msg);
            continue;
        }

        // 指令 2: 查看目前設定 (從資料庫讀取 JSON)
        if ($userMsg === '查看人設') {
            $row = getUserData($db, $userId);
            if ($row && !empty($row['persona_config'])) {
                $c = json_decode($row['persona_config'], true);
                $info = "📜 目前設定：\n";
                $info .= "• 名字：{$c['name']}\n";
                $info .= "• 設定：{$c['gender']}\n";
                $info .= "• 關係：{$c['relationship']}\n";
                $info .= "• 性格：{$c['personality']}";
                replyText($replyToken, $info);
            } else {
                replyText($replyToken, "目前還沒有設定人設喔！請輸入「開始設定」。");
            }
            continue;
        }

        // 指令 3: 清除記憶
        if ($userMsg === '清除記憶' || $userMsg === '重置') {
            clearHistory($db, $userId);
            replyText($replyToken, "🧹 記憶已清除，我們可以重新開始了。");
            continue;
        }
        
        // ==========================================
        // 對話區
        // ==========================================
        try {
            // 1. 取得人設 Prompt (這是在 save_persona.php 裡生成的)
            $userData = getUserData($db, $userId);
            $personaPrompt = $userData ? $userData['persona_prompt'] : null;

            // 2. 取得歷史紀錄
            $history = getChatHistory($userId, 10);
            
            // 3. 呼叫 OpenAI
            $aiReply = $aiService->generateReply($userMsg, $history, $personaPrompt);

            if ($aiReply) {
                saveChat($db, $userId, 'user', $userMsg);
                saveChat($db, $userId, 'model', $aiReply);
                replyText($replyToken, $aiReply);
            } else {
                replyText($replyToken, "我現在腦袋有點打結... (Empty Response)");
            }

        } catch (Exception $e) {
            error_log("Error: " . $e->getMessage());
            replyText($replyToken, "發生錯誤，請稍後再試。");
        }
    }
}

echo "OK";

// ====================================================
// 輔助函式庫
// ====================================================

function replyText($replyToken, $text) {
    $url = "https://api.line.me/v2/bot/message/reply";
    $data = [
        'replyToken' => $replyToken, 
        'messages' => [['type' => 'text', 'text' => $text]]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json", 
        "Authorization: Bearer " . LINE_CHANNEL_ACCESS_TOKEN
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function getUserData($pdo, $userId) {
    // 同時讀取 Prompt 和 JSON 設定
    $stmt = $pdo->prepare("SELECT persona_prompt, persona_config FROM users WHERE line_user_id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch();
}

function saveChat($pdo, $userId, $role, $msg) {
    $stmt = $pdo->prepare("INSERT INTO chat_logs (line_user_id, role, message) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $role, $msg]);
}

function clearHistory($pdo, $userId) {
    $stmt = $pdo->prepare("DELETE FROM chat_logs WHERE line_user_id = ?");
    $stmt->execute([$userId]);
}

function getChatHistory($userId, $limit = 10) {
    $db = Database::getInstance();
    $pdo = $db->getConnection(); 
    
    // 使用子查詢來正確排序 (先取最新的 N 筆 DESC，再轉成 ASC)
    $sql = "SELECT * FROM (
                SELECT * FROM chat_logs 
                WHERE line_user_id = :user_id 
                ORDER BY created_at DESC 
                LIMIT :limit
            ) sub ORDER BY created_at ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $userId);
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}
?>