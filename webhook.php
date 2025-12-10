<?php
// glimmer/webhook.php (收單機)

// 讓 PHP 盡快結束，避免 LINE 超時和主機資源鎖定
set_time_limit(5); 

require_once 'config.php';
require_once 'src/Database.php';

// 1. 接收資料
$content = file_get_contents('php://input');
$events = json_decode($content, true);

if (empty($events['events'])) {
    echo "OK";
    exit;
}

$db = Database::getInstance()->getConnection();

// 2. 快速收單 (只存 DB)
foreach ($events['events'] as $event) {
    if ($event['type'] == 'message' && $event['message']['type'] == 'text') {
        $userId = $event['source']['userId'];
        $userMsg = trim($event['message']['text']);
        $replyToken = $event['replyToken'];
        
        // 儲存為 pending 狀態，等待 runner 處理
        $stmt = $db->prepare("INSERT INTO chat_logs (line_user_id, role, message, status) VALUES (?, 'user', ?, 'pending')");
        // 注意：這裡將 replyToken 暫存到 message 欄位，或者如果你有增加 reply_token 欄位則存入該欄位
        // 為了簡單，我們在這裡採用 PUSH API，所以不需要 replyToken。
        $stmt->execute([$userId, $userMsg]);
    }
}

// 3. 🚨 關鍵：使用 fsockopen 觸發後台 runner.php (射後不理)
triggerRunner();

// 4. 立即回覆 LINE OK (解除主機資源佔用)
echo "OK";
exit;


// --- 輔助函式 ---
function triggerRunner() {
    // 獲取當前網域和路徑，確保跨環境運行
    $host = $_SERVER['HTTP_HOST'];
    // 假設 runner.php 在 glimmer/ 根目錄
    $path = "/glimmer/runner.php"; 
    $port = 443;
    
    // 使用非阻塞連線
    $fp = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 1);
    
    if ($fp) {
        $out = "GET {$path} HTTP/1.1\r\n";
        $out .= "Host: {$host}\r\n";
        $out .= "Connection: Close\r\n\r\n";
        fwrite($fp, $out);
        fclose($fp);
    } else {
        error_log("Runner 觸發失敗: $errstr ($errno)");
    }
}
?>