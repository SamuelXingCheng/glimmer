<?php
// glimmer/webhook.php

// 1. 設定腳本執行時間
set_time_limit(60); 

// 2. 開啟詳細錯誤紀錄
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// 【Debug Start】
error_log("------------------------------------------------");
error_log("【Webhook】程式開始執行...");

require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/OpenAIService.php'; // 確認這裡是 OpenAI

$content = file_get_contents('php://input');
$events = json_decode($content, true);

// 握手測試
if (empty($events['events'])) {
    error_log("【Webhook】收到空事件 (或是 Verify 請求)");
    echo "OK";
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    error_log("【Webhook】資料庫連線成功");
} catch (Exception $e) {
    error_log("【Webhook Fatal】資料庫連線失敗: " . $e->getMessage());
    exit;
}

$aiService = new OpenAIService();

foreach ($events['events'] as $event) {
    
    if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
        
        $userId = $event['source']['userId'];
        $userMsg = trim($event['message']['text']);
        $replyToken = $event['replyToken'];

        error_log("【Webhook】收到訊息: $userMsg (User: $userId)");

        // ==========================================
        // 指令區
        // ==========================================

        // 指令 1: 設定人設
        if (mb_strpos($userMsg, '設定人設：') === 0) {
            $newPrompt = trim(mb_substr($userMsg, 5));
            if (mb_strlen($newPrompt) < 2) {
                replyText($replyToken, "人設描述太短囉。");
                continue;
            }
            updateUserPersona($db, $userId, $newPrompt);
            clearHistory($db, $userId); 
            replyText($replyToken, "收到！人設已更新，記憶已重置。");
            continue;
        }

        // 指令 2: 查看人設
        if ($userMsg === '查看人設') {
            $p = getUserPersona($db, $userId);
            replyText($replyToken, $p ? "📜 目前人設：\n$p" : "📜 目前使用預設人設。");
            continue;
        }

        // 指令 3: 清除記憶
        if ($userMsg === '清除記憶' || $userMsg === '重置') {
            clearHistory($db, $userId);
            replyText($replyToken, "🧹 記憶已清除。");
            continue;
        }
        
        // ==========================================
        // 對話區
        // ==========================================
        try {
            $personaPrompt = getUserPersona($db, $userId);
            $history = getChatHistory($userId, 10);
            
            error_log("【Webhook】準備呼叫 OpenAI Service...");
            
            // 呼叫 AI
            $aiReply = $aiService->generateReply($userMsg, $history, $personaPrompt);

            if ($aiReply) {
                error_log("【Webhook】AI 回覆內容: " . mb_substr($aiReply, 0, 20) . "...");
                
                // 存檔
                saveChat($db, $userId, 'user', $userMsg);
                saveChat($db, $userId, 'model', $aiReply);
                
                // 回覆 LINE
                replyText($replyToken, $aiReply);
                error_log("【Webhook】已發送回覆給 LINE");
            } else {
                error_log("【Webhook Error】AI 回傳內容為空！");
                replyText($replyToken, "AI 暫時無法回應 (Empty Response)");
            }

        } catch (Exception $e) {
            error_log("【Webhook Exception】處理過程發生錯誤: " . $e->getMessage());
            replyText($replyToken, "系統發生錯誤，請檢查 Log");
        }
    }
}

echo "OK";

// ====================================================
// 輔助函式庫 (之前消失的就是這些，這次補齊了)
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
    $res = curl_exec($ch);
    if(curl_errno($ch)){
         error_log("【LINE Reply Error】" . curl_error($ch));
    }
    curl_close($ch);
}

function getUserPersona($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT persona_prompt FROM users WHERE line_user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ? $row['persona_prompt'] : null;
}

function updateUserPersona($pdo, $userId, $prompt) {
    $sql = "INSERT INTO users (line_user_id, persona_prompt) VALUES (?, ?) 
            ON DUPLICATE KEY UPDATE persona_prompt = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $prompt, $prompt]);
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