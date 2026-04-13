<?php
session_start();

header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../src/AiRulebook.php';

$currentRole = (string)($_SESSION['admin_role'] ?? 'admin');
$permissions = admin_load_permissions_cached();
$allowedPages = $permissions[$currentRole] ?? [];
if ($currentRole !== 'superadmin' && !in_array('ai_rulebook', $allowedPages, true)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'forbidden']);
  exit;
}

if (!csrf_check_request()) {
  http_response_code(419);
  echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
  exit;
}

$raw = @file_get_contents('php://input');
$payload = json_decode((string)$raw, true);
if (!is_array($payload)) {
  $payload = $_POST;
}

$action = strtolower(trim((string)($payload['action'] ?? 'list_rules')));
$actor = (string)($_SESSION['admin_user'] ?? 'admin');

if ($action === 'list_rules') {
  $rulebook = AiRulebook::load();
  echo json_encode([
    'ok' => true,
    'version' => (string)($rulebook['version'] ?? 'rulebook-v1'),
    'updated_at' => (string)($rulebook['updated_at'] ?? ''),
    'rules' => array_values($rulebook['rules'] ?? []),
  ]);
  exit;
}

if ($action === 'teach_rule') {
  $text = trim((string)($payload['text'] ?? ''));
  $result = AiRulebook::teachRule($text, $actor);
  if (empty($result['ok'])) {
    http_response_code(422);
  } else {
    if (function_exists('admin_log_action')) {
      admin_log_action('AI_Rulebook', 'Teach Rule', 'Rule taught by ' . $actor . ': ' . $text);
    }
  }
  echo json_encode($result);
  exit;
}

if ($action === 'rephrase_rule') {
  $ruleId = trim((string)($payload['rule_id'] ?? ''));
  $text = trim((string)($payload['text'] ?? ''));
  $result = AiRulebook::rephraseRule($ruleId, $text, $actor);
  if (empty($result['ok'])) {
    http_response_code(422);
  } else {
    if (function_exists('admin_log_action')) {
      admin_log_action('AI_Rulebook', 'Rephrase Rule', 'Rule rephrased by ' . $actor . ': ' . $ruleId);
    }
  }
  echo json_encode($result);
  exit;
}

if ($action === 'toggle_rule') {
  $ruleId = trim((string)($payload['rule_id'] ?? ''));
  $enabled = !empty($payload['enabled']);
  $result = AiRulebook::toggleRule($ruleId, $enabled, $actor);
  if (empty($result['ok'])) {
    http_response_code(422);
  } else {
    if (function_exists('admin_log_action')) {
      admin_log_action('AI_Rulebook', 'Toggle Rule', 'Rule ' . $ruleId . ' toggled to ' . ($enabled ? 'enabled' : 'disabled') . ' by ' . $actor);
    }
  }
  echo json_encode($result);
  exit;
}

if ($action === 'simulate_rule') {
  $facts = isset($payload['facts']) && is_array($payload['facts']) ? $payload['facts'] : [];
  $outcome = AiRulebook::evaluate($facts);
  echo json_encode([
    'ok' => true,
    'facts' => $facts,
    'matched' => !empty($outcome),
    'outcome' => $outcome,
  ]);
  exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown_action']);
