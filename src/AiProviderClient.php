<?php

require_once __DIR__ . '/../env_helpers.php';
require_once __DIR__ . '/AiSiteStructureContext.php';

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
    $site = self::getSiteContextBundle('admin');
    $prompt = self::buildPrompt($ticket, $diagnosis, 'admin', (string)($site['context'] ?? ''));
    $systemPrompt = 'You are an attendance support operations assistant. The rulebook and diagnosis are hard constraints, not optional hints. First obey the supplied classification, action, and policy reason; do not contradict them. Then generate concise admin action guidance in plain text, grounded in the ticket facts and attendance policy. Keep it practical and niche-specific to attendance ops.';
    $res = self::queryWithFallback($prompt, $systemPrompt, $ticket, 'ticket_resolution');
    if (!empty($res['ok'])) {
      return self::applyContextMeta($res, $site);
    }

    return self::applyContextMeta(self::ruleBasedSuggestion($ticket, $diagnosis), $site);
  }

  public static function suggestFingerprintResponse(array $ticket, array $diagnosis)
  {
    $site = self::getSiteContextBundle('student_fingerprint_message');
    $prompt = self::buildPrompt($ticket, $diagnosis, 'student_fingerprint_message', (string)($site['context'] ?? ''));
    $systemPrompt = 'You are Attendance AI. The rulebook and diagnosis are hard constraints. First obey the supplied classification, action, and policy reason; do not contradict them. Then generate one short direct student-facing message tailored to this specific fingerprint context. Be warm, specific, and avoid generic wording. Plain text only.';
    $res = self::queryWithFallback($prompt, $systemPrompt, $ticket, 'fingerprint_response');
    if (!empty($res['ok'])) {
      $safeSuggestion = self::sanitizeStudentFingerprintSuggestion((string)($res['suggestion'] ?? ''), $ticket, $diagnosis);
      if ($safeSuggestion !== '') {
        $res['suggestion'] = $safeSuggestion;
        return self::applyContextMeta($res, $site);
      }
    }

    return self::applyContextMeta(self::ruleBasedFingerprintResponse($ticket, $diagnosis), $site);
  }

  private static function sanitizeStudentFingerprintSuggestion($suggestion, array $ticket, array $diagnosis)
  {
    $suggestion = trim((string)$suggestion);
    if ($suggestion === '') {
      return '';
    }

    $normalized = strtolower($suggestion);
    $adminOnlyPatterns = [
      '/\badmin\s+sidebar\b/i',
      '/\bgo\s+to\s+the\s+dashboard\b/i',
      '/\bdashboard\b.*\bsidebar\b/i',
      '/\bindex\.php\?page=/i',
      '/\bset\s+active\s+course\b/i',
      '/\bfollow\s+these\s+steps\b/i',
      '/\bclick\s+on\b/i',
      '/\bstep\s*\d+\b/i',
    ];

    foreach ($adminOnlyPatterns as $pattern) {
      if (preg_match($pattern, $normalized)) {
        return '';
      }
    }

    // Keep student-facing notices concise and avoid long procedural dumps.
    if (mb_strlen($suggestion) > 320) {
      return '';
    }

    return $suggestion;
  }

  public static function suggestAdminChatReply($adminMessage, array $context = [])
  {
    $adminMessageRaw = trim((string)$adminMessage);
    $adminMessageLower = strtolower($adminMessageRaw);
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

    $site = self::getSiteContextBundle('admin_chat');
    $recentMessages = isset($context['recent_messages']) && is_array($context['recent_messages']) ? $context['recent_messages'] : [];
    $recentMessagesText = '';
    if (!empty($recentMessages)) {
      $lines = [];
      foreach ($recentMessages as $row) {
        if (!is_array($row)) {
          continue;
        }
        $name = trim((string)($row['name'] ?? 'Admin'));
        $message = trim((string)($row['message'] ?? ''));
        if ($message === '') {
          continue;
        }
        $lines[] = '- ' . ($name !== '' ? $name : 'Admin') . ': ' . $message;
      }
      if (!empty($lines)) {
        $recentMessagesText = "Recent chat context:\n" . implode("\n", array_slice($lines, -6)) . "\n";
      }
    }
    $assistantName = trim((string)($context['assistant_name'] ?? 'Sentinel AI'));
    if ($assistantName === '') {
      $assistantName = 'Sentinel AI';
    }

    $prompt = "Admin chat message: " . $adminMessageRaw . "\n"
      . "Context: pending_review_count=" . (int)($context['pending_review_count'] ?? 0) . ", provider_mode=" . self::providerMode() . ".\n"
      . $recentMessagesText
      . self::buildPrompt($ticket, $diagnosis, 'admin_chat', (string)($site['context'] ?? ''));

    $allowHumor = !empty($context['allow_humor']);
    $allowGreetingFiller = (bool)preg_match('/\b(hello|hi|hey|good\s+(morning|afternoon|evening)|how\s+are\s+you|who\s+are\s+you|can\s+you|could\s+you|what\s+can\s+you\s+do)\b/i', $adminMessageRaw);

    $humorRule = $allowHumor
      ? 'Humor mode is enabled: dry, subtle humor is allowed only when relevant. Never joke about users, errors, incidents, or failures.'
      : 'Humor mode is disabled: keep tone neutral and operational.';
    $emojiRule = $allowHumor
      ? 'Emojis are allowed only in admin chat humor replies and only when contextually appropriate. Use at most one subtle emoji.'
      : 'Do not use emojis.';
    $greetingRule = $allowGreetingFiller
      ? 'Because the admin asked in a conversational tone, one brief greeting/filler clause is allowed before the core answer.'
      : 'Skip greetings/filler and answer directly.';

    $systemPrompt = $assistantName
      . ' is Sentinel AI, the internal System Guardian and Operations Assistant for admins. '
      . 'Tone: calm, observant, precise, quietly authoritative. Not chatty. '
      . 'Response format: Observation -> Conclusion -> Action (if needed), in 2-4 short sentences. '
      . 'Confidence policy: high confidence = direct statement; medium confidence = recommend targeted review; low confidence = escalate clearly. '
      . 'If explaining navigation, include a markdown link like [Go to Status](index.php?page=status). Use plain text and markdown links only. '
      . $greetingRule . ' ' . $emojiRule . ' ' . $humorRule;
    $res = self::queryWithFallback($prompt, $systemPrompt, $ticket, 'admin_chat_reply');
    if (!empty($res['ok'])) {
      return self::applyContextMeta($res, $site);
    }

    return self::applyContextMeta(self::ruleBasedAdminChatResponse($adminMessage, $context), $site);
  }

  private static function getSiteContextBundle($audience)
  {
    if (!class_exists('AiSiteStructureContext') || !method_exists('AiSiteStructureContext', 'getBundle')) {
      return ['enabled' => false, 'context' => '', 'meta' => []];
    }
    $bundle = AiSiteStructureContext::getBundle((string)$audience);
    return is_array($bundle) ? $bundle : ['enabled' => false, 'context' => '', 'meta' => []];
  }

  private static function applyContextMeta(array $response, array $bundle)
  {
    $meta = (isset($bundle['meta']) && is_array($bundle['meta'])) ? $bundle['meta'] : [];
    $response['site_context_enabled'] = !empty($bundle['enabled']);
    $response['site_context_version'] = (string)($meta['version'] ?? 'site-context-v1');
    $response['site_context_source_count'] = (int)($meta['source_count'] ?? 0);
    $response['site_context_indexed_pages'] = (int)($meta['indexed_pages'] ?? 0);
    $response['site_context_last_scan_at'] = (string)($meta['last_scan_at'] ?? '');
    return $response;
  }

  public static function suggestAdminNavigationHelp($adminQuery, array $context = [])
  {
    $ticket = [
      'message' => (string)$adminQuery,
      'fingerprint' => 'admin_nav',
      'timestamp' => date('c'),
      'matric' => 'admin',
      'course' => 'Navigation',
      'requested_action' => 'navigating',
    ];

    $prompt = "Admin asked: " . trim((string)$adminQuery) . "\n"
      . "Context: They are looking for a page in the admin dashboard. The available pages are: Dashboard, Status, Logs, Request Timings, Chain, Failed Attempts, Clear / Backup, Clear Tokens, Email Logs, Add Course, Set Active Course, Manual Attendance, Geo-fence, Announcement, Unlink Fingerprint, Patcher, Support Tickets, AI Suggestions, Role Privileges, Action Audit Log, Manage Accounts, System Settings.";

    $systemPrompt = "You are the Attendance Admin Command Palette Navigation Assistant. "
      . "Your only goal is to guide the admin to the exact page they need. "
      . "Give a highly concise 1-2 sentence response. "
      . "Return the answer in plain text. Format your response exactly like this: "
      . "[Your helpful sentence] => [Target Page]. "
      . "Example: 'To enable check-in mode, go to the Status page. => Status'.";

    $res = self::queryWithFallback($prompt, $systemPrompt, $ticket, 'admin_nav_help');

    if (!empty($res['ok']) && trim((string)$res['suggestion']) !== '') {
      return $res;
    }

    return [
      'ok' => true,
      'provider' => 'rules',
      'model' => 'rules-nav-v1',
      'latency_ms' => 0,
      'suggestion' => 'I can help you navigate. Use the Sidebar or type a specific keyword (e.g. Logs, Course, Settings) => Dashboard'
    ];
  }

  public static function interpretRuleText($ruleText)
  {
    $ruleText = trim((string)$ruleText);
    if ($ruleText === '') {
      return ['ok' => false, 'error' => 'empty_rule_text'];
    }

    $ticket = [
      'message' => $ruleText,
      'fingerprint' => 'rulebook_parser',
      'timestamp' => date('c'),
      'matric' => 'admin',
      'course' => 'General',
      'requested_action' => '',
    ];

    $systemPrompt = 'You convert admin attendance-policy statements into strict JSON rules. '
      . 'Return JSON ONLY with this exact schema: '
      . '{"conditions":{...},"outcome":{"classification":"...","action":"...","confidence":0.0,"suggested_admin_action":"...","reason":"..."}}. '
      . 'Allowed condition keys: course_exists, course_is_active, identity_keys_present, device_sharing_risk, revoked, fp_match, ip_match, requested_action, has_checkin, has_checkout. '
      . 'Allowed actions: deny_and_review, manual_review_only, deny_and_notify, auto_fix_add_attendance, guide_and_admin_review, verify_and_admin_review, deny_and_review. '
      . 'If ambiguous, still produce best-effort valid JSON with at least one condition.';

    $prompt = 'Policy statement: ' . $ruleText;
    $res = self::queryWithFallback($prompt, $systemPrompt, $ticket, 'rule_interpretation');
    if (empty($res['ok'])) {
      return ['ok' => false, 'error' => 'no_provider_available'];
    }

    $raw = trim((string)($res['suggestion'] ?? ''));
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
      $jsonCandidate = self::extractJsonObjectFromText($raw);
      if ($jsonCandidate !== '') {
        $decoded = json_decode($jsonCandidate, true);
      }
    }

    if (!is_array($decoded)) {
      return ['ok' => false, 'error' => 'invalid_json_response'];
    }

    $conditions = isset($decoded['conditions']) && is_array($decoded['conditions']) ? $decoded['conditions'] : [];
    $outcome = isset($decoded['outcome']) && is_array($decoded['outcome']) ? $decoded['outcome'] : [];
    if (empty($conditions) || empty($outcome)) {
      return ['ok' => false, 'error' => 'missing_conditions_or_outcome'];
    }

    return [
      'ok' => true,
      'conditions' => $conditions,
      'outcome' => $outcome,
      'provider' => (string)($res['provider'] ?? ''),
      'model' => (string)($res['model'] ?? ''),
      'latency_ms' => (int)($res['latency_ms'] ?? 0),
    ];
  }

  private static function extractJsonObjectFromText($text)
  {
    $text = trim((string)$text);
    if ($text === '') {
      return '';
    }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start === false || $end === false || $end <= $start) {
      return '';
    }
    return substr($text, $start, $end - $start + 1);
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

  private static function buildPrompt(array $ticket, array $diagnosis, $audience = 'admin', $siteContext = '')
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

    $basePrompt = sprintf(
      "Audience=%s. Response style=%s. Keep response non-generic and tailored to fingerprint segment %s. Ticket context: matric=%s, course=%s, requested_action=%s, classification=%s, action=%s, rulebook_applied=%s, confidence=%.2f, fpMatch=%s, ipMatch=%s, revoked=%s, checkinCount=%d, checkoutCount=%d, policy_reason=%s, student_message=%s",
      $audience,
      $style,
      $fpShort,
      $matric,
      $course,
      $requestedAction !== '' ? $requestedAction : 'unknown',
      (string)($diagnosis['classification'] ?? 'unknown'),
      (string)($diagnosis['action'] ?? 'unknown'),
      !empty($diagnosis['rulebook_applied']) ? 'true' : 'false',
      (float)($diagnosis['confidence'] ?? 0),
      !empty($diagnosis['fpMatch']) ? 'true' : 'false',
      !empty($diagnosis['ipMatch']) ? 'true' : 'false',
      !empty($diagnosis['revoked']) ? 'true' : 'false',
      (int)($diagnosis['checkinCount'] ?? 0),
      (int)($diagnosis['checkoutCount'] ?? 0),
      (string)($diagnosis['reason'] ?? 'none'),
      $message !== '' ? $message : 'none'
    );

    $siteContext = trim((string)$siteContext);
    if ($siteContext === '') {
      return $basePrompt;
    }

    return $basePrompt
      . "\n\nUse this site structure/navigation context for direct, source-backed answers (never guess routes):\n"
      . $siteContext;
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
    } elseif ($classification === 'invalid_course_reference') {
      $choices = [
        'Ticket course is not configured in course catalog. Reject auto-write and request a valid course selection.',
        'Invalid course reference detected. Keep ticket in review and instruct student to use a configured course only.',
      ];
      $suggestion = $choices[$seed % count($choices)];
    } elseif ($classification === 'inactive_course_reference') {
      $choices = [
        'Course exists but is not active. On the AI Suggestions page, tell admin to activate that course for the student session first; after activation, Sentinel may write guarded manual attendance only if identity and duplicate checks pass.',
        'Inactive course request detected. Admin action: switch the attendance session to the student course if appropriate, then allow Sentinel/manual attendance only under course-safe guardrails.',
      ];
      $suggestion = $choices[$seed % count($choices)];
    } elseif ($classification === 'fingerprint_conflict_rig_attempt') {
      $choices = [
        'Fingerprint conflict across matrics detected. Treat as rig-risk and block automated attendance writes.',
        'Possible proxy/rig attempt: same fingerprint appears linked to another matric. Keep manual-review-only.',
      ];
      $suggestion = $choices[$seed % count($choices)];
    } elseif ($classification === 'policy_device_sharing_risk') {
      $choices = [
        'Same-day same-course device signature overlaps with another matric. Keep this ticket in manual review and do not auto-write attendance.',
        'Policy risk detected: one device context appears linked to multiple students for this course today. Require admin verification before any attendance change.',
        'Potential proxy attendance attempt detected for this course/day. Hold automated remediation and request manual identity confirmation.'
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
        'Valid session signal detected. Sentinel can register the missing attendance as a guarded manual attendance entry for this course only, then resolve the ticket.',
        'Looks like a recoverable session issue. Apply a course-scoped manual attendance fix under Sentinel guardrails and close the ticket.',
        'Session glitch confirmed with fingerprint/IP match. Safe to let Sentinel perform guarded manual attendance remediation for this course.'
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
    } elseif ($classification === 'invalid_course_reference') {
      $opts = [
        'The course in your request is not recognized in the system. Please select a valid configured course and contact admin.',
        'We could not verify your course against the configured list. Kindly choose a valid course and retry support.',
      ];
      $base = $opts[$seed % count($opts)];
    } elseif ($classification === 'inactive_course_reference') {
      $opts = [
        'We received your support ticket for this check-in request. That course is not active right now, so the support team is reviewing the course setup for you.',
        'This course is currently inactive in the attendance session. Your support ticket is under review, and the team will update the next attendance step here.',
      ];
      $base = $opts[$seed % count($opts)];
    } elseif ($classification === 'fingerprint_conflict_rig_attempt') {
      $opts = [
        'We detected a fingerprint conflict that requires admin identity verification before attendance can proceed.',
        'This request is paused due to a device identity conflict. Please contact admin support for manual verification.',
      ];
      $base = $opts[$seed % count($opts)];
    } elseif ($classification === 'policy_device_sharing_risk') {
      $opts = [
        "Your request is under manual review due to a same-device policy check for {$course} today. Please wait for admin verification.",
        "We detected a policy conflict for {$course} on this device context. Admin must verify before attendance can be updated.",
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
        "We detected a session issue and are reviewing your {$course} attendance request now. You do not need to keep refreshing; the next update will appear on this device.",
        "Your request looks valid and we are checking the safest fix for {$course}. Please wait for the next update here instead of refreshing repeatedly.",
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
    $allowHumor = !empty($context['allow_humor']);
    $isGreeting = (bool)preg_match('/\b(hello|hi|hey|yo|sup|what\'?s\s+up|good\s+(morning|afternoon|evening))\b/i', (string)$adminMessage);
    $isSmallTalk = (bool)preg_match('/\b(how\s+are\s+you|who\s+are\s+you|how\s+far|howdy|thanks|thank\s+you|good\s+night|good\s+day|nice\s+one|wassup|what\'?s\s+good)\b/i', (string)$adminMessage);
    $isCapabilityRequest = (bool)preg_match('/\b(what\s+can\s+you\s+do|capabilit(y|ies)|apart\s+from\s+tickets?|apart\s+from\s+announcements?|help\s+with\s+what)\b/i', (string)$adminMessage);
    $wantsJoke = (bool)preg_match('/\b(joke|humou?r|funny|laugh|banter|gist)\b/i', (string)$adminMessage);

    $reply = 'Observation: input context is limited. Conclusion: a precise recommendation requires matric, course, and failed action. Action: share those fields for exact next-step guidance.';

    if ($isCapabilityRequest) {
      return [
        'ok' => true,
        'provider' => 'rules',
        'model' => 'rules-chat-v1',
        'latency_ms' => 0,
        'suggestion' => 'Beyond tickets and announcements, I can: (1) map admins to exact dashboard pages with route links, (2) summarize pending-review patterns and likely root causes, (3) advise checkin/checkout rule enforcement and guardrails, (4) assess fingerprint/IP/device mismatch risk, (5) process qualified support requests to clear tab-fencing or inactivity token blocks, (6) manage active blocked-token cleanup conditions, (7) trigger compulsory log auto-send after completed checkin+checkout cycles, and (8) generate concise operator-ready next actions from current chat context.'
      ];
    }

    if (strpos($msg, 'pending') !== false || strpos($msg, 'review') !== false) {
      $reply = 'Observation: unresolved high-risk diagnostics are present. Conclusion: queue health is constrained by priority incidents. Action: resolve revoked-device and fraud-sequence cases first, then process IP-rotation reviews.';
    } elseif (strpos($msg, 'fingerprint') !== false || strpos($msg, 'device') !== false) {
      $reply = 'Observation: fingerprint/device mismatch is the active signal. Conclusion: identity verification is required before attendance writes. Action: keep new-device cases in manual review; allow guarded remediation only for verified known-device history.';
    } elseif (strpos($msg, 'checkin') !== false || strpos($msg, 'checkout') !== false) {
      $reply = 'Observation: attendance action validation is requested. Conclusion: course-scoped guardrails must be enforced. Action: block duplicate checkin, block checkout without prior checkin, and block duplicate checkout.';
    } elseif ($isGreeting || $isSmallTalk) {
      $smallTalkReplies = [
        'Hello. Sentinel AI is online and ready to assist with operations diagnostics, route guidance, or a quick sanity-check.',
        'Hi. I am here and watching the board. If you want, I can keep it light for a moment or jump straight into tickets, logs, or attendance checks.',
        'Hey. Sentinel AI is active. We can gist briefly, then pivot into anything operational the moment you need it.'
      ];
      $reply = $smallTalkReplies[abs(crc32((string)$adminMessage)) % count($smallTalkReplies)];
    }

    if ($allowHumor && $wantsJoke) {
      $jokes = [
        'Quick one: I told the duplicate ticket to wait its turn, but it said it had already submitted that request.',
        'Small admin joke: the only thing faster than a rumor is a misconfigured attendance retry.',
        'Tiny one: I like calm dashboards. They are the only places where nothing is on fire and everyone still looks busy.'
      ];
      $reply = $jokes[abs(crc32((string)$adminMessage . '|joke')) % count($jokes)];
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
