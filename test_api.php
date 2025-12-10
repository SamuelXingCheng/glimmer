<?php
// glimmer/test_api.php
require_once 'config.php';

echo "<h1>Gemini 連線測試</h1>";
echo "API Key: " . substr(GEMINI_API_KEY, 0, 5) . "......<br>";

// ✅ 修正後：讀取 .env 設定的 GEMINI_MODEL
$model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash';
$url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . GEMINI_API_KEY;

$data = [
    'contents' => [
        ['parts' => [['text' => '哈囉，這是一個測試連線。']]]
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10秒逾時

$start = microtime(true);
$response = curl_exec($ch);
$end = microtime(true);

if (curl_errno($ch)) {
    echo "<h2 style='color:red'>連線失敗！</h2>";
    echo "錯誤原因: " . curl_error($ch) . "<br>";
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    echo "<h2>HTTP 狀態碼: $httpCode</h2>";
    echo "耗時: " . round($end - $start, 2) . " 秒<br>";
    echo "<pre>" . htmlspecialchars($response) . "</pre>";
}
curl_close($ch);
?>