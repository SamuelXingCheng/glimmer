<?php
// glimmer/src/Encryption.php

class Encryption {
    private static $cipher = 'aes-256-cbc';
    private static $key = ENCRYPTION_KEY; // 從 config.php 載入的常數

    /**
     * 加密資料
     * @param string $data 要加密的純文字
     * @return string 加密後的 base64 編碼字串
     */
    public static function encrypt($data) {
        if (!extension_loaded('openssl')) {
            throw new Exception("OpenSSL 擴展未啟用！無法加密。");
        }
        $ivlen = openssl_cipher_iv_length(self::$cipher);
        $iv = openssl_random_pseudo_bytes($ivlen);
        $encrypted = openssl_encrypt($data, self::$cipher, self::$key, 0, $iv);
        // 將 IV 和加密後的資料合併，並 base64 編碼，以便儲存
        return base64_encode($iv . $encrypted);
    }

    /**
     * 解密資料
     * @param string $data 從資料庫讀取的 base64 編碼字串
     * @return string 解密後的純文字
     */
    public static function decrypt($data) {
        $data = base64_decode($data);
        $ivlen = openssl_cipher_iv_length(self::$cipher);
        $iv = substr($data, 0, $ivlen);
        $encrypted = substr($data, $ivlen);
        return openssl_decrypt($encrypted, self::$cipher, self::$key, 0, $iv);
    }
}