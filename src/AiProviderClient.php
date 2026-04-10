<?php

require_once __DIR__ . '/../env_helpers.php';

class AiProviderClient
{
  private static $metricsLoaded = false;
  private static $metrics = [];

  public static function providerMode()
  {
    $mode = strtolower(trim((string)app_env_value('AI_AUTOMATION_PROVIDER', 'rules')));
    return in_array($mode, ['rules', 'groq', 'openrouter', 'gemini', 'auto'], true) ? $mode : 'rules';
  }

  public static function defaultGroqModel()
  {
    return trim((string)app_env_value('GROQ_MODEL', 'llama-3.1-8b-instant'));
  }

  public static function defaultOpenRouterModel()
  {
    return trim((string)app_env_value('AI_OPENROUTER_MODEL', 'openrouter/auto'));
  }

  public static function defaultGeminiModel()
  {
    return trim((string)app_env_value('AI_GEMINI_MODEL', 'gemini-2.0-flash'));
  }

  public static function suggestTicketResolution(array $ticket, array $diagnosis)
  {
    $prompt = self::buildPrompt($ticket, $diagnosis, 'admin');
    $systemPrompt = 'You are an attendance support operations assistant. Respond with concise admin action guidance in plain text. Keep it practical and niche-specific to attendance ops.';
    $res = self::queryWithFallback($prompt, $systemPrompt, $ticket, 'ticket_resolution');
    if (!empty($res['ok'])) {
      return $res;
    }

    return self::ruleBasedSuggestion($ticket, $diagnosis);
  }

  public static function suggestFingerprintResponse(array $ticket, array $diagnosis)
  {
    $prompt = self::buildPrompt($ticket, $diagnosis, 'student_fingerprint_message');
    $systemPrompt = 'You are Attendance AI. Generate one short direct student-facing message tailored to this specific fingerprint context. Be warm, specific, and avoid generic wording. Plain text only.';
    $res = self::queryWithFallback($prompt, $systemPrompt, $ticket, 'fingerprint_response');
    if (!empty($res['ok'])) {
      return $res;
    }

    return self::ruleBasedFingerprintResponse($ticket, $diagnosis);
  }

  public static function suggestAdminChatReply($adminMessage, array $context = [])
  {
    $ticket = [
      'message' => (string)$adminMessage,
      'fingerprint' => (string)($context['fingerprint'] ?? ''),
      'timestamp' => date('c'),
      'matric' => (string)($context['matric'] ?? 'unknown'),
      'course' => (string)($context['course'] ?? 'General'),
      'requested_action' => (string)($context['requested_action'] ?? ''),
    ];
    $diagnosis = [
      'classification' => (string)($context['classification'] ?? 'manual_review_required'),
      'confidence' => (float)($context['confidence'] ?? 0.5),
      'fpMatch' => !empty($context['fpMatch']),
      'ipMatch' => !empty($context['ipMatch']),
      'revoked' => !empty($context['revoked']),
      'checkinCount' => (int)($context['checkinCount'] ?? 0),
      'checkoutCount' => (int)($context['checkoutCount'] ?? 0),
    ];

    $prompt = "Admin chat message: " . trim((string)$adminMessage) . "\n"
      . "Context: pending_review_count=" . (int)($context['pending_review_count'] ?? 0) . ", provider_mode=" . self::providerMode() . ".\n"
      . self::buildPrompt($ticket, $diagnosis, 'admin_chat');

    $systemPrompt = 'You are the internal Attendance AI assistant for admins. Reply like a real AI assistant but focused on this platform operations. Keep response under 4 short sentences, plain text only.';
    $res = self::queryWithFallback($prompt, $systemPrompt, $ticket, 'admin_chat_reply');
    if (!empty($res['ok'])) {
      return $res;
    }

    return self::ruleBasedAdminChatResponse($adminMessage, $context);
  }

  private static function metricsFile()
  {
    return __DIR__ . '/../storage/admin/ai_provider_metrics.json';
  }

  private static function loadMetrics()
  {
    if (self::$metricsLoaded) {
      return;
    }
    self::$metricsLoaded = true;

    $file = self::metricsFile();
    if (!file_exists($file)) {
      self::$metrics = [];
      return;
    }

    $raw = @file_get_contents($file);
    $decoded = json_decode((string)$raw, true);
    self::$metrics = is_array($decoded) ? $decoded : [];
  }

  private static function saveMetrics()
  {
    $file = self::metricsFile();
    @file_put_contents($file, json_encode(self::$metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  private static function recordLatency($provider, $latencyMs, $ok)
  {
    $provider = strtolower(trim((string)$provider));
    if ($provider === '') {
      return;
    }

    self::loadMetrics();
    if (!isset(self::$metrics[$provider]) || !is_array(self::$metrics[$provider])) {
      self::$metrics[$provider] = [
        'avg_ms' => 0,
        'samples' => 0,
        'success' => 0,
        'failure' => 0,
        'last_ms' => 0,
        'updated_at' => null,
      ];
    }

    $entry = self::$metrics[$provider];
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

    self::$metrics[$provider] = $entry;
    self::saveMetrics();
  }

  private static function providerOrder($mode)
  {
    if ($mode === 'groq') {
      return ['groq', 'openrouter', 'gemini'];
    }
    if ($mode === 'openrouter') {
      return ['openrouter', 'groq', 'gemini'];
    }
    if ($mode === 'gemini') {
      return ['gemini', 'groq', 'openrouter'];
    }
    if ($mode === 'rules') {
      return ['rules'];
    }

    return ['groq', 'openrouter', 'gemini'];
  }

  private static function queryWithFallback($prompt, $systemPrompt, array $ticket, $kind)
  {
    $mode = self::providerMode();
    $order = self::providerOrder($mode);
    foreach ($order as $provider) {
      if ($provider === 'groq') {
        $key = trim((string)app_env_value('GROQ_API_KEY', ''));
        if ($key === '') {
          continue;
        }

        $models = self::groqModels();
        foreach ($models as $m) {
          $res = self::requestOpenAiCompatible(
            'groq',
            trim((string)app_env_value('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions')),
            $key,
            $m,
            $prompt,
            $systemPrompt
          );
          if (!empty($res['ok'])) {
            return $res;
          }
        }
      } elseif ($provider === 'openrouter') {
        $key = trim((string)app_env_value('AI_OPENROUTER_API_KEY', ''));
        if ($key === '') {
          continue;
        }
        $res = self::requestOpenAiCompatible(
          'openrouter',
          trim((string)app_env_value('AI_OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions')),
          $key,
          self::defaultOpenRouterModel(),
          $prompt,
          $systemPrompt,
          [
            'HTTP-Referer: ' . (trim((string)app_env_value('APP_URL', 'http://localhost')) ?: 'http://localhost'),
            'X-Title: Attendance Automation'
          ]
        );
        if (!empty($res['ok'])) {
          return $res;
        }
      } elseif ($provider === 'gemini') {
        $key = trim((string)app_env_value('AI_GEMINI_API_KEY', ''));
        if ($key === '') {
          continue;
        }
        $res = self::requestGemini($key, self::defaultGeminiModel(), $prompt, $systemPrompt);
        if (!empty($res['ok'])) {
          return $res;
        }
      }
    }

    return ['ok' => false, 'provider' => 'none', 'model' => 'none', 'latency_ms' => 0, 'error' => 'no_provider_available:' . $kind];
  }

  private static function groqModels()
  {
    $model = self::defaultGroqModel();
    $fastModelsRaw = trim((string)app_env_value('GROQ_FAST_MODELS', 'llama-3.1-8b-instant,llama-3.3-70b-versatile'));
    $fastModels = array_values(array_filter(array_map('trim', explode(',', $fastModelsRaw)), function ($v) {
      return $v !== '';
    }));
    if (empty($fastModels)) {
      $fastModels = [$model];
    }
    if (!in_array($model, $fastModels, true)) {
      array_unshift($fastModels, $model);
    }
    return $fastModels;
  }

  private static function requestOpenAiCompatible($provider, $url, $apiKey, $model, $prompt, $systemPrompt, array $extraHeaders = [])
  {
    $timeout = (int)app_env_value('AI_AUTOMATION_TIMEOUT_SECONDS', '8');
    if ($timeout < 3) {
      $timeout = 3;
    }

    $payload = [
      'model' => $model,
      'temperature' => 0.35,
      'max_tokens' => 220,
      'messages' => [
        [
          'role' => 'system',
          'content' => $systemPrompt
        ],
        [
          'role' => 'user',
          'content' => $prompt
        ]
      ]
    ];

    $start = microtime(true);
    $raw = '';
    $status = 0;
    $err = '';

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge([
          'Content-Type: application/json',
          'Authorization: Bearer ' . $apiKey,
        ], $extraHeaders),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
      ]);
      $raw = (string)curl_exec($ch);
      $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      $err = (string)curl_error($ch);
      curl_close($ch);
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$apiKey}\r\n" . (empty($extraHeaders) ? '' : implode("\r\n", $extraHeaders) . "\r\n"),
          'content' => (string)json_encode($payload),
          'timeout' => $timeout,
          'ignore_errors' => true,
        ]
      ]);
      $raw = (string)@file_get_contents($url, false, $context);
      foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', (string)$line, $m)) {
          $status = (int)$m[1];
          break;
        }
      }
      if ($raw === '') {
        $err = 'HTTP request failed';
      }
    }

    $latencyMs = (int)round((microtime(true) - $start) * 1000);
    self::recordLatency($provider, $latencyMs, ($err === '' && $status >= 200 && $status < 300));
    if ($err !== '' || $status < 200 || $status >= 300) {
      return [
        'ok' => false,
        'provider' => $provider,
        'model' => $model,
        'latency_ms' => $latencyMs,
        'error' => $err !== '' ? $err : ('HTTP ' . $status),
      ];
    }

    $decoded = json_decode($raw, true);
    $content = trim((string)($decoded['choices'][0]['message']['content'] ?? ''));
    if ($content === '') {
      return [
        'ok' => false,
        'provider' => $provider,
        'model' => $model,
        'latency_ms' => $latencyMs,
        'error' => 'Empty response',
      ];
    }

    return [
      'ok' => true,
      'provider' => $provider,
      'model' => $model,
      'latency_ms' => $latencyMs,
      'suggestion' => $content,
    ];
  }

  private static function requestGemini($apiKey, $model, $prompt, $systemPrompt)
  {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($apiKey);
    $timeout = (int)app_env_value('AI_AUTOMATION_TIMEOUT_SECONDS', '8');
    if ($timeout < 3) {
      $timeout = 3;
    }

    $payload = [
      'contents' => [
        [
          'parts' => [
            ['text' => $systemPrompt . "\n\n" . $prompt]
          ]
        ]
      ],
      'generationConfig' => [
        'temperature' => 0.35,
        'maxOutputTokens' => 220,
      ],
    ];

    $start = microtime(true);
    $raw = '';
    $status = 0;
    $err = '';

    if (function_exists('curl_init')) {
      $ch = curl_init($url);
      curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
          'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => $timeout,
      ]);
      $raw = (string)curl_exec($ch);
      $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
      $err = (string)curl_error($ch);
      curl_close($ch);
    } else {
      $context = stream_context_create([
        'http' => [
          'method' => 'POST',
          'header' => "Content-Type: application/json\r\n",
          'content' => (string)json_encode($payload),
          'timeout' => $timeout,
          'ignore_errors' => true,
        ]
      ]);
      $raw = (string)@file_get_contents($url, false, $context);
      foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#HTTP/\S+\s+(\d{3})#', (string)$line, $m)) {
          $status = (int)$m[1];
          break;
        }
      }
      if ($raw === '') {
        $err = 'HTTP request failed';
      }
    }

    $latencyMs = (int)round((microtime(true) - $start) * 1000);
    self::recordLatency('gemini', $latencyMs, ($err === '' && $status >= 200 && $status < 300));
    if ($err !== '' || $status < 200 || $status >= 300) {
      return [
        'ok' => false,
        'provider' => 'gemini',
        'model' => $model,
        'latency_ms' => $latencyMs,
        'error' => $err !== '' ? $err : ('HTTP ' . $status),
      ];
    }

    $decoded = json_decode($raw, true);
    $content = trim((string)($decoded['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if ($content === '') {
      return [
        'ok' => false,
        'provider' => 'gemini',
        'model' => $model,
        'latency_ms' => $latencyMs,
        'error' => 'Empty response',
      ];
    }

    return [
      'ok' => true,
      'provider' => 'gemini',
      'model' => $model,
      'latency_ms' => $latencyMs,
      'suggestion' => $content,
    ];
  }

  private static function buildPrompt(array $ticket, array $diagnosis, $audience = 'admin')
  {
    $matric = (string)($ticket['matric'] ?? 'unknown');
    $course = (string)($ticket['course'] ?? 'General');
    $requestedAction = (string)($ticket['requested_action'] ?? '');
    $message = trim((string)($ticket['message'] ?? ''));
    $fingerprint = (string)($ticket['fingerprint'] ?? 'unknown_fp');
    $timestamp = (string)($ticket['timestamp'] ?? date('c'));
    $seed = abs(crc32($fingerprint . '|' . $timestamp . '|' . $audience));
    $styles = ['confident', 'helpful', 'calm', 'practical', 'precise'];
    $style = $styles[$seed % count($styles)];
    $fpShort = substr($fingerprint, 0, 8);

    return sprintf(
      "Audience=%s. Response style=%s. Keep response non-generic and tailored to fingerprint segment %s. Ticket context: matric=%s, course=%s, requested_action=%s, classification=%s, confidence=%.2f, fpMatch=%s, ipMatch=%s, revoked=%s, checkinCount=%d, checkoutCount=%d, student_message=%s",
      $audience,
      $style,
      $fpShort,
      $matric,
      $course,
      $requestedAction !== '' ? $requestedAction : 'unknown',
      (string)($diagnosis['classification'] ?? 'unknown'),
      (float)($diagnosis['confidence'] ?? 0),
      !empty($diagnosis['fpMatch']) ? 'true' : 'false',
      !empty($diagnosis['ipMatch']) ? 'true' : 'false',
      !empty($diagnosis['revoked']) ? 'true' : 'false',
      (int)($diagnosis['checkinCount'] ?? 0),
      (int)($diagnosis['checkoutCount'] ?? 0),
      $message !== '' ? $message : 'none'
    );
  }

  private static function ruleBasedSuggestion(array $ticket, array $diagnosis)
  {
    $course = trim((string)($ticket['course'] ?? 'General'));
    if ($course === '') {
      $course = 'General';
    }

    $classification = (string)($diagnosis['classification'] ?? 'manual_review_required');
    $fingerprint = (string)($ticket['fingerprint'] ?? '');
    $seed = abs(crc32($classification . '|' . $course . '|' . $fingerprint));

    $manualVariants = [
      'Review ticket details and handle manually.',
      'Check current ticket context and resolve with policy-safe manual action.',
      'Manual review recommended with attendance logs and identity checks.'
    ];
    $suggestion = $manualVariants[$seed % count($manualVariants)];

    if ($classification === 'blocked_revoked_device') {
      $choices = [
        'Device is revoked. Keep access blocked and use admin-only re-enable flow if identity is confirmed.',
        'Revoked fingerprint/IP detected. Do not write attendance; escalate to superadmin re-enable checks.',
        'Security block is active for this device. Preserve block and verify ownership before any override.'
      ];
      $suggestion = $choices[$seed % count($choices)];
    } elseif ($classification === 'duplicate_submission_attempt' || $classification === 'duplicate_or_fraudulent_sequence') {
      $choices = [
        'Attendance already recorded for this course today. Reject duplicate write and notify student.',
        'Duplicate sequence detected for this course/day. Keep ledger unchanged and close with guidance.',
        'Existing course attendance found. Avoid extra checkin/checkout writes and return clear status to student.'
      ];
      $suggestion = $choices[$seed % count($choices)];
    } elseif ($classification === 'legitimate_session_issue') {
      $choices = [
        'Valid session signal detected. Auto-add missing attendance for this course only, then resolve ticket.',
        'Looks like a recoverable session issue. Apply course-scoped attendance fix and close ticket.',
        'Session glitch confirmed with fingerprint/IP match. Safe to perform guarded course attendance remediation.'
      ];
      $suggestion = $choices[$seed % count($choices)];
    } elseif ($classification === 'network_ip_rotation') {
      $choices = [
        'Fingerprint match with IP drift. Ask for stable network retry and keep ticket in review queue if repeated.',
        'IP rotated while fingerprint remained stable. Request single-network retry before manual attendance write.',
        'Likely network switch issue. Guide student to retry on same connection and review if recurrence continues.'
      ];
      $suggestion = $choices[$seed % count($choices)];
    } elseif ($classification === 'new_or_suspicious_device') {
      $choices = [
        'New/unverified device. Send targeted verification announcement and require admin confirmation before attendance write.',
        'Fingerprint appears new for this flow. Trigger identity verification and keep in admin review queue.',
        'Unrecognized device context detected. Provide verification instructions and defer attendance update.'
      ];
      $suggestion = $choices[$seed % count($choices)];
    }

    return [
      'ok' => true,
      'provider' => 'rules',
      'model' => 'rules-v1',
      'latency_ms' => 0,
      'suggestion' => $suggestion,
    ];
  }

  private static function ruleBasedFingerprintResponse(array $ticket, array $diagnosis)
  {
    $classification = (string)($diagnosis['classification'] ?? 'manual_review_required');
    $course = trim((string)($ticket['course'] ?? 'General'));
    if ($course === '') {
      $course = 'General';
    }
    $fingerprint = (string)($ticket['fingerprint'] ?? '');
    $seed = abs(crc32($classification . '|' . $course . '|' . $fingerprint));

    $base = 'We are reviewing your request. Please stay on one browser session and retry shortly.';
    if ($classification === 'blocked_revoked_device') {
      $opts = [
        'Your device is currently blocked for security reasons. Please contact admin for verification before retrying attendance.',
        'Security lock is active on this device. Reach admin support to verify your identity and request re-enable.',
      ];
      $base = $opts[$seed % count($opts)];
    } elseif ($classification === 'duplicate_submission_attempt' || $classification === 'duplicate_or_fraudulent_sequence') {
      $opts = [
        "Attendance for {$course} appears already recorded today. No extra action is needed from your side right now.",
        "We found an existing {$course} attendance record for today. Please refresh and continue normally.",
      ];
      $base = $opts[$seed % count($opts)];
    } elseif ($classification === 'legitimate_session_issue') {
      $opts = [
        "We detected a session issue and are fixing your {$course} attendance request. Refresh in a few seconds.",
        "Your request looks valid and we are applying a safe {$course} attendance fix now. Please retry shortly.",
      ];
      $base = $opts[$seed % count($opts)];
    } elseif ($classification === 'network_ip_rotation') {
      $opts = [
        "Your network changed during attendance attempt. Keep one connection and retry for {$course}; support is available if it repeats.",
        "We noticed an IP/network switch. Please use a stable network and retry {$course} attendance once.",
      ];
      $base = $opts[$seed % count($opts)];
    } elseif ($classification === 'new_or_suspicious_device') {
      $opts = [
        "This appears to be a new device for your profile. Please complete verification with admin before retrying {$course} attendance.",
        "We need a quick identity check because this device is unfamiliar. Contact admin support to continue {$course} attendance safely.",
      ];
      $base = $opts[$seed % count($opts)];
    }

    return [
      'ok' => true,
      'provider' => 'rules',
      'model' => 'rules-fingerprint-v1',
      'latency_ms' => 0,
      'suggestion' => $base,
    ];
  }

  private static function ruleBasedAdminChatResponse($adminMessage, array $context = [])
  {
    $msg = strtolower(trim((string)$adminMessage));
    $reply = 'I can help. Share matric, course, and failed action (checkin/checkout) and I will suggest the safest next step.';

    if (strpos($msg, 'pending') !== false || strpos($msg, 'review') !== false) {
      $reply = 'Current priority is unresolved high-risk diagnostics first, then IP-rotation cases. I can summarize exact tickets if you want.';
    } elseif (strpos($msg, 'fingerprint') !== false || strpos($msg, 'device') !== false) {
      $reply = 'For fingerprint mismatches, verify identity first. If it is a new device, keep manual review; if known with stable history, apply guarded remediation.';
    } elseif (strpos($msg, 'checkin') !== false || strpos($msg, 'checkout') !== false) {
      $reply = 'I recommend course-scoped validation: no duplicate checkin, no checkout without prior checkin, and no duplicate checkout.';
    }

    return [
      'ok' => true,
      'provider' => 'rules',
      'model' => 'rules-chat-v1',
      'latency_ms' => 0,
      'suggestion' => $reply,
    ];
  }
}
