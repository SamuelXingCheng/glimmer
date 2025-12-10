<?php
// glimmer/save_persona.php
require_once 'config.php';
require_once 'src/Database.php';

header('Content-Type: application/json');

// 接收 JSON 資料
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['userId']) || !isset($data['config'])) {
    echo json_encode(['status' => 'error', 'message' => '資料格式錯誤']);
    exit;
}

$userId = $data['userId'];
$config = $data['config'];

// 將設定轉為 JSON 字串
$configJson = json_encode($config, JSON_UNESCAPED_UNICODE);

// 建構 System Prompt (這樣我們就把複雜的組合邏輯放在這裡一次做完)
$systemPrompt = "現在開始進行角色扮演 (Roleplay)。\n";
$systemPrompt .= "你的名字是：{$config['name']}。\n";
$systemPrompt .= "你的性別/設定是：{$config['gender']}。\n";
$systemPrompt .= "你的外貌特徵：{$config['appearance']}。\n";
$systemPrompt .= "你的核心性格：{$config['personality']} (請務必在對話中展現這個個性)。\n";
$systemPrompt .= "你與使用者的關係是：{$config['relationship']}。\n";
$systemPrompt .= "使用者的暱稱是：{$config['user_nickname']}。\n";
$systemPrompt .= "請完全融入角色，不要表現出你是 AI，說話要自然、口語化。";

try {
    $db = Database::getInstance()->getConnection();
    
    // 更新 users 資料表
    // 同時更新 persona_config (詳細設定) 和 persona_prompt (給 AI 看的指令)
    $sql = "INSERT INTO users (line_user_id, persona_config, persona_prompt) VALUES (?, ?, ?) 
            ON DUPLICATE KEY UPDATE persona_config = ?, persona_prompt = ?";
            
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $configJson, $systemPrompt, $configJson, $systemPrompt]);
    
    // 清除舊的對話記憶，讓新角色重新開始
    $stmt = $db->prepare("DELETE FROM chat_logs WHERE line_user_id = ?");
    $stmt->execute([$userId]);

    echo json_encode(['status' => 'success']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>