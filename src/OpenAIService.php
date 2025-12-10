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
            'max_tokens' => 500, // 限制回應長度，確保速度
            'temperature' => 0.7,
        ];

        // ============================================================
        // 🚀【關鍵修改】改用 file_get_contents (跟測試檔一樣)
        // ============================================================
        
        error_log("【OpenAI Stream】準備發送 (使用 file_get_contents)...");

        $options = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                             "Authorization: Bearer " . $this->apiKey . "\r\n",
                'content' => json_encode($payload),
                'timeout' => 30, // 總等待時間
                'ignore_errors' => true,
                // 🚨 新增：強制每一次都是新連線，解決 Socket 衝突
                'protocol_version' => 1.1, 
                'max_redirects' => 0,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ];

        $context  = stream_context_create($options);
        
        // 執行請求
        $result = @file_get_contents($url, false, $context);

        // 檢查 HTTP Header (確認是否 200 OK)
        if (isset($http_response_header)) {
            // 取出第一行狀態碼，例如 "HTTP/1.1 200 OK"
            $statusLine = $http_response_header[0];
            error_log("【OpenAI Status】" . $statusLine);
        }

        if ($result === FALSE) {
            $error = error_get_last();
            error_log("【OpenAI Fail】連線失敗: " . ($error['message'] ?? '未知錯誤'));
            return "連線失敗，請稍後再試。";
        }

        // 3. 解析結果
        $data = json_decode($result, true);
        
        // 檢查 OpenAI 回傳的錯誤
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
            error_log("【OpenAI Fail】回應解析失敗");
            return "AI 思考中斷";
        }
    }

    /**
     * 新增：專門用於生成長時記憶摘要的函式
     */
    public function generateSummary($prompt) {
        if (empty($this->apiKey)) return null;

        $url = "https://api.openai.com/v1/chat/completions";

        $messages = [
            // 讓 AI 知道它的任務是精簡地總結長篇內容
            ['role' => 'system', 'content' => "You are an expert summarizer. Your task is to extract core user information, interests, and relationship dynamics from the given conversation and output a concise, single-paragraph Chinese summary. You must strictly follow all length and content instructions provided in the user prompt."],
            
            // 傳入需要摘要的內容
            ['role' => 'user', 'content' => $prompt]
        ];

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => 800, // 摘要需要較長的輸出空間
            'temperature' => 0.2, // 確保摘要內容是事實且準確的（低溫度）
        ];

        // 🚨 沿用 file_get_contents 連線邏輯
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 摘要可能需要更長的時間

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        
        // 錯誤處理
        if (isset($data['error'])) {
            throw new Exception("OpenAI Summarize Error: " . ($data['error']['message'] ?? '未知錯誤'));
        }

        return $data['choices'][0]['message']['content'] ?? null;
    }
}
?>