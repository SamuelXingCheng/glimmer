<?php
// src/OpenAIService.php

class OpenAIService {
    private $apiKey;
    private $model;

    public function __construct() {
        $this->apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
        $this->model = defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o-mini';
        
        if (empty($this->apiKey)) {
            error_log("【OpenAI Critical】尚未設定 API Key！");
        } else {
            error_log("【OpenAI Init】Key 載入成功 (" . $this->model . ")");
        }
    }

    /**
     * 核心函式：生成對話回覆
     * 🚨 修正：統一使用 cURL 連線
     */
    public function generateReply($userMsg, $history = [], $persona = null) {
        if (empty($this->apiKey)) return "系統錯誤：無 API Key";

        $url = "https://api.openai.com/v1/chat/completions";

        // 1. 準備訊息 (Messages)
        $messages = [];
        if ($persona) {
            $messages[] = ['role' => 'system', 'content' => $persona];
        }

        if (!empty($history) && is_array($history)) {
            foreach ($history as $chat) {
                if (!isset($chat['role']) || !isset($chat['message'])) continue;
                // 資料庫的 model 對應 OpenAI 的 assistant
                $role = ($chat['role'] === 'model') ? 'assistant' : 'user';
                $messages[] = ['role' => $role, 'content' => $chat['message']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $userMsg];

        // 2. 準備 Payload
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 500, 
            'temperature' => 0.7,
        ];
        
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // 3. 🚨 統一使用 cURL 連線 (解決 Socket 和 400 錯誤)
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        // 優化連線參數
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true); // 強制新連線

        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            error_log("【OpenAI Fail】連線失敗: " . curl_error($ch));
            return "連線失敗，請稍後再試。";
        }
        curl_close($ch);

        // 4. 解析結果
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            $errMsg = $data['error']['message'] ?? '未知錯誤';
            error_log("【OpenAI API Error】" . $errMsg);
            return "OpenAI 錯誤: " . $errMsg;
        }

        $reply = $data['choices'][0]['message']['content'] ?? null;
        
        if ($reply) {
            error_log("【OpenAI Success】成功取得回應 (長度: " . mb_strlen($reply) . ")");
            return $reply;
        } else {
            error_log("【OpenAI Fail】回應解析失敗或內容為空");
            return "AI 思考中斷";
        }
    }

    /**
     * 專用於長時記憶摘要的函式 (已確認使用 cURL)
     */
    public function generateSummary($prompt) {
        if (empty($this->apiKey)) return null;

        $url = "https://api.openai.com/v1/chat/completions";

        $messages = [
            ['role' => 'system', 'content' => "You are an expert summarizer. Your task is to extract core user information, interests, and relationship dynamics from the given conversation and output a concise, single-paragraph Chinese summary. You must strictly follow all length and content instructions provided in the user prompt."],
            ['role' => 'user', 'content' => $prompt]
        ];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 800, 
            'temperature' => 0.2, 
        ];

        // 🚨 這裡使用 cURL，連線邏輯與 generateReply 相同，確保穩定性。
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); 

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            throw new Exception("OpenAI Summarize Error: " . ($data['error']['message'] ?? '未知錯誤'));
        }

        return $data['choices'][0]['message']['content'] ?? null;
    }
}
?>