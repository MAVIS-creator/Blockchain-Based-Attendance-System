<?php

require_once __DIR__ . '/../admin/state_helpers.php';
require_once __DIR__ . '/AiProviderClient.php';

class AiRulebook
{
  private static function filePath()
  {
    if (function_exists('ai_rulebook_file')) {
      return ai_rulebook_file();
    }
    return admin_storage_migrate_file('ai_rulebook.json');
  }

  public static function defaultRulebook()
  {
    return [
      'version' => 'rulebook-v1',
      'updated_at' => date('c'),
      'rules' => [
        [
          'id' => 'rule_invalid_course_reference',
          'priority' => 1000,
          'enabled' => true,
          'title' => 'Invalid course reference',
          'intent' => 'If course does not exist in course catalog, block auto-write and mark invalid course.',
          'conditions' => ['course_exists' => false],
          'outcome' => [
            'classification' => 'invalid_course_reference',
            'action' => 'deny_and_review',
            'confidence' => 0.99,
            'suggested_admin_action' => 'Requested course is not configured. Ask student to select a valid course and resubmit.',
            'reason' => 'Rulebook: requested course does not exist in configured catalog.'
          ],
          'examples' => ['Course=CSC401 but catalog has only Group 1..Group 14.'],
          'created_by' => 'system',
          'updated_by' => 'system',
          'created_at' => date('c'),
          'updated_at' => date('c')
        ],
        [
          'id' => 'rule_inactive_course_reference',
          'priority' => 990,
          'enabled' => true,
          'title' => 'Inactive course reference',
          'intent' => 'If course exists but is not active, block auto-write and require review.',
          'conditions' => ['course_exists' => true, 'course_is_active' => false],
          'outcome' => [
            'classification' => 'inactive_course_reference',
            'action' => 'deny_and_review',
            'confidence' => 0.96,
            'suggested_admin_action' => 'Requested course is not active for this session. Verify schedule before attendance write.',
            'reason' => 'Rulebook: requested course is not currently active.'
          ],
          'examples' => ['Ticket course=Group 14 while active course=Group 2.'],
          'created_by' => 'system',
          'updated_by' => 'system',
          'created_at' => date('c'),
          'updated_at' => date('c')
        ],
        [
          'id' => 'rule_missing_identity_keys_for_auto_write',
          'priority' => 980,
          'enabled' => true,
          'title' => 'Missing identity keys',
          'intent' => 'Missing fingerprint/IP should block auto-write because linkage is incomplete.',
          'conditions' => ['identity_keys_present' => false],
          'outcome' => [
            'classification' => 'manual_review_required',
            'action' => 'deny_and_review',
            'confidence' => 0.95,
            'suggested_admin_action' => 'Fingerprint/IP missing. Require manual verification before attendance write.',
            'reason' => 'Rulebook: missing fingerprint or IP for policy-safe auto-write.'
          ],
          'examples' => ['Support ticket has empty fingerprint or unknown IP.'],
          'created_by' => 'system',
          'updated_by' => 'system',
          'created_at' => date('c'),
          'updated_at' => date('c')
        ],
        [
          'id' => 'rule_fingerprint_conflict_rig_attempt',
          'priority' => 970,
          'enabled' => true,
          'title' => 'Fingerprint conflict rig attempt',
          'intent' => 'If fingerprint appears tied to another matric same day/course, treat as rig attempt.',
          'conditions' => ['device_sharing_risk' => true],
          'outcome' => [
            'classification' => 'fingerprint_conflict_rig_attempt',
            'action' => 'manual_review_only',
            'confidence' => 0.98,
            'suggested_admin_action' => 'Fingerprint conflict detected across matrics for same course/day. Block auto-write and verify identity manually.',
            'reason' => 'Rulebook: fingerprint overlap indicates possible rig/proxy attempt.'
          ],
          'examples' => ['Same fingerprint used by two matrics in one course on same date.'],
          'created_by' => 'system',
          'updated_by' => 'system',
          'created_at' => date('c'),
          'updated_at' => date('c')
        ]
      ]
    ];
  }

  public static function ensureSeeded()
  {
    $file = self::filePath();
    if (file_exists($file)) {
      return;
    }
    @file_put_contents($file, json_encode(self::defaultRulebook(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  public static function load()
  {
    self::ensureSeeded();
    $raw = @file_get_contents(self::filePath());
    $decoded = json_decode((string)$raw, true);
    if (!is_array($decoded)) {
      $decoded = self::defaultRulebook();
    }
    if (!isset($decoded['rules']) || !is_array($decoded['rules'])) {
      $decoded['rules'] = [];
    }
    usort($decoded['rules'], function ($a, $b) {
      return ((int)($b['priority'] ?? 0)) <=> ((int)($a['priority'] ?? 0));
    });
    return $decoded;
  }

  public static function save(array $rulebook)
  {
    $rulebook['updated_at'] = date('c');
    if (!isset($rulebook['version']) || trim((string)$rulebook['version']) === '') {
      $rulebook['version'] = 'rulebook-v1';
    }
    if (!isset($rulebook['rules']) || !is_array($rulebook['rules'])) {
      $rulebook['rules'] = [];
    }
    $rulebook['rules'] = array_values(self::dedupeLoadedRules($rulebook['rules']));
    return (bool)@file_put_contents(self::filePath(), json_encode($rulebook, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
  }

  public static function resetToDefaults()
  {
    return self::save(self::defaultRulebook());
  }

  public static function clearRules($mode = 'reset_defaults')
  {
    $mode = strtolower(trim((string)$mode));
    if ($mode === 'empty') {
      return self::save([
        'version' => 'rulebook-v1',
        'updated_at' => date('c'),
        'rules' => [],
      ]);
    }

    return self::resetToDefaults();
  }

  private static function conditionSatisfied($expected, $actual)
  {
    if (is_bool($expected)) {
      return (bool)$actual === $expected;
    }
    if (is_numeric($expected) && is_numeric($actual)) {
      return (float)$actual === (float)$expected;
    }
    return strtolower(trim((string)$actual)) === strtolower(trim((string)$expected));
  }

  public static function evaluate(array $facts)
  {
    $rulebook = self::load();
    $rules = $rulebook['rules'] ?? [];
    foreach ($rules as $rule) {
      if (empty($rule['enabled'])) {
        continue;
      }
      $conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : [];
      $matched = true;
      foreach ($conditions as $k => $expectedValue) {
        if (!array_key_exists($k, $facts) || !self::conditionSatisfied($expectedValue, $facts[$k])) {
          $matched = false;
          break;
        }
      }
      if ($matched) {
        $outcome = isset($rule['outcome']) && is_array($rule['outcome']) ? $rule['outcome'] : [];
        $outcome['matched_rule_id'] = (string)($rule['id'] ?? '');
        $outcome['rulebook_version'] = (string)($rulebook['version'] ?? 'rulebook-v1');
        return $outcome;
      }
    }

    return [];
  }

  private static function parseFreeTextRule($text)
  {
    $text = trim((string)$text);
    if ($text === '') {
      return ['ok' => false, 'error' => 'empty_rule_text'];
    }

    if (class_exists('AiProviderClient') && method_exists('AiProviderClient', 'interpretRuleText')) {
      $aiParsed = AiProviderClient::interpretRuleText($text);
      if (!empty($aiParsed['ok']) && !empty($aiParsed['conditions']) && !empty($aiParsed['outcome'])) {
        $outcome = $aiParsed['outcome'];
        if (!isset($outcome['confidence']) || !is_numeric($outcome['confidence'])) {
          $outcome['confidence'] = 0.9;
        }
        if (!isset($outcome['suggested_admin_action']) || trim((string)$outcome['suggested_admin_action']) === '') {
          $outcome['suggested_admin_action'] = 'Review ticket with updated policy rule.';
        }
        if (!isset($outcome['reason']) || trim((string)$outcome['reason']) === '') {
          $outcome['reason'] = 'Rulebook custom rule applied.';
        }
        return [
          'ok' => true,
          'conditions' => $aiParsed['conditions'],
          'outcome' => $outcome,
          'parser' => 'ai',
          'provider' => (string)($aiParsed['provider'] ?? ''),
          'model' => (string)($aiParsed['model'] ?? ''),
        ];
      }
    }

    $lower = strtolower($text);
    $conditions = [];
    $outcome = [
      'classification' => 'manual_review_required',
      'action' => 'guide_and_admin_review',
      'confidence' => 0.9,
      'suggested_admin_action' => 'Review ticket with updated policy rule.',
      'reason' => 'Rulebook custom rule applied.'
    ];

    if (strpos($lower, 'course') !== false && (strpos($lower, 'not exist') !== false || strpos($lower, 'invalid') !== false)) {
      $conditions['course_exists'] = false;
      $outcome['classification'] = 'invalid_course_reference';
      $outcome['action'] = 'deny_and_review';
      $outcome['reason'] = 'Rulebook custom: invalid course is blocked.';
    }

    if (strpos($lower, 'not active') !== false || strpos($lower, 'inactive course') !== false) {
      $conditions['course_exists'] = true;
      $conditions['course_is_active'] = false;
      $outcome['classification'] = 'inactive_course_reference';
      $outcome['action'] = 'deny_and_review';
      $outcome['reason'] = 'Rulebook custom: inactive course blocked.';
    }

    if (strpos($lower, 'fingerprint') !== false && (strpos($lower, 'another matric') !== false || strpos($lower, 'shared') !== false || strpos($lower, 'rig') !== false)) {
      $conditions['device_sharing_risk'] = true;
      $outcome['classification'] = 'fingerprint_conflict_rig_attempt';
      $outcome['action'] = 'manual_review_only';
      $outcome['confidence'] = 0.98;
      $outcome['reason'] = 'Rulebook custom: fingerprint conflict considered rig risk.';
    }

    if (strpos($lower, 'auto') !== false && strpos($lower, 'checkin') !== false && strpos($lower, 'valid') !== false) {
      $conditions['course_exists'] = true;
      $conditions['course_is_active'] = true;
      $conditions['identity_keys_present'] = true;
      $conditions['device_sharing_risk'] = false;
      $outcome['classification'] = 'legitimate_session_issue';
      $outcome['action'] = 'auto_fix_add_attendance';
      $outcome['confidence'] = 0.95;
      $outcome['reason'] = 'Rulebook custom: policy-safe auto-write enabled for valid identity/course.';
    }

    if (empty($conditions)) {
      return [
        'ok' => false,
        'error' => 'rule_text_not_understood',
        'hint' => 'Try including specific conditions like invalid course, inactive course, fingerprint conflict, or auto checkin when valid.'
      ];
    }

    return [
      'ok' => true,
      'conditions' => $conditions,
      'outcome' => $outcome,
      'parser' => 'heuristic',
    ];
  }

  private static function normalizePolicyText($text)
  {
    $text = str_replace(["\r\n", "\r"], "\n", (string)$text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim((string)$text);
  }

  private static function titleFromText($text)
  {
    $text = trim((string)$text);
    if ($text === '') {
      return 'Custom rule';
    }

    $headline = preg_split('/[\r\n\.\!\?;]+/', $text, 2);
    $headline = trim((string)($headline[0] ?? ''));
    if ($headline === '') {
      return 'Custom rule';
    }

    $headline = preg_replace('/^\d+[\.\)]\s*/', '', $headline);
    $headline = preg_replace('/\s+/', ' ', $headline);
    if (mb_strlen($headline) > 72) {
      $headline = mb_substr($headline, 0, 72);
      $headline = rtrim($headline, " ,;:-");
    }

    return $headline !== '' ? $headline : 'Custom rule';
  }

  private static function sourceHash($text)
  {
    return sha1(self::normalizePolicyText($text));
  }

  private static function splitPolicyChunks($text)
  {
    $text = self::normalizePolicyText($text);
    if ($text === '') {
      return [];
    }

    $headingPattern = '/^\s*(?:\d+[\.\)]\s+)?(?:PRIMARY VALIDATION|REVOKED CHECK|MATRIC VALIDATION|DUPLICATE ATTENDANCE|DOUBLE ACTION|INVALID FLOW|PROXY|FRIEND ATTEMPT|VALID USER FAILURE|NEW DEVICE|FINGERPRINT LINKING|CONTROLLED ASSISTANCE|FAILED SUBMISSION RECOVERY|SUCCESSFUL COMPLETION|TARGETED RESPONSE|TICKET AUTO-RESOLUTION|ADMIN ESCALATION|VALIDATION PRIORITY ORDER|CORE PRINCIPLE|FINAL BEHAVIOR)\b/i';
    $numberedPattern = '/^\s*\d+[\.\)]\s+/';

    $lines = preg_split("/\n/", $text);
    if (!is_array($lines) || empty($lines)) {
      return [$text];
    }

    $chunks = [];
    $buffer = '';
    foreach ($lines as $line) {
      $trimmed = trim((string)$line);
      if ($trimmed === '') {
        if ($buffer !== '') {
          $buffer .= "\n";
        }
        continue;
      }

      $startsNewChunk = preg_match($headingPattern, $trimmed) || preg_match($numberedPattern, $trimmed);
      if ($startsNewChunk && trim($buffer) !== '') {
        $chunks[] = trim($buffer);
        $buffer = '';
      }

      $buffer .= ($buffer !== '' ? "\n" : '') . $trimmed;
    }

    if (trim($buffer) !== '') {
      $chunks[] = trim($buffer);
    }

    $cleaned = [];
    foreach ($chunks as $chunk) {
      $chunk = preg_replace('/^\d+[\.\)]\s*/', '', trim((string)$chunk));
      if ($chunk !== '') {
        $cleaned[] = $chunk;
      }
    }

    return !empty($cleaned) ? $cleaned : [$text];
  }

  private static function buildRuleCandidate($intent, array $conditions, array $outcome)
  {
    if (empty($conditions) || empty($outcome['classification']) || empty($outcome['action'])) {
      return null;
    }

    if (!isset($outcome['confidence']) || !is_numeric($outcome['confidence'])) {
      $outcome['confidence'] = 0.9;
    }
    if (!isset($outcome['suggested_admin_action']) || trim((string)$outcome['suggested_admin_action']) === '') {
      $outcome['suggested_admin_action'] = 'Review ticket with updated policy rule.';
    }
    if (!isset($outcome['reason']) || trim((string)$outcome['reason']) === '') {
      $outcome['reason'] = 'Rulebook custom rule applied.';
    }

    return [
      'title' => self::titleFromText($intent),
      'intent' => trim((string)$intent),
      'source_text' => self::normalizePolicyText($intent),
      'source_hash' => self::sourceHash($intent),
      'conditions' => $conditions,
      'outcome' => $outcome,
    ];
  }

  private static function parsePolicyChunk($chunk)
  {
    $chunk = trim((string)$chunk);
    if ($chunk === '') {
      return [];
    }

    $lower = strtolower($chunk);
    if (
      strpos($lower, 'you are sentinel ai') === 0
      || strpos($lower, 'system operator responsible') !== false
      || strpos($lower, 'master prompt') !== false
    ) {
      return [];
    }
    $candidates = [];

    if (
      strpos($lower, 'revoked') !== false
      || strpos($lower, 'block all actions') !== false
      || strpos($lower, 'do not allow attendance') !== false
    ) {
      $rule = self::buildRuleCandidate($chunk, ['revoked' => true], [
        'classification' => 'blocked_revoked_device',
        'action' => 'deny_and_notify',
        'confidence' => 0.99,
        'suggested_admin_action' => 'Keep the device blocked. Do not register attendance or help submission until a valid re-enable review is completed.',
        'reason' => 'Rulebook custom: revoked fingerprints or IPs must be blocked from attendance actions.'
      ]);
      if ($rule) {
        $candidates[] = $rule;
      }
    }

    if (
      strpos($lower, 'matric') !== false
      && (strpos($lower, 'already recorded attendance') !== false || strpos($lower, 'matric already used today') !== false || strpos($lower, 'attendance exists') !== false)
    ) {
      $rule = self::buildRuleCandidate($chunk, ['has_checkin' => true], [
        'classification' => 'duplicate_submission_attempt',
        'action' => 'deny_and_notify',
        'confidence' => 0.95,
        'suggested_admin_action' => 'Attendance already exists for this matric today. Reject new submission and do not link a new fingerprint.',
        'reason' => 'Rulebook custom: matric already has attendance today.'
      ]);
      if ($rule) {
        $candidates[] = $rule;
      }
    }

    if (
      strpos($lower, 'duplicate') !== false
      || strpos($lower, 'already recorded for today') !== false
      || strpos($lower, 'check-in twice') !== false
      || strpos($lower, 'check-out twice') !== false
      || strpos($lower, 'check-out without check-in') !== false
    ) {
      if (
        strpos($lower, 'already has check-in or check-out or both') !== false
        || strpos($lower, 'already has check-in') !== false
        || strpos($lower, 'already has check-out') !== false
        || strpos($lower, 'attendance already recorded for today') !== false
      ) {
        $rule = self::buildRuleCandidate($chunk, ['has_checkin' => true], [
          'classification' => 'duplicate_submission_attempt',
          'action' => 'deny_and_notify',
          'confidence' => 0.95,
          'suggested_admin_action' => 'Reject the request and do not write a new attendance entry because attendance is already recorded for today.',
          'reason' => 'Rulebook custom: attendance already exists for the student today.'
        ]);
        if ($rule) {
          $candidates[] = $rule;
        }
      }

      if (strpos($lower, 'check-out without check-in') !== false || strpos($lower, 'checkout without checkin') !== false) {
        $rule = self::buildRuleCandidate($chunk, ['requested_action' => 'checkout', 'has_checkin' => false], [
          'classification' => 'manual_review_required',
          'action' => 'deny_and_review',
          'confidence' => 0.95,
          'suggested_admin_action' => 'Reject checkout because no prior check-in exists for this course today.',
          'reason' => 'Rulebook custom: checkout attempted before a valid check-in.'
        ]);
        if ($rule) {
          $candidates[] = $rule;
        }
      }

      if (strpos($lower, 'check-in twice') !== false || strpos($lower, 'checkin twice') !== false) {
        $rule = self::buildRuleCandidate($chunk, ['requested_action' => 'checkin', 'has_checkin' => true], [
          'classification' => 'duplicate_submission_attempt',
          'action' => 'deny_and_notify',
          'confidence' => 0.95,
          'suggested_admin_action' => 'Reject duplicate check-in. Attendance log should remain unchanged.',
          'reason' => 'Rulebook custom: second check-in attempt detected.'
        ]);
        if ($rule) {
          $candidates[] = $rule;
        }
      }

      if (strpos($lower, 'check-out twice') !== false || strpos($lower, 'checkout twice') !== false) {
        $rule = self::buildRuleCandidate($chunk, ['requested_action' => 'checkout', 'has_checkout' => true], [
          'classification' => 'duplicate_submission_attempt',
          'action' => 'deny_and_notify',
          'confidence' => 0.95,
          'suggested_admin_action' => 'Reject duplicate checkout. Attendance log should remain unchanged.',
          'reason' => 'Rulebook custom: second checkout attempt detected.'
        ]);
        if ($rule) {
          $candidates[] = $rule;
        }
      }
    }

    if (
      (strpos($lower, 'proxy') !== false || strpos($lower, 'friend attempt') !== false || strpos($lower, 'identity is inconsistent') !== false)
      || (strpos($lower, 'fingerprint/ip exists') !== false && strpos($lower, 'matric') !== false && strpos($lower, 'inconsistent') !== false)
    ) {
      $rule = self::buildRuleCandidate($chunk, ['device_sharing_risk' => true], [
        'classification' => 'fingerprint_conflict_rig_attempt',
        'action' => 'manual_review_only',
        'confidence' => 0.98,
        'suggested_admin_action' => 'Block attendance and flag as suspicious. Do not allow automated or manual attendance until identity is verified.',
        'reason' => 'Rulebook custom: device identity conflicts with the matric or user identity.'
      ]);
      if ($rule) {
        $candidates[] = $rule;
      }
    }

    if (
      strpos($lower, 'new device') !== false
      || strpos($lower, 'fingerprint and ip are not found') !== false
      || strpos($lower, 'if both match') !== false
      || strpos($lower, 'if no match') !== false
    ) {
      $rule = self::buildRuleCandidate($chunk, ['fp_match' => false, 'ip_match' => false], [
        'classification' => 'new_or_suspicious_device',
        'action' => 'verify_and_admin_review',
        'confidence' => 0.74,
        'suggested_admin_action' => 'Treat as a new device. Do not auto-approve immediately; continue with controlled validation before any attendance write.',
        'reason' => 'Rulebook custom: fingerprint and IP are not found in logs.'
      ]);
      if ($rule) {
        $candidates[] = $rule;
      }
    }

    if (
      strpos($lower, 'link fingerprint') !== false
      || strpos($lower, 'allow attendance submission') !== false
      || strpos($lower, 'controlled assistance') !== false
      || strpos($lower, 'help complete attendance') !== false
      || strpos($lower, 'failed submission recovery') !== false
    ) {
      $conditions = [
        'revoked' => false,
        'device_sharing_risk' => false,
        'course_exists' => true,
        'course_is_active' => true,
        'identity_keys_present' => true,
      ];

      if (strpos($lower, 'matric has no attendance today') !== false || strpos($lower, 'matric unused') !== false) {
        $conditions['has_checkin'] = false;
      }

      $rule = self::buildRuleCandidate($chunk, $conditions, [
        'classification' => 'legitimate_session_issue',
        'action' => 'auto_fix_add_attendance',
        'confidence' => 0.95,
        'suggested_admin_action' => 'If the user passes identity and duplicate checks, Sentinel may link the device context if needed and register guarded manual attendance for this course.',
        'reason' => 'Rulebook custom: controlled assistance is allowed for legitimate attendance failures.'
      ]);
      if ($rule) {
        $candidates[] = $rule;
      }
    }

    if (
      strpos($lower, 'successful completion') !== false
      || (strpos($lower, 'valid check-in') !== false && strpos($lower, 'valid check-out') !== false)
      || strpos($lower, 'attendance complete') !== false
    ) {
      $rule = self::buildRuleCandidate($chunk, ['has_checkin' => true, 'has_checkout' => true], [
        'classification' => 'duplicate_submission_attempt',
        'action' => 'deny_and_notify',
        'confidence' => 0.92,
        'suggested_admin_action' => 'Attendance is already complete for today. No further write is needed; resolve the ticket with completion guidance.',
        'reason' => 'Rulebook custom: a valid check-in and check-out already exist.'
      ]);
      if ($rule) {
        $candidates[] = $rule;
      }
    }

    return $candidates;
  }

  private static function isInstructionalPreamble($text)
  {
    $lower = strtolower(trim((string)$text));
    if ($lower === '') {
      return false;
    }

    return strpos($lower, 'you are sentinel ai') === 0
      || strpos($lower, 'system operator responsible') !== false
      || strpos($lower, 'master prompt') !== false;
  }

  private static function dedupeRuleCandidates(array $rules)
  {
    $seen = [];
    $out = [];
    foreach ($rules as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      if (!isset($rule['title']) || trim((string)$rule['title']) === '') {
        $rule['title'] = self::titleFromText((string)($rule['intent'] ?? ''));
      }
      if (!isset($rule['source_hash']) || trim((string)$rule['source_hash']) === '') {
        $sourceText = (string)($rule['source_text'] ?? $rule['intent'] ?? '');
        $rule['source_text'] = self::normalizePolicyText($sourceText);
        $rule['source_hash'] = self::sourceHash($sourceText);
      }
      $sourceKey = strtolower(trim((string)($rule['source_hash'] ?? '')));
      if (isset($seen[$sourceKey])) {
        continue;
      }
      $seen[$sourceKey] = true;
      $out[] = $rule;
    }
    return $out;
  }

  private static function dedupeLoadedRules(array $rules)
  {
    $seen = [];
    $out = [];
    foreach ($rules as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      $title = trim((string)($rule['title'] ?? $rule['intent'] ?? ''));
      if ($title === '') {
        $title = self::titleFromText((string)($rule['intent'] ?? ''));
      }
      $sourceHash = trim((string)($rule['source_hash'] ?? ''));
      if ($sourceHash === '') {
        $sourceText = (string)($rule['source_text'] ?? $rule['intent'] ?? '');
        $sourceHash = self::sourceHash($sourceText);
        $rule['source_text'] = self::normalizePolicyText($sourceText);
        $rule['source_hash'] = $sourceHash;
      }
      $sourceKey = strtolower($sourceHash);
      if (isset($seen[$sourceKey])) {
        continue;
      }
      $seen[$sourceKey] = true;
      $rule['title'] = $title;
      $out[] = $rule;
    }
    return $out;
  }

  private static function parseFreeTextRules($text)
  {
    $text = trim((string)$text);
    if ($text === '') {
      return ['ok' => false, 'error' => 'empty_rule_text'];
    }

    $rules = [];
    $chunks = self::splitPolicyChunks($text);
    $chunkCount = count($chunks);

    if ($chunkCount === 1) {
      $parsed = self::parseFreeTextRule($text);
      if (!empty($parsed['ok'])) {
        $rules[] = [
          'title' => self::titleFromText($text),
          'intent' => trim((string)$text),
          'source_text' => self::normalizePolicyText($text),
          'source_hash' => self::sourceHash($text),
          'conditions' => $parsed['conditions'],
          'outcome' => $parsed['outcome'],
        ];
      }
    } else {
      foreach ($chunks as $chunk) {
        $chunkRules = self::parsePolicyChunk($chunk);
        if (!empty($chunkRules)) {
          foreach ($chunkRules as $chunkRule) {
            $rules[] = $chunkRule;
          }
          continue;
        }

        if (self::isInstructionalPreamble($chunk)) {
          continue;
        }

        $parsed = self::parseFreeTextRule($chunk);
        if (!empty($parsed['ok'])) {
          $rules[] = [
            'title' => self::titleFromText($chunk),
            'intent' => trim((string)$chunk),
            'source_text' => self::normalizePolicyText($chunk),
            'source_hash' => self::sourceHash($chunk),
            'conditions' => $parsed['conditions'],
            'outcome' => $parsed['outcome'],
          ];
        }
      }
    }

    $rules = self::dedupeRuleCandidates($rules);
    if (empty($rules)) {
      return [
        'ok' => false,
        'error' => 'rule_text_not_understood',
        'hint' => 'Write naturally. Sentinel will try to split long policy text into multiple ticket-validation rules automatically.'
      ];
    }

    return [
      'ok' => true,
      'rules' => $rules,
      'chunk_count' => $chunkCount,
      'rule_count' => count($rules),
    ];
  }

  public static function teachRule($text, $actor = 'admin')
  {
    $parsed = self::parseFreeTextRules($text);
    if (empty($parsed['ok'])) {
      return $parsed;
    }

    $rulebook = self::load();
    $rules = $rulebook['rules'] ?? [];

    $now = date('c');
    $newRules = [];
    foreach (($parsed['rules'] ?? []) as $parsedRule) {
      $normalizedTitle = self::titleFromText((string)($parsedRule['title'] ?? $parsedRule['intent'] ?? $text));
      $conditions = $parsedRule['conditions'] ?? [];
      $outcome = $parsedRule['outcome'] ?? [];
      $sourceText = self::normalizePolicyText((string)($parsedRule['source_text'] ?? $parsedRule['intent'] ?? $text));
      $sourceHash = self::sourceHash($sourceText);

      $matchedIndex = null;
      foreach ($rules as $idx => $existingRule) {
        if (!is_array($existingRule)) {
          continue;
        }
        $existingSourceHash = strtolower(trim((string)($existingRule['source_hash'] ?? '')));
        if ($existingSourceHash === '') {
          $existingSourceText = (string)($existingRule['source_text'] ?? $existingRule['intent'] ?? '');
          $existingSourceHash = self::sourceHash($existingSourceText);
        }
        if ($existingSourceHash === strtolower($sourceHash)) {
          $matchedIndex = $idx;
          break;
        }
      }

      if ($matchedIndex !== null) {
        $rules[$matchedIndex]['enabled'] = true;
        $rules[$matchedIndex]['intent'] = trim((string)($parsedRule['intent'] ?? $text));
        $rules[$matchedIndex]['title'] = $normalizedTitle;
        $rules[$matchedIndex]['source_text'] = $sourceText;
        $rules[$matchedIndex]['source_hash'] = $sourceHash;
        $rules[$matchedIndex]['conditions'] = $conditions;
        $rules[$matchedIndex]['outcome'] = $outcome;
        $rules[$matchedIndex]['updated_by'] = (string)$actor;
        $rules[$matchedIndex]['updated_at'] = $now;
        $newRules[] = $rules[$matchedIndex];
        continue;
      }

      $priorityBase = 800;
      foreach ($rules as $r) {
        $priorityBase = max($priorityBase, (int)($r['priority'] ?? 0) + 1);
      }

      $ruleId = 'rule_custom_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
      $newRule = [
        'id' => $ruleId,
        'priority' => $priorityBase,
        'enabled' => true,
        'title' => $normalizedTitle,
        'intent' => trim((string)($parsedRule['intent'] ?? $text)),
        'source_text' => $sourceText,
        'source_hash' => $sourceHash,
        'conditions' => $conditions,
        'outcome' => $outcome,
        'examples' => [],
        'created_by' => (string)$actor,
        'updated_by' => (string)$actor,
        'created_at' => $now,
        'updated_at' => $now,
      ];
      $rules[] = $newRule;
      $newRules[] = $newRule;
    }

    $rulebook['rules'] = $rules;
    self::save($rulebook);

    return [
      'ok' => true,
      'rule' => $newRules[0] ?? null,
      'rules' => $newRules,
      'rule_count' => count($newRules),
      'chunk_count' => (int)($parsed['chunk_count'] ?? 1),
      'rulebook_version' => (string)($rulebook['version'] ?? 'rulebook-v1')
    ];
  }

  public static function rephraseRule($ruleId, $newText, $actor = 'admin')
  {
    $ruleId = trim((string)$ruleId);
    if ($ruleId === '') {
      return ['ok' => false, 'error' => 'missing_rule_id'];
    }

    $parsed = self::parseFreeTextRule($newText);
    if (empty($parsed['ok'])) {
      return $parsed;
    }

    $rulebook = self::load();
    $rules = $rulebook['rules'] ?? [];
    $found = false;

    foreach ($rules as &$rule) {
      if ((string)($rule['id'] ?? '') !== $ruleId) {
        continue;
      }
      $rule['intent'] = trim((string)$newText);
      $rule['conditions'] = $parsed['conditions'];
      $rule['outcome'] = $parsed['outcome'];
      $rule['updated_by'] = (string)$actor;
      $rule['updated_at'] = date('c');
      $found = true;
      break;
    }
    unset($rule);

    if (!$found) {
      return ['ok' => false, 'error' => 'rule_not_found'];
    }

    $rulebook['rules'] = $rules;
    self::save($rulebook);

    return ['ok' => true, 'rule_id' => $ruleId];
  }

  public static function toggleRule($ruleId, $enabled, $actor = 'admin')
  {
    $ruleId = trim((string)$ruleId);
    if ($ruleId === '') {
      return ['ok' => false, 'error' => 'missing_rule_id'];
    }

    $rulebook = self::load();
    $rules = $rulebook['rules'] ?? [];
    $found = false;

    foreach ($rules as &$rule) {
      if ((string)($rule['id'] ?? '') !== $ruleId) {
        continue;
      }
      $rule['enabled'] = (bool)$enabled;
      $rule['updated_by'] = (string)$actor;
      $rule['updated_at'] = date('c');
      $found = true;
      break;
    }
    unset($rule);

    if (!$found) {
      return ['ok' => false, 'error' => 'rule_not_found'];
    }

    $rulebook['rules'] = $rules;
    self::save($rulebook);

    return ['ok' => true, 'rule_id' => $ruleId, 'enabled' => (bool)$enabled];
  }
}
