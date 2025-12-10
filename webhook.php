<?php
// glimmer/webhook.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/GeminiService.php';

// 1. 接收 LINE Webhook 資料
$content = file_get_contents('php://input');
$events = json_decode($content, true);

if (empty($events['events'])) {
    echo "OK";
    exit;
}

$db = Database::getInstance()->getConnection();
$gemini = new GeminiService();

foreach ($events['events'] as $event) {
    if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
        
        $userId = $event['source']['userId'];
        $userMsg = trim($event['message']['text']);
        $replyToken = $event['replyToken'];

        // ==========================================
        // 指令區
        // ==========================================

        // 指令 1: 設定人設
        if (mb_strpos($userMsg, '設定人設：') === 0) {
            $newPrompt = trim(mb_substr($userMsg, 5)); // 去掉前5個字
            
            if (mb_strlen($newPrompt) < 2) {
                replyText($replyToken, "人設描述太短囉，請多告訴我一點細節。");
                continue;
            }

            updateUserPersona($db, $userId, $newPrompt);
            clearHistory($db, $userId); // 設定新人設後，清除舊記憶
            
            replyText($replyToken, "收到！人設已更新，記憶已重置。\n現在試著跟我說話看看？");
            continue;
        }

        // 指令 2: 查看目前人設
        if ($userMsg === '查看人設') {
            $currentPrompt = getUserPersona($db, $userId);
            $reply = $currentPrompt ? "📜 目前的人設指令：\n\n" . $currentPrompt : "📜 目前使用預設人設（溫暖的微光角落）。";
            replyText($replyToken, $reply);
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
        
        $personaPrompt = getUserPersona($db, $userId);
        $history = getChatHistory($db, $userId, 10);
        
        // 呼叫 Gemini AI
        $aiReply = $gemini->generateReply($userMsg, $history, $personaPrompt);

        saveChat($db, $userId, 'user', $userMsg);
        
        if ($aiReply) {
            saveChat($db, $userId, 'model', $aiReply);
            replyText($replyToken, $aiReply);
        }
    }
}

// ----------------------------------------------------
// 輔助函式庫
// ----------------------------------------------------

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

function getChatHistory($pdo, $userId, $limit) {
    $stmt = $pdo->prepare("
        SELECT role, message FROM (
            SELECT role, message, created_at 
            FROM chat_logs 
            WHERE line_user_id = ? 
            ORDER BY id DESC LIMIT ?
        ) sub ORDER BY created_at ASC
    ");
    $stmt->execute([$userId, $limit]);
    $rows = $stmt->fetchAll();
    
    $formatted = [];
    foreach ($rows as $row) {
        $formatted[] = [
            'role' => $row['role'],
            'parts' => [['text' => $row['message']]]
        ];
    }
    return $formatted;
}
?>