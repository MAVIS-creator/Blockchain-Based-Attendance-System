<?php

require_once __DIR__ . '/../admin/runtime_storage.php';

class AiAnnouncementService
{
    public static function targetedFile()
    {
        return admin_storage_migrate_file('announcement_targets.json');
    }

    public static function pushTargeted($fingerprint, $message, $severity = 'info', array $meta = [])
    {
        $fingerprint = trim((string)$fingerprint);
        $message = trim((string)$message);
        $severity = strtolower(trim((string)$severity));
        if (!in_array($severity, ['info', 'warning', 'urgent'], true)) {
            $severity = 'info';
        }
        if ($fingerprint === '' || $message === '') {
            return false;
        }

        $file = self::targetedFile();
        if (!file_exists($file)) {
            file_put_contents($file, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        }

        $entry = [
            'id' => 'ai_' . date('YmdHis') . '_' . substr(md5($fingerprint . $message), 0, 8),
            'enabled' => true,
            'severity' => $severity,
            'message' => $message,
            'target_fingerprint' => $fingerprint,
            'auto_generated_by' => 'system_ai_operator',
            'updated_at' => date('c'),
        ];

        foreach ($meta as $k => $v) {
            $entry[$k] = $v;
        }

        $fp = fopen($file, 'c+');
        if (!$fp) return false;
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return false;
        }

        rewind($fp);
        $raw = stream_get_contents($fp);
        $rows = json_decode($raw ?: '[]', true);
        if (!is_array($rows)) $rows = [];

        array_unshift($rows, $entry);
        if (count($rows) > 500) {
            $rows = array_slice($rows, 0, 500);
        }

        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return $entry;
    }

    public static function latestForFingerprint($fingerprint)
    {
        $fingerprint = trim((string)$fingerprint);
        if ($fingerprint === '') {
            return null;
        }

        $file = self::targetedFile();
        if (!file_exists($file)) {
            return null;
        }

        $rows = json_decode((string)@file_get_contents($file), true);
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            if (empty($row['enabled'])) continue;
            if ((string)($row['target_fingerprint'] ?? '') !== $fingerprint) continue;
            return $row;
        }

        return null;
    }
}
