<?php
// glimmer/save_persona.php

// 1. 設定執行時間 (因為不繪圖，設短一點，更快完成)
set_time_limit(30); 

require_once 'config.php';
require_once 'src/Database.php';

header('Content-Type: application/json');

// 接收資料
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['userId']) || !isset($data['config'])) {
    echo json_encode(['status' => 'error', 'message' => '資料格式錯誤']);
    exit;
}

$userId = $data['userId'];
$c = $data['config'];

// =============================================
// 🎨 Part 1: 【暫時關閉】DALL·E 3 繪圖功能
// =============================================
$imageUrl = "https://example.com/default_avatar.png"; // 使用一個預設圖片網址
$imageGenError = "DALL-E 功能已暫時關閉。"; 
// =============================================


// =============================================
// 💾 Part 2: 儲存設定到資料庫
// =============================================

// 更新 config JSON，加入預設圖片網址
$c['image_url'] = $imageUrl;
$configJson = json_encode($c, JSON_UNESCAPED_UNICODE);

// 生成文字對話用的 System Prompt 
$prompt = "現在開始進行角色扮演 (Roleplay)。\n";
$prompt .= "你的名字是：{$c['name']}，年齡是：{$c['age']} 歲。\n";
$prompt .= "你的角色設定：{$c['gender']}。\n";
$prompt .= "你的外貌特徵：{$c['appearance']} (請在對話中偶爾描寫動作)。\n";
$prompt .= "你的性格與語氣：{$c['personality']} (這是最重要的核心設定)。\n";
$prompt .= "你與使用者的關係是：{$c['relationship']}。\n";
$prompt .= "使用者的暱稱是：{$c['user_nickname']}。\n";
$prompt .= "請完全融入角色，不要表現出你是 AI，對話要自然、口語化、有溫度。";

try {
    $db = Database::getInstance()->getConnection();
    
    // 預設年齡 (因為 Vue 頁面會傳 age，所以直接用 $c['age'])
    $age = $c['age'] ?? 20; 

    // 更新 users 表：加入 age, config, prompt, image_url
    $sql = "INSERT INTO users (line_user_id, persona_age, persona_config, persona_prompt, persona_image_url) 
            VALUES (?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE persona_age = ?, persona_config = ?, persona_prompt = ?, persona_image_url = ?";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([
        $userId, $age, $configJson, $prompt, $imageUrl,  
        $age, $configJson, $prompt, $imageUrl            
    ]);
    
    // 清除舊記憶
    $db->prepare("DELETE FROM chat_logs WHERE line_user_id = ?")->execute([$userId]);

    // 回傳成功狀態與圖片網址給前端
    echo json_encode([
        'status' => 'success', 
        'imageUrl' => $imageUrl, // 傳回預設網址
        'imageError' => $imageGenError 
    ]);

} catch (Exception $e) {
    // 儲存資料庫失敗，通知前端
    error_log("【DB Save Fail】" . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => "資料庫儲存失敗: " . $e->getMessage()]);
}
?>