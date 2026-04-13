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
    return (bool)@file_put_contents(self::filePath(), json_encode($rulebook, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
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

  public static function teachRule($text, $actor = 'admin')
  {
    $parsed = self::parseFreeTextRule($text);
    if (empty($parsed['ok'])) {
      return $parsed;
    }

    $rulebook = self::load();
    $rules = $rulebook['rules'] ?? [];

    $ruleId = 'rule_custom_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $priorityBase = 800;
    foreach ($rules as $r) {
      $priorityBase = max($priorityBase, (int)($r['priority'] ?? 0) + 1);
    }

    $now = date('c');
    $newRule = [
      'id' => $ruleId,
      'priority' => $priorityBase,
      'enabled' => true,
      'intent' => trim((string)$text),
      'conditions' => $parsed['conditions'],
      'outcome' => $parsed['outcome'],
      'examples' => [],
      'created_by' => (string)$actor,
      'updated_by' => (string)$actor,
      'created_at' => $now,
      'updated_at' => $now,
    ];

    $rules[] = $newRule;
    $rulebook['rules'] = $rules;
    self::save($rulebook);

    return ['ok' => true, 'rule' => $newRule, 'rulebook_version' => (string)($rulebook['version'] ?? 'rulebook-v1')];
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
