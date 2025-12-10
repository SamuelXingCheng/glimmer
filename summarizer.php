<?php
// glimmer/summarizer.php

// 允許長時間執行 (避免因大量對話紀錄而超時)
set_time_limit(300); 
ignore_user_abort(true); 

require_once 'config.php';
require_once 'src/Database.php';
require_once 'src/OpenAIService.php';

$db = Database::getInstance()->getConnection();
$aiService = new OpenAIService();

// 1. 取得所有活躍的 Line User IDs
$stmt = $db->query("SELECT DISTINCT line_user_id FROM chat_logs WHERE status = 'completed'");
$userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$targetDate = date('Y-m-d H:i:s', strtotime('-7 days')); // 目標：處理 7 天前的對話

foreach ($userIds as $userId) {
    // 2. 找出需要被摘要的對話紀錄 (例如：過去 30 天內，且早於 7 天前的紀錄)
    // 這裡的邏輯可以很複雜，為了簡化，我們只撈取過去 30 天內且未被處理的對話
    
    // 找出該用戶最後一次摘要的時間點
    $lastSummaryStmt = $db->prepare("SELECT created_at FROM user_ltm_summaries WHERE line_user_id = ? ORDER BY created_at DESC LIMIT 1");
    $lastSummaryStmt->execute([$userId]);
    $lastSummaryTime = $lastSummaryStmt->fetchColumn() ?: date('Y-m-d H:i:s', strtotime('-30 days'));

    // 撈取上次摘要時間之後的所有對話
    $chatLogsStmt = $db->prepare("SELECT role, message FROM chat_logs 
                                  WHERE line_user_id = ? AND created_at > ? AND status = 'completed'
                                  ORDER BY created_at ASC");
    $chatLogsStmt->execute([$userId, $lastSummaryTime]);
    $logs = $chatLogsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($logs) < 50) { // 如果新紀錄不足 50 則，跳過，避免頻繁呼叫 AI
        continue; 
    }

    // 3. 組裝供 AI 摘要的對話文本
    $conversation = "";
    foreach ($logs as $log) {
        $conversation .= "{$log['role']}: {$log['message']}\n";
    }

    // 4. AI 摘要提示詞
    $summarizePrompt = "請根據以下從 {$lastSummaryTime} 到現在的對話紀錄，為用戶生成一個客製化的長時記憶摘要。摘要內容必須包含用戶的興趣、習慣、重要的人生事件、以及任何特殊的稱呼或關係定義。摘要必須精簡，不超過 500 個中文字：\n\n--- 對話紀錄 ---\n{$conversation}";

    try {
        // 5. 呼叫 OpenAI 進行摘要
        // 注意：這裡需要使用一個新的函式或邏輯來呼叫 OpenAI，因為它不是一般的對話
        $aiSummary = $aiService->generateSummary($summarizePrompt); // 假設 OpenAIService 內建了 generateSummary 函式

        // 6. 儲存新的摘要到 LTM 表
        if (!empty($aiSummary)) {
            $insertStmt = $db->prepare("INSERT INTO user_ltm_summaries (line_user_id, summary_text) VALUES (?, ?)");
            $insertStmt->execute([$userId, $aiSummary]);
            error_log("【LTM Success】用戶 $userId 摘要已更新。");
        }

    } catch (Exception $e) {
        error_log("【LTM Error】用戶 $userId 摘要失敗: " . $e->getMessage());
    }
}
echo "Summarization run completed.";
?>