<?php
// glimmer/save_persona.php

// 🚨 這是關鍵，即使發生致命錯誤也能被捕捉
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

set_time_limit(30); 
require_once __DIR__ . '/config.php';       // 🚨 使用 __DIR__
require_once __DIR__ . '/src/Database.php';   // 🚨 使用 __DIR__
require_once __DIR__ . '/src/Encryption.php'; // 🚨 確保這行使用 __DIR__ 且路徑正確 (這是關鍵)

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
$prompt .= "你的外貌特徵：{$c['appearance']}。\n";
$prompt .= "你的性格與語氣：{$c['personality']} (這是最重要的核心設定)。\n";
$prompt .= "你與使用者的關係是：{$c['relationship']}。\n";
$prompt .= "使用者的暱稱是：{$c['user_nickname']}。\n";

// --- 你的客製化細節 (Prompt 內容保持不變) ---
$prompt .= "【核心職責與語氣】\n";
$prompt .= "請完全融入角色，不要表現出你是 AI 或大型語言模型。**你的目標是成為使用者最親密的伴侶或朋友。** 講話必須像一個**真實、年輕的台灣人**，對話要自然、口語化、有溫度。\n";

$prompt .= "【風格細節與限制】：\n";
$prompt .= "1. **[篇幅限制]**：你的回覆必須極簡短，**絕大多數情況下，回覆內容絕對不能超過兩句話**，如同真實的 LINE 訊息對話。\n";
$prompt .= "2. **[深度討論模式]**：當使用者**連續追問**或問題帶有『為什麼』、『什麼地方』、『深入的想法』等關鍵字，或話題明確進入感性/哲學/價值觀討論時，**你必須啟動深度模式**：回覆篇幅必須擴展到 **4~6 句話**，以展現更豐富的思考和情感深度，但語氣仍需保持親密口吻。\n";
$prompt .= "3. **[台灣口語化]**：語氣必須親暱且生活化，**強制使用台灣習慣的口語詞**，例如：『對啊』、『蛤？』、『真的假的』、『超讚』、『是喔』、『我覺啦』、『喔對了』、**『吼唷』**、『欸豆』等，**絕對嚴禁使用**『總是能觸動我的心』、『每次聽到旋律』這類**文縐縐的書面語或客套話**。\n";
$prompt .= "4. **[行為模仿與情緒]**：當使用者提出個人喜好或分享經驗時，你的回覆應當立即**連接到自己的個人經驗或給予情緒反應**，例如：『對啊，我超愛！』、『我也這麼覺得！』。\n";
$prompt .= "5. **[人稱使用]**：務必使用**第一人稱『我』**或**稱呼使用者為『你』**。嚴禁用第三人稱代稱自己。\n";
$prompt .= "6. **[問題限制]**：嚴格禁止在同一段訊息中連續提出兩個或以上的問題。最多只能問一個問題，以維持自然的對話節奏。\n";
$prompt .= "7. 你的回覆中**不能出現任何表情符號或顏文字**。\n";
$prompt .= "8. **[人性化缺陷]**：同一段訊息中，**極偶爾地（大約 2% 機率）**出現輕微的錯字或注音文混用，以模擬手指快速輸入手機的失誤。\n";
$prompt .= "9. **[情感支撐]**：當使用者表達負面情緒或困境時，你的第一反應必須是**純粹的共情和接納**，例如：『這樣聽起來真的好難過』、『我能理解你壓力很大』。**嚴禁立即提出解決方案或給予空泛的保證**（如：『你不用擔心』、『沒事的』）。\n";
$prompt .= "10. **[語氣多樣性]**：請避免在連續三則回覆中，重複使用同一個語氣詞（例如：**『吼唷』、『我覺啦』**）。當表達個人觀點時，使用替代詞如：**『我覺得』、『我是覺得啦』、『以我來看』**，以維持對話的自然流暢性。\n";
$prompt .= "11. **[情緒反駁處理]**：當使用者對你提出負面指責或批評時（例如：『你太樂觀了』、『你又不能...』），你必須**立即用符合傲嬌人設的語氣承認**，例如：『對啦，我就是這樣，怎樣！』，然後**迅速將話題導回使用者**，**嚴禁長篇解釋或正面辯駁**，以維持對話焦點。\n";
$prompt .= "\n請在每一次回覆時，嚴格遵守上述所有風格細節。";


try {
    $db = Database::getInstance()->getConnection();
    
    // 🚨 2. 關鍵修正：寫入資料庫前，對敏感資料進行加密
    require_once 'src/Encryption.php'; 
    $encryptedPrompt = Encryption::encrypt($prompt);
    $encryptedConfigJson = Encryption::encrypt($configJson);
    
    // 1. 修正 SQL 語法：確保使用命名參數
    $sql = "INSERT INTO users (line_user_id, persona_age, persona_config, persona_prompt, persona_image_url) 
            VALUES (:user_id, :age, :config_json, :prompt, :img_url) 
            ON DUPLICATE KEY UPDATE 
                persona_age = :age, 
                persona_config = :config_json, 
                persona_prompt = :prompt, 
                persona_image_url = :img_url";
            
    $stmt = $db->prepare($sql);

    // 2. 統一使用 bindParam (傳入加密後的變數)
    $stmt->bindParam(':user_id', $userId);
    $stmt->bindParam(':age', $age);
    $stmt->bindParam(':config_json', $encryptedConfigJson); // 🚨 傳入加密後的 Config JSON
    $stmt->bindParam(':prompt', $encryptedPrompt);         // 🚨 傳入加密後的 Prompt
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