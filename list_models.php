<?php
// glimmer/list_models.php
require_once 'config.php';

$apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '你的API_KEY';
$url = "https://generativelanguage.googleapis.com/v1beta/models?key=" . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);

echo "<h1>可用模型清單</h1>";
if (isset($data['models'])) {
    echo "<ul>";
    foreach ($data['models'] as $model) {
        // 只列出支援 generateContent 的模型
        if (in_array("generateContent", $model['supportedGenerationMethods'])) {
            $name = str_replace("models/", "", $model['name']);
            echo "<li><strong>" . $name . "</strong><br><small>" . $model['description'] . "</small></li>";
        }
    }
    echo "</ul>";
} else {
    echo "讀取失敗，回應內容：<pre>" . htmlspecialchars($response) . "</pre>";
}
?>