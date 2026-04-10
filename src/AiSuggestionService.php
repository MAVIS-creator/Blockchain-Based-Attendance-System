<?php

require_once __DIR__ . '/../env_helpers.php';
require_once __DIR__ . '/../storage_helpers.php';
require_once __DIR__ . '/../admin/runtime_storage.php';

class AiSuggestionService
{
  private static function env($key, $default = '')
  {
    return (string)app_env_value($key, $default, __DIR__ . '/../.env');
  }

  private static function metricsFile()
  {
    return admin_storage_migrate_file('ai_provider_metrics.json');
  }

  private static function loadMetrics()
  {
    $file = self::metricsFile();
    if (!file_exists($file)) {
      return [];
    }
    $raw = @file_get_contents($file);
    $rows = json_decode((string)$raw, true);
    return is_array($rows) ? $rows : [];
  }

  private static function saveMetrics(array $metrics)
  {
    $file = self::metricsFile();
    @file_put_contents($file, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  private static function recordLatency($provider, $latencyMs, $ok)
  {
    $provider = strtolower(trim((string)$provider));
    if ($provider === '') {
      return;
    }

    $metrics = self::loadMetrics();
    if (!isset($metrics[$provider]) || !is_array($metrics[$provider])) {
      $metrics[$provider] = [
        'avg_ms' => 0,
        'samples' => 0,
        'success' => 0,
        'failure' => 0,
        'last_ms' => 0,
        'updated_at' => null,
      ];
    }

    $entry = $metrics[$provider];
    $samples = (int)($entry['samples'] ?? 0);
    $avg = (float)($entry['avg_ms'] ?? 0.0);
    $latencyMs = max(1, (int)$latencyMs);

    $newSamples = $samples + 1;
    $newAvg = $samples <= 0 ? $latencyMs : (($avg * $samples) + $latencyMs) / $newSamples;

    $entry['avg_ms'] = round($newAvg, 2);
    $entry['samples'] = $newSamples;
    $entry['last_ms'] = $latencyMs;
    if ($ok) {
      $entry['success'] = (int)($entry['success'] ?? 0) + 1;
    } else {
      $entry['failure'] = (int)($entry['failure'] ?? 0) + 1;
    }
    $entry['updated_at'] = date('c');

    $metrics[$provider] = $entry;
    self::saveMetrics($metrics);
  }

  private static function providerAvailability()
  {
    return [
      'groq' => trim(self::env('AI_GROQ_API_KEY', '')) !== '',
      'openrouter' => trim(self::env('AI_OPENROUTER_API_KEY', '')) !== '',
      'gemini' => trim(self::env('AI_GEMINI_API_KEY', '')) !== '',
    ];
  }

  private static function preferredProviderOrder()
  {
    $availability = self::providerAvailability();
    $providers = [];

    $configured = strtolower(trim(self::env('AI_PROVIDER_MODE', 'auto')));
    if (in_array($configured, ['groq', 'openrouter', 'gemini', 'rules'], true)) {
      if ($configured === 'rules') {
        return ['rules'];
      }
      if (!empty($availability[$configured])) {
        $providers[] = $configured;
      }
    }

    foreach (['groq', 'openrouter', 'gemini'] as $provider) {
      if (!empty($availability[$provider]) && !in_array($provider, $providers, true)) {
        $providers[] = $provider;
      }
    }

    if (empty($providers)) {
      return ['rules'];
    }

    $metrics = self::loadMetrics();
    usort($providers, function ($a, $b) use ($metrics) {
      $aAvg = isset($metrics[$a]['avg_ms']) ? (float)$metrics[$a]['avg_ms'] : 999999;
      $bAvg = isset($metrics[$b]['avg_ms']) ? (float)$metrics[$b]['avg_ms'] : 999999;
      if ($aAvg === $bAvg) {
        $priority = ['groq' => 1, 'openrouter' => 2, 'gemini' => 3];
        return ($priority[$a] ?? 99) <=> ($priority[$b] ?? 99);
      }
      return $aAvg <=> $bAvg;
    });

    return $providers;
  }

  private static function requestJson($url, array $headers, array $payload)
  {
    $body = json_encode($payload);
    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 25,
      ]);
      $raw = curl_exec($ch);
      $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      $error = curl_error($ch);
      curl_close($ch);
      return [
        'ok' => $error === '' && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => (string)$raw,
        'error' => (string)$error,
      ];
    }

    $context = stream_context_create([
      'http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 25,
        'ignore_errors' => true,
      ]
    ]);

    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
      if (preg_match('#HTTP/\S+\s+(\d{3})#', $header, $m)) {
        $status = (int)$m[1];
        break;
      }
    }

    return [
      'ok' => $raw !== false && $status >= 200 && $status < 300,
      'status' => $status,
      'body' => (string)$raw,
      'error' => $raw === false ? 'HTTP request failed.' : '',
    ];
  }

  private static function buildPrompt(array $ticket, array $diag)
  {
    $ticketTs = (string)($ticket['timestamp'] ?? '');
    $matric = (string)($ticket['matric'] ?? '');
    $course = (string)($ticket['course'] ?? 'General');
    $requestedAction = (string)($ticket['requested_action'] ?? '');
    $message = trim((string)($ticket['message'] ?? ''));

    return "You are an attendance-support AI assistant for admins. Return strict JSON only with keys: summary, next_action, risk_level, checks, suggested_message_to_student. "
      . "Context: ticket_timestamp={$ticketTs}; matric={$matric}; course={$course}; requested_action={$requestedAction}; "
      . "classification=" . (string)($diag['classification'] ?? '') . "; confidence=" . (string)($diag['confidence'] ?? '') . "; "
      . "fpMatch=" . (!empty($diag['fpMatch']) ? 'true' : 'false') . "; ipMatch=" . (!empty($diag['ipMatch']) ? 'true' : 'false') . "; revoked=" . (!empty($diag['revoked']) ? 'true' : 'false') . ". "
      . "Student message: {$message}. Keep it concise and operational for admin follow-up.";
  }

  private static function normalizeAiJson($text)
  {
    $text = trim((string)$text);
    if ($text === '') {
      return null;
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
      return $decoded;
    }

    if (preg_match('/\{.*\}/s', $text, $m)) {
      $decoded = json_decode($m[0], true);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    return null;
  }

  private static function callGroq($prompt)
  {
    $key = trim(self::env('AI_GROQ_API_KEY', ''));
    if ($key === '') {
      return ['ok' => false, 'error' => 'groq_key_missing'];
    }

    $model = trim(self::env('AI_GROQ_MODEL', 'llama-3.1-8b-instant'));
    $start = microtime(true);
    $res = self::requestJson(
      'https://api.groq.com/openai/v1/chat/completions',
      [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
      ],
      [
        'model' => $model,
        'temperature' => 0.2,
        'messages' => [
          ['role' => 'user', 'content' => $prompt]
        ]
      ]
    );
    $latencyMs = (int)round((microtime(true) - $start) * 1000);
    self::recordLatency('groq', $latencyMs, $res['ok']);

    if (!$res['ok']) {
      return ['ok' => false, 'error' => 'groq_request_failed', 'detail' => trim((string)$res['error'] . ' ' . (string)$res['body'])];
    }

    $decoded = json_decode((string)$res['body'], true);
    $text = (string)($decoded['choices'][0]['message']['content'] ?? '');
    return ['ok' => $text !== '', 'raw' => $text, 'model' => $model, 'latency_ms' => $latencyMs];
  }

  private static function callOpenRouter($prompt)
  {
    $key = trim(self::env('AI_OPENROUTER_API_KEY', ''));
    if ($key === '') {
      return ['ok' => false, 'error' => 'openrouter_key_missing'];
    }

    $url = trim(self::env('AI_OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions'));
    $model = trim(self::env('AI_OPENROUTER_MODEL', 'openrouter/auto'));

    $start = microtime(true);
    $res = self::requestJson(
      $url,
      [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
        'HTTP-Referer: ' . (trim(self::env('APP_URL', 'http://localhost')) ?: 'http://localhost'),
        'X-Title: Attendance Ticket AI',
      ],
      [
        'model' => $model,
        'temperature' => 0.2,
        'messages' => [
          ['role' => 'user', 'content' => $prompt]
        ]
      ]
    );
    $latencyMs = (int)round((microtime(true) - $start) * 1000);
    self::recordLatency('openrouter', $latencyMs, $res['ok']);

    if (!$res['ok']) {
      return ['ok' => false, 'error' => 'openrouter_request_failed', 'detail' => trim((string)$res['error'] . ' ' . (string)$res['body'])];
    }

    $decoded = json_decode((string)$res['body'], true);
    $text = (string)($decoded['choices'][0]['message']['content'] ?? '');
    return ['ok' => $text !== '', 'raw' => $text, 'model' => $model, 'latency_ms' => $latencyMs];
  }

  private static function callGemini($prompt)
  {
    $key = trim(self::env('AI_GEMINI_API_KEY', ''));
    if ($key === '') {
      return ['ok' => false, 'error' => 'gemini_key_missing'];
    }

    $model = trim(self::env('AI_GEMINI_MODEL', 'gemini-2.0-flash'));
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);

    $start = microtime(true);
    $res = self::requestJson(
      $url,
      ['Content-Type: application/json'],
      [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => [
          'temperature' => 0.2,
          'responseMimeType' => 'application/json'
        ]
      ]
    );
    $latencyMs = (int)round((microtime(true) - $start) * 1000);
    self::recordLatency('gemini', $latencyMs, $res['ok']);

    if (!$res['ok']) {
      return ['ok' => false, 'error' => 'gemini_request_failed', 'detail' => trim((string)$res['error'] . ' ' . (string)$res['body'])];
    }

    $decoded = json_decode((string)$res['body'], true);
    $text = '';
    foreach (($decoded['candidates'][0]['content']['parts'] ?? []) as $part) {
      if (isset($part['text'])) {
        $text .= (string)$part['text'];
      }
    }

    return ['ok' => trim($text) !== '', 'raw' => trim($text), 'model' => $model, 'latency_ms' => $latencyMs];
  }

  private static function fallbackRules(array $ticket, array $diag)
  {
    $classification = (string)($diag['classification'] ?? 'manual_review_required');
    $course = (string)($ticket['course'] ?? 'General');

    $nextAction = 'Review ticket and communicate with student.';
    $risk = 'medium';
    if ($classification === 'blocked_revoked_device') {
      $nextAction = 'Do not auto-approve. Verify revoked reason and only superadmin may re-enable device.';
      $risk = 'high';
    } elseif ($classification === 'network_ip_rotation') {
      $nextAction = 'Confirm identity and recent device history, then decide manual check-in/out for ' . $course . '.';
      $risk = 'medium';
    } elseif ($classification === 'new_or_suspicious_device') {
      $nextAction = 'Require device verification before any manual attendance action.';
      $risk = 'high';
    } elseif ($classification === 'legitimate_session_issue') {
      $nextAction = 'Safe to apply course-scoped check-in/out if duplicates do not already exist.';
      $risk = 'low';
    }

    return [
      'summary' => 'Rules-based fallback suggestion generated.',
      'next_action' => $nextAction,
      'risk_level' => $risk,
      'checks' => [
        'Confirm ticket course and requested action',
        'Confirm no duplicate for same date/course/action',
        'Keep fingerprint/IP audit trail'
      ],
      'suggested_message_to_student' => 'We are reviewing your ticket and will update your attendance status shortly.',
    ];
  }

  public static function buildSuggestion(array $ticket, array $diag)
  {
    $providers = self::preferredProviderOrder();
    $prompt = self::buildPrompt($ticket, $diag);

    foreach ($providers as $provider) {
      if ($provider === 'rules') {
        $rules = self::fallbackRules($ticket, $diag);
        return [
          'provider' => 'rules',
          'model' => 'rules-engine',
          'latency_ms' => 0,
          'data' => $rules,
        ];
      }

      $result = null;
      if ($provider === 'groq') {
        $result = self::callGroq($prompt);
      } elseif ($provider === 'openrouter') {
        $result = self::callOpenRouter($prompt);
      } elseif ($provider === 'gemini') {
        $result = self::callGemini($prompt);
      }

      if (!is_array($result) || empty($result['ok'])) {
        continue;
      }

      $parsed = self::normalizeAiJson((string)($result['raw'] ?? ''));
      if (!is_array($parsed)) {
        continue;
      }

      return [
        'provider' => $provider,
        'model' => (string)($result['model'] ?? 'unknown'),
        'latency_ms' => (int)($result['latency_ms'] ?? 0),
        'data' => $parsed,
      ];
    }

    return [
      'provider' => 'rules',
      'model' => 'rules-engine',
      'latency_ms' => 0,
      'data' => self::fallbackRules($ticket, $diag),
    ];
  }
}
