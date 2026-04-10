<?php

require_once __DIR__ . '/../admin/state_helpers.php';

class AiAdminChatAssistant
{
    public static function postInsight($message, array $meta = [])
    {
        $message = trim((string)$message);
        if ($message === '') {
            return false;
        }

        $chatFile = function_exists('admin_chat_file') ? admin_chat_file() : null;
        if (!$chatFile) {
            return false;
        }

        if (!file_exists($chatFile)) {
            file_put_contents($chatFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }

        $entry = [
            'user' => 'system_ai_operator',
            'name' => 'System AI Operator',
            'time' => date('c'),
            'message' => $message,
            'auto_replied_by' => 'system_ai_operator',
            'context' => 'support_ticket_diagnostics',
        ];

        foreach ($meta as $k => $v) {
            $entry[$k] = $v;
        }

        $fp = fopen($chatFile, 'c+');
        if (!$fp) return false;
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        rewind($fp);
        $raw = stream_get_contents($fp);
        $rows = json_decode($raw ?: '[]', true);
        if (!is_array($rows)) $rows = [];

        $rows[] = $entry;
        if (count($rows) > 1000) {
            $rows = array_slice($rows, -1000);
        }

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return true;
    }
}
