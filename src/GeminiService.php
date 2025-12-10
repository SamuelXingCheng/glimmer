<?php
// src/GeminiService.php

class GeminiService {
    private $apiKey;
    private $model;

    public function __construct() {
        $this->apiKey = defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '';
        $this->model = defined('GEMINI_MODEL') ? GEMINI_MODEL : 'gemini-1.5-flash';
    }

    public function generateReply($userMsg, $history = [], $persona = null) {
        if (empty($this->apiKey)) return "系統設定錯誤：無 API Key";

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";

        // Payload 準備
        $payload = [
            'contents' => [],
            'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 800]
        ];

        if ($persona) {
            $payload['systemInstruction'] = ['parts' => [['text' => $persona]]];
        }

        if (!empty($history) && is_array($history)) {
            foreach ($history as $chat) {
                if (!isset($chat['role']) || !isset($chat['message'])) continue;
                $payload['contents'][] = [
                    'role' => $chat['role'],
                    'parts' => [['text' => $chat['message']]]
                ];
            }
        }

        $payload['contents'][] = [
            'role' => 'user',
            'parts' => [['text' => $userMsg]]
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // ==========================================
        // 標準 cURL 連線 (最穩定)
        // ==========================================
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // 基本逾時設定 (防止卡死太久)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log("Gemini Error: " . curl_error($ch));
            return "連線失敗，請稍後再試。";
        }
        curl_close($ch);

        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            return "AI 回應錯誤: " . ($data['error']['message'] ?? '未知');
        }

        return $data['candidates'][0]['content']['parts'][0]['text'] ?? "AI 思考中斷";
    }
}
?>