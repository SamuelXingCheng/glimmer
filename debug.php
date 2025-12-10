<?php
// glimmer/debug.php
// 開啟所有錯誤顯示，讓我們看到真正的兇手
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Glimmer Debug Tool</h1>";

// 1. 檢查 .env 是否存在
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    echo "<p style='color:green;'>✅ .env 檔案存在。</p>";
} else {
    echo "<p style='color:red;'>❌ 錯誤：找不到 .env 檔案！請確認已上傳。</p>";
}

// 2. 測試載入 config
echo "<hr><h3>測試載入 Config...</h3>";
try {
    require_once 'config.php';
    
    // 檢查常數是否定義
    $checks = ['DB_HOST', 'DB_NAME', 'DB_USER', 'LINE_CHANNEL_ACCESS_TOKEN', 'GEMINI_API_KEY'];
    $missing = [];
    foreach ($checks as $c) {
        if (!defined($c)) {
            $missing[] = $c;
        }
    }
    
    if (empty($missing)) {
        echo "<p style='color:green;'>✅ 設定檔載入成功，所有關鍵常數已定義。</p>";
    } else {
        echo "<p style='color:red;'>❌ 設定檔載入不完整，缺少常數：<br>" . implode(', ', $missing) . "</p>";
        // 如果常數沒定義，下面資料庫連線一定會崩潰，所以先停在這
        exit;
    }

} catch (Throwable $e) {
    echo "<p style='color:red;'>❌ Config 載入發生錯誤：" . $e->getMessage() . "</p>";
    exit;
}

// 3. 測試資料庫連線
echo "<hr><h3>測試資料庫連線...</h3>";
try {
    require_once 'src/Database.php';
    $db = Database::getInstance()->getConnection();
    echo "<p style='color:green;'>✅ 資料庫連線成功！</p>";
} catch (Throwable $e) {
    echo "<p style='color:red;'>❌ 資料庫連線失敗：" . $e->getMessage() . "</p>";
}

echo "<hr><p>測試結束。如果是綠燈，請回到 LINE Developer Console 再按一次 Verify。</p>";
?>