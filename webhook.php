<?php
// glimmer/webhook.php (收單機)

// 讓 PHP 盡快結束，避免 LINE 超時和主機資源鎖定
set_time_limit(5); 

require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/Encryption.php'; // 🚨 1. 引入 Encryption 類別

// 1. 接收資料
$content = file_get_contents('php://input');
$events = json_decode($content, true);

if (empty($events['events'])) {
    echo "OK";
    exit;
}

$db = Database::getInstance()->getConnection();
$hasNewTask = false;

// 2. 快速收單 (只存 DB)
foreach ($events['events'] as $event) {
    if ($event['type'] == 'message') {
        $userId = $event['source']['userId'];
        $msgType = $event['message']['type'];
        $msgToSave = null;
        
        // 關鍵修改：處理貼圖和文字
        if ($msgType == 'text') {
            $msgToSave = trim($event['message']['text']);
        } elseif ($msgType == 'sticker') {
            $stickerId = $event['message']['stickerId'];
            $packageId = $event['message']['packageId'];
            
            // 將貼圖轉化為 AI 可理解的文字描述
            $msgToSave = "[用戶傳送了貼圖] (貼圖ID: {$packageId}/{$stickerId})。請根據貼圖內容，用你的人設做出**口語化、有情感**的回覆。";
        }
        // 忽略圖片、影片、語音等其他類型

        if ($msgToSave !== null && $msgToSave !== '') {
            
            // 🚨 2. 關鍵修正：將訊息加密後再儲存
            try {
                $encryptedMsg = Encryption::encrypt($msgToSave); 
            } catch (Exception $e) {
                error_log("Webhook 加密失敗: " . $e->getMessage());
                // 如果加密失敗，則不儲存，防止明文洩露
                continue; 
            }
            
            // 儲存為 pending 狀態，等待 runner 處理
            $stmt = $db->prepare("INSERT INTO chat_logs (line_user_id, role, message, status) VALUES (?, 'user', ?, 'pending')");
            $stmt->execute([$userId, $encryptedMsg]); // 🚨 儲存加密後的訊息
            $hasNewTask = true;
        }
    }elseif ($event['type'] == 'follow') {
        $replyToken = $event['replyToken'];
        
        // 這裡填入你的 LIFF 完整網址 (請確認 ID 正確)
        // 根據你上傳的檔案，你的 LIFF ID 應該是 2008670429-XlQ1dMMK
        $liffUrl = "https://liff.line.me/2008670429-XlQ1dMMK"; 

        // 定義 Flex Message
        $flexMessage = [
            'type' => 'flex',
            'altText' => '歡迎加入！請建立您的專屬角色',
            'contents' => [
                'type' => 'bubble',
                // 🚨 移除 hero 區塊以消除大空白
                'body' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm', // 調整為 sm (Small) 讓間距更緊湊
                    'paddingAll' => '20px', // 確保邊距適中
                    'contents' => [
                        [
                            'type' => 'text',
                            'text' => '歡迎來到 微光角落',
                            'weight' => 'bold',
                            'size' => 'xl',
                            'color' => '#2C5F48' // 使用主色調
                        ],
                        [
                            'type' => 'text',
                            'text' => '為了提供最完美的陪伴體驗，請先花 30 秒設定您心目中的理想 AI 伴侶。',
                            'wrap' => true,
                            'size' => 'sm', // 稍微縮小字體
                            'color' => '#666666',
                            'margin' => 'md'
                        ]
                    ]
                ],
                'footer' => [
                    'type' => 'box',
                    'layout' => 'vertical',
                    'spacing' => 'sm',
                    'contents' => [
                        [
                            'type' => 'button',
                            'action' => [
                                'type' => 'uri',
                                'label' => '開始建立角色',
                                'uri' => $liffUrl // 跳轉到 LIFF
                            ],
                            'style' => 'primary',
                            'color' => '#2C5F48'
                        ]
                    ]
                ]
            ]
        ];

        // 直接呼叫 LINE API 回覆 (不進資料庫)
        replyMessage($replyToken, $flexMessage);
    }
}

// 3. 關鍵：僅當有新任務時才觸發 runner.php (優化資源)
if ($hasNewTask) {
    triggerRunner();
}

// 4. 立即回覆 LINE OK (解除主機資源佔用)
echo "OK";
exit;


// --- 輔助函式 ---
function triggerRunner() {
    // 獲取當前網域和路徑，確保跨環境運行
    $host = $_SERVER['HTTP_HOST'];
    $path = "/glimmer/runner.php"; 
    
    // 判斷使用 HTTP 或 HTTPS
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
    $scheme = $isHttps ? 'ssl://' : '';
    $port = $isHttps ? 443 : 80;
    
    // 使用非阻塞連線
    $fp = @fsockopen("{$scheme}{$host}", $port, $errno, $errstr, 1);
    
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
// 🚨 新增：專門用來回覆歡迎訊息的函式
function replyMessage($replyToken, $messageObj) {
    $url = "https://api.line.me/v2/bot/message/reply";
    
    $data = [
        'replyToken' => $replyToken,
        'messages' => [$messageObj]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . LINE_CHANNEL_ACCESS_TOKEN // 確保 config.php 有定義此常數
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
?>