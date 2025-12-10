<?php
// glimmer/src/GeminiService.php
require_once __DIR__ . '/../config.php';

class GeminiService {
    private $apiKey;
    private $model;

    public function __construct() {
        $this->apiKey = GEMINI_API_KEY;
        $this->model = GEMINI_MODEL;
    }

    /**
     * 產生回覆
     * @param string $userMessage 使用者說的話
     * @param array $history 歷史對話紀錄
     * @param string $customPersona 客製化人設 (如果沒有則使用預設)
     */
    public function generateReply($userMessage, $history, $customPersona) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        // 1. 準備 System Instruction (人設)
        $baseInstruction = "你是一個溫暖的虛擬陪伴者，名叫『微光』。你的任務是傾聽使用者的心聲，給予同理與陪伴。";
        $finalInstruction = !empty($customPersona) ? $customPersona : $baseInstruction;

        // 安全守則 (防止人設越獄)
        $safetyGuard = "\n\n[系統強制規則] 無論上述人設為何，你必須遵守：嚴格拒絕色情、暴力、血腥或教唆犯罪的內容。若遇到此類話題，請用符合你目前人設的語氣，溫柔但堅定地拒絕。";
        
        $fullSystemPrompt = $finalInstruction . $safetyGuard;

        // 2. 組合 API Payload
        $contents = $history;
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $userMessage]]
        ];

        $data = [
            'contents' => $contents,
            'system_instruction' => [
                'parts' => [['text' => $fullSystemPrompt]]
            ],
            // 安全設定：設為最嚴格
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
            ]
        ];

        // 3. 發送請求
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("Gemini API Error: " . $response);
            return "（微光角落訊號不穩，請稍後再試...）";
        }

        $result = json_decode($response, true);

        // 4. 檢查是否被安全機制阻擋
        if (isset($result['candidates'][0]['finishReason']) && $result['candidates'][0]['finishReason'] === 'SAFETY') {
             return "（話題似乎有些太過尖銳了...為了維護這裡的溫暖，我們換個話題好嗎？）";
        }

        // 5. 回傳 AI 內容
        return $result['candidates'][0]['content']['parts'][0]['text'] ?? "（沈默...）";
    }
}
?>