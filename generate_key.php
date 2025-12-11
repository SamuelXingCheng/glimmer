<?php
// 生成 32 位元組的二進制字串，然後 base64 編碼，確保高熵和安全長度
$key = base64_encode(openssl_random_pseudo_bytes(32)); 
echo "您的 ENCRYPTION_KEY:\n" . $key . "\n";
?>