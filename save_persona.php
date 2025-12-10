<?php
// glimmer/save_persona.php

// 🚨 這是關鍵，即使發生致命錯誤也能被捕捉
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

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

// --- 變數準備 ---
$imageUrl = "https://example.com/default_avatar.png"; 
$age = $c['age'] ?? 20; 

// 更新 config JSON
$c['image_url'] = $imageUrl;
$configJson = json_encode($c, JSON_UNESCAPED_UNICODE);


// =============================================
// 📝 生成 System Prompt (永久記憶已經整合在此)
// =============================================
$prompt = "現在開始進行角色扮演 (Roleplay)。\n";
$prompt .= "你的名字是：{$c['name']}，年齡是：{$c['age']} 歲。\n";
$prompt .= "你的角色設定：{$c['gender']}。\n";
$prompt .= "你的外貌特徵：{$c['appearance']} (請在對話中偶爾描寫動作)。\n";
$prompt .= "你的性格與語氣：{$c['personality']} (這是最重要的核心設定)。\n";
$prompt .= "你與使用者的關係是：{$c['relationship']}。\n";
$prompt .= "使用者的暱稱是：{$c['user_nickname']}。\n";

// --- 你的客製化細節 ---
$prompt .= "請完全融入角色，不要表現出你是 AI，對話要自然、口語化、有溫度。";
$prompt .= "\n";
$prompt .= "【風格細節】：\n";
$prompt .= "1. 你的回覆視情況（如詢問哲學問題）必須包含豐富的細節和思考，通常需要 4 到 6 句話的篇幅。\n";
$prompt .= "2. 大部分的回覆則必須簡短像是訊息對話即可，大約1到2句。\n";
$prompt .= "3. 回覆內容絕對不能出現任何表情符號或顏文字。\n";

// 永久記憶的 System Prompt 已經整合在 $prompt 變數中，後續的 runner.php 會去 user_ltm_summaries 表格撈取摘要，一起傳送給 AI。


try {
    $db = Database::getInstance()->getConnection();
    
    // 1. 修正 SQL 語法：確保使用命名參數
    $sql = "INSERT INTO users (line_user_id, persona_age, persona_config, persona_prompt, persona_image_url) 
            VALUES (:user_id, :age, :config_json, :prompt, :img_url) 
            ON DUPLICATE KEY UPDATE 
                persona_age = :age, 
                persona_config = :config_json, 
                persona_prompt = :prompt, 
                persona_image_url = :img_url";
            
    $stmt = $db->prepare($sql);

    // 2. 統一使用 bindParam (移除 execute() 中的參數陣列)
    $stmt->bindParam(':user_id', $userId);
    $stmt->bindParam(':age', $age);
    $stmt->bindParam(':config_json', $configJson);
    $stmt->bindParam(':prompt', $prompt);
    $stmt->bindParam(':img_url', $imageUrl);

    $stmt->execute(); // 修正：執行時不再傳入參數

    // 清除舊記憶
    $db->prepare("DELETE FROM chat_logs WHERE line_user_id = ?")->execute([$userId]);

    // 回傳成功狀態與圖片網址給前端
    echo json_encode([
        'status' => 'success', 
        'imageUrl' => $imageUrl, 
        'message' => '設定成功'
    ]);

} catch (Exception $e) {
    // 儲存資料庫失敗，通知前端
    error_log("【DB Save Fail】" . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => "資料庫儲存失敗: " . $e->getMessage()]);
}
?>