<?php
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/../src/AiRulebook.php';

$rulebook = AiRulebook::load();
$rules = array_values($rulebook['rules'] ?? []);
?>

<div style="margin-bottom:24px;">
  <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);letter-spacing:-0.02em;margin:0;display:flex;align-items:center;gap:8px;">
    <span class="material-symbols-outlined">rule_settings</span>
    AI Rulebook Trainer
  </h2>
  <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">
    Teach, rephrase, and toggle operational rules instantly. You can paste short rules or long natural-language policy prompts, and Sentinel will try to turn them into usable ticket rules.
  </p>
</div>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px 0;">
  <span class="st-chip st-chip-info">Version: <?= htmlspecialchars((string)($rulebook['version'] ?? 'rulebook-v1')) ?></span>
  <span class="st-chip st-chip-info">Rules: <?= (int)count($rules) ?></span>
  <span class="st-chip st-chip-info">Updated: <?= htmlspecialchars((string)($rulebook['updated_at'] ?? '-')) ?></span>
  <button id="clearRulesBtn" class="st-btn st-btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
    <span class="material-symbols-outlined" style="font-size:1rem;">delete_sweep</span>
    Reset Rules to Defaults
  </button>
</div>

<div id="rulebookNotice" style="display:none;margin:0 0 12px 0;padding:10px 12px;border-radius:10px;border:1px solid #cfe1f5;background:#eef6ff;color:#1d4f80;font-size:0.83rem;"></div>

<div class="st-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;">
  <section class="st-card" style="padding:14px;">
    <h3 style="margin:0 0 10px 0;font-size:1rem;font-weight:800;display:flex;align-items:center;gap:6px;">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">chat</span>
      Teach New Rule
    </h3>
    <p style="margin:0 0 10px 0;color:var(--on-surface-variant);font-size:0.8rem;line-height:1.4;">
      Example: <em>If fingerprint is linked to another matric same day, block auto write and classify as rig attempt.</em>
      You can also paste a long policy note or master prompt and Sentinel will split it into rule candidates.
    </p>
    <textarea id="teachRuleText" style="width:100%;min-height:120px;border:1px solid var(--outline-variant);border-radius:10px;padding:10px;font:inherit;"></textarea>
    <div style="display:flex;justify-content:flex-end;margin-top:10px;">
      <button id="teachRuleBtn" class="st-btn st-btn-primary" style="display:inline-flex;align-items:center;gap:6px;">
        <span class="material-symbols-outlined" style="font-size:1rem;">school</span>
        Teach Rule
      </button>
    </div>
  </section>

  <section class="st-card" style="padding:14px;">
    <h3 style="margin:0 0 10px 0;font-size:1rem;font-weight:800;display:flex;align-items:center;gap:6px;">
      <span class="material-symbols-outlined" style="font-size:1.1rem;">science</span>
      Rule Simulator
    </h3>
    <p style="margin:0 0 8px 0;color:var(--on-surface-variant);font-size:0.8rem;">Simulate facts against current rulebook.</p>
    <textarea id="simulateFacts" style="width:100%;min-height:120px;border:1px solid var(--outline-variant);border-radius:10px;padding:10px;font:12px/1.45 monospace;">{
  "course_exists": true,
  "course_is_active": true,
  "identity_keys_present": true,
  "device_sharing_risk": false,
  "fp_match": true,
  "ip_match": true,
  "requested_action": "checkin",
  "has_checkin": false,
  "has_checkout": false
}</textarea>
    <div style="display:flex;justify-content:flex-end;margin-top:10px;">
      <button id="simulateBtn" class="st-btn" style="display:inline-flex;align-items:center;gap:6px;">
        <span class="material-symbols-outlined" style="font-size:1rem;">play_arrow</span>
        Run Simulation
      </button>
    </div>
    <pre id="simulateOutput" style="display:none;margin-top:10px;max-height:220px;overflow:auto;background:var(--surface-container-lowest);border:1px solid var(--outline-variant);border-radius:8px;padding:10px;font:12px/1.45 monospace;"></pre>
  </section>
</div>

<section class="st-card" style="margin-top:12px;padding:14px;">
  <h3 style="margin:0 0 10px 0;font-size:1rem;font-weight:800;display:flex;align-items:center;gap:6px;">
    <span class="material-symbols-outlined" style="font-size:1.1rem;">list_alt</span>
    Current Rules (What AI Understands)
  </h3>

  <div id="rulesList" style="display:grid;gap:10px;">
    <?php foreach ($rules as $rule): ?>
      <article class="rule-card" data-rule-id="<?= htmlspecialchars((string)$rule['id']) ?>" style="padding:10px;border-radius:10px;border:1px solid var(--outline-variant);background:var(--surface-container-lowest);">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;">
          <div>
            <strong style="font-size:0.86rem;"><?= htmlspecialchars((string)($rule['title'] ?? $rule['id'] ?? 'rule')) ?></strong>
            <div style="font-size:0.72rem;color:var(--on-surface-variant);">ID: <?= htmlspecialchars((string)($rule['id'] ?? 'rule')) ?></div>
            <div style="font-size:0.74rem;color:var(--on-surface-variant);">Priority: <?= (int)($rule['priority'] ?? 0) ?> · Updated: <?= htmlspecialchars((string)($rule['updated_at'] ?? '-')) ?></div>
          </div>
          <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.78rem;">
            <input type="checkbox" class="rule-toggle" <?= !empty($rule['enabled']) ? 'checked' : '' ?>> Enabled
          </label>
        </div>

        <div style="margin-top:8px;font-size:0.81rem;line-height:1.45;color:var(--on-surface);"><?= htmlspecialchars((string)($rule['intent'] ?? '')) ?></div>

        <details style="margin-top:8px;">
          <summary style="cursor:pointer;font-size:0.78rem;color:var(--on-surface-variant);">Rephrase this rule</summary>
          <textarea class="rule-rephrase" style="margin-top:8px;width:100%;min-height:80px;border:1px solid var(--outline-variant);border-radius:8px;padding:8px;font:inherit;"></textarea>
          <div style="display:flex;justify-content:flex-end;margin-top:8px;">
            <button class="st-btn st-btn-sm rule-rephrase-btn">Save Rephrase</button>
          </div>
        </details>
      </article>
    <?php endforeach; ?>
  </div>
</section>

<script>
  const csrfToken = <?= json_encode(csrf_token()) ?>;
  const apiUrl = 'ai_rulebook_api.php';

  function notice(message, isError = false) {
    const box = document.getElementById('rulebookNotice');
    box.style.display = 'block';
    box.textContent = message;
    box.style.background = isError ? '#fef2f2' : '#eef6ff';
    box.style.borderColor = isError ? '#fecaca' : '#cfe1f5';
    box.style.color = isError ? '#991b1b' : '#1d4f80';
  }

  async function apiCall(payload) {
    const res = await fetch(apiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken
      },
      body: JSON.stringify(payload)
    });
    const json = await res.json();
    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'Request failed');
    }
    return json;
  }

  document.getElementById('teachRuleBtn').addEventListener('click', async () => {
    const text = (document.getElementById('teachRuleText').value || '').trim();
    if (!text) {
      notice('Please enter a rule statement first.', true);
      return;
    }
    try {
      const result = await apiCall({
        action: 'teach_rule',
        text,
        csrf_token: csrfToken
      });
      const added = Number(result.rule_count || 1);
      const label = added > 1 ? `${added} rules` : '1 rule';
      notice(label + ' added or merged and applied immediately. Refreshing…');
      setTimeout(() => location.reload(), 550);
    } catch (e) {
      notice('Teach failed: ' + e.message, true);
    }
  });

  document.getElementById('clearRulesBtn').addEventListener('click', async () => {
    if (!confirm('Reset the rulebook to the default baseline rules?')) {
      return;
    }
    try {
      await apiCall({
        action: 'clear_rules',
        mode: 'reset_defaults',
        csrf_token: csrfToken
      });
      notice('Rulebook reset to defaults. Refreshing…');
      setTimeout(() => location.reload(), 550);
    } catch (e) {
      notice('Reset failed: ' + e.message, true);
    }
  });

  document.getElementById('simulateBtn').addEventListener('click', async () => {
    const out = document.getElementById('simulateOutput');
    out.style.display = 'block';
    try {
      const raw = document.getElementById('simulateFacts').value || '{}';
      const facts = JSON.parse(raw);
      const result = await apiCall({
        action: 'simulate_rule',
        facts,
        csrf_token: csrfToken
      });
      out.textContent = JSON.stringify(result, null, 2);
    } catch (e) {
      out.textContent = 'Simulation failed: ' + e.message;
      notice('Simulation failed. Check JSON syntax and try again.', true);
    }
  });

  document.querySelectorAll('.rule-toggle').forEach((checkbox) => {
    checkbox.addEventListener('change', async (ev) => {
      const card = ev.target.closest('.rule-card');
      const ruleId = card ? card.getAttribute('data-rule-id') : '';
      if (!ruleId) return;
      try {
        await apiCall({
          action: 'toggle_rule',
          rule_id: ruleId,
          enabled: !!ev.target.checked,
          csrf_token: csrfToken
        });
        notice('Rule toggle saved for ' + ruleId + '.');
      } catch (e) {
        ev.target.checked = !ev.target.checked;
        notice('Toggle failed: ' + e.message, true);
      }
    });
  });

  document.querySelectorAll('.rule-rephrase-btn').forEach((btn) => {
    btn.addEventListener('click', async (ev) => {
      const card = ev.target.closest('.rule-card');
      const ruleId = card ? card.getAttribute('data-rule-id') : '';
      const textarea = card ? card.querySelector('.rule-rephrase') : null;
      const text = textarea ? (textarea.value || '').trim() : '';
      if (!ruleId || !text) {
        notice('Please enter a rephrase text before saving.', true);
        return;
      }
      try {
        await apiCall({
          action: 'rephrase_rule',
          rule_id: ruleId,
          text,
          csrf_token: csrfToken
        });
        notice('Rule rephrased and applied immediately. Refreshing…');
        setTimeout(() => location.reload(), 550);
      } catch (e) {
        notice('Rephrase failed: ' + e.message, true);
      }
    });
  });
</script>
