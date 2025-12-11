<?php
// glimmer/src/GeminiService.php

class GeminiService {
    private $apiKey;
    private $model;

    public function __construct() {
        $this->apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        $this->model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash';
    }

    /**
     * 核心函式：生成對話回覆 (用於 Runner.php)
     * @param string $userMsg 當前使用者訊息
     * @param array $history 對話歷史 (user/model 角色)
     * @param string $persona System Prompt + LTM 摘要 (整合後的指令)
     * @param float $temperature 溫度，預設用於一般對話
     */
    public function generateReply($userMsg, $history = [], $persona = null, $temperature = 0.7) {
        if (empty($this->apiKey)) return "系統設定錯誤：無 API Key";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        // 1. 基礎 Payload 準備
        $payload = [
            'contents' => [],
            'generationConfig' => [
                'temperature' => $temperature, 
                'maxOutputTokens' => 800
            ]
        ];
        
        // 2. 🚨 關鍵修正：將 System Prompt 作為 contents 的第一則 User 訊息 (避免 system_instruction 錯誤)
        if ($persona) {
            // 將整個 Persona Prompt 視為對話的開始指令 (角色：user)
            $payload['contents'][] = [
                'role' => 'user', 
                'parts' => [['text' => $persona]]
            ];
            // 緊接著一個 model 的起始點，讓 AI 知道這是 System Prompt，下一則該它回覆
            $payload['contents'][] = [
                'role' => 'model', 
                'parts' => [['text' => '好的，我會嚴格遵守你的角色設定。']] 
            ];
        }

        // 3. 處理歷史紀錄 (contents)
        if (!empty($history) && is_array($history)) {
            foreach ($history as $chat) {
                if (!isset($chat['role']) || !isset($chat['message'])) continue;
                
                // 修正角色名：確保是 'user' 或 'model'
                $role = ($chat['role'] === 'user') ? 'user' : 'model'; 

                $payload['contents'][] = [
                    'role' => $role,
                    'parts' => [['text' => $chat['message']]]
                ];
            }
        }

        // 4. 加入當前使用者訊息
        $payload['contents'][] = [
            'role' => 'user',
            'parts' => [['text' => $userMsg]]
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // 5. 標準 cURL 連線
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log("Gemini Error: " . curl_error($ch));
            return "連線失敗，請稍後再試。";
        }
        curl_close($ch);

        // 6. 解析結果
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            error_log("Gemini API Error: " . json_encode($data['error']));
            return "AI 回應錯誤: " . ($data['error']['message'] ?? '未知');
        }

        // 檢查內容是否被阻擋或為空
        if (isset($data['promptFeedback']['blockReason']) || !isset($data['candidates'][0]['content'])) {
            $reason = $data['promptFeedback']['blockReason'] ?? 'Unknown';
            error_log("Gemini API Blocked! Reason: " . $reason . " Full Response: " . json_encode($data));
            return "AI 思考中斷或內容被阻止。原因: " . $reason;
        }

        return $data['candidates'][0]['content']['parts'][0]['text'];
    }

    /**
     * 專用於生成長時記憶摘要的函式 (用於 summarizer.php)
     */
    public function generateSummary($prompt) {
        // LTM 摘要也是對話，直接使用 generateReply 函式來實現
        $systemPrompt = "You are an expert summarizer. Your task is to extract core user information, interests, and relationship dynamics from the given conversation and output a concise, single-paragraph Chinese summary. You must strictly follow all length and content instructions provided in the user prompt.";
        
        // 🚨 摘要需要低溫度 (0.2) 確保事實準確性
        // 這裡我們只傳入 user 訊息 (即摘要請求) 和 system prompt
        return $this->generateReply($prompt, [], $systemPrompt, 0.2); 
    }
}
?>