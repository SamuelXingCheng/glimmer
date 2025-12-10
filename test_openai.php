<?php
// glimmer/test_openai.php
require_once 'config.php';

echo "<h1>OpenAI 連線測試</h1>";
echo "API Key 前五碼: " . substr(OPENAI_API_KEY, 0, 5) . "......<br>";

// 1. 使用最簡單的 file_get_contents 測試 (避開 cURL 問題)
$url = "https://api.openai.com/v1/chat/completions";

$data = [
    'model' => 'gpt-4o-mini',
    'messages' => [
        ['role' => 'user', 'content' => 'Say Hello in one word.']
    ],
    'max_tokens' => 10
];

$options = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n" .
                     "Authorization: Bearer " . OPENAI_API_KEY . "\r\n",
        'content' => json_encode($data),
        'timeout' => 15
    ]
];

echo "<h3>正在嘗試連線到 $url ...</h3>";

$context  = stream_context_create($options);
$start = microtime(true);

// 嘗試連線
$result = @file_get_contents($url, false, $context);
$end = microtime(true);

if ($result === FALSE) {
    $error = error_get_last();
    echo "<h2 style='color:red'>連線失敗！</h2>";
    echo "錯誤訊息: " . ($error['message'] ?? '未知錯誤') . "<br>";
    echo "<p>可能原因：主機防火牆擋住了 OpenAI，或是 DNS 解析失敗。</p>";
} else {
    echo "<h2 style='color:green'>連線成功！</h2>";
    echo "耗時: " . round($end - $start, 2) . " 秒<br>";
    echo "回應內容: <pre>" . htmlspecialchars($result) . "</pre>";
}
?>