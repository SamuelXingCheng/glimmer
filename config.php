<?php
// glimmer/config.php

/**
 * 簡易的 .env 載入器
 * 讀取同目錄下的 .env 檔案，並設定為常數與環境變數
 */
function loadEnv($path) {
    if (!file_exists($path)) {
        // 如果找不到 .env，可以選擇報錯或略過
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // 忽略註解
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // 解析 KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            // 移除可能存在的引號
            $value = trim($value, '"\'');

            // 如果尚未定義，則定義為常數
            if (!defined($name)) {
                define($name, $value);
                putenv("$name=$value"); // 同時寫入環境變數
            }
        }
    }
}

// 1. 載入 .env
loadEnv(__DIR__ . '/.env');

// 2. 檢查必要設定 (防呆)
$required = ['DB_HOST', 'DB_USER', 'DB_PASS', 'LINE_CHANNEL_ACCESS_TOKEN', 'GEMINI_API_KEY'];
foreach ($required as $key) {
    if (!defined($key)) {
        error_log("Config Error: Missing $key in .env");
        // 為了避免直接顯示錯誤給用戶，這裡只記錄 log
    }
}

// 3. 設定預設值 (如果 .env 沒寫)
if (!defined('GEMINI_MODEL')) define('GEMINI_MODEL', 'gemini-1.5-flash');

?>