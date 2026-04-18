<?php
require_once __DIR__ . '/session_bootstrap.php';
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
  <div>
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);margin:0;">Threat Monitor</h2>
    <p style="color:var(--on-surface-variant);font-size:0.9rem;margin:4px 0 0;">Live request-guard abuse telemetry for burst, brute-force, and repeated route abuse.</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;">
    <label style="font-size:0.82rem;color:var(--on-surface-variant);">Window</label>
    <select id="tm-hours" class="st-input" style="width:120px;">
      <option value="1">Last 1 hour</option>
      <option value="6">Last 6 hours</option>
      <option value="24" selected>Last 24 hours</option>
      <option value="72">Last 72 hours</option>
      <option value="168">Last 7 days</option>
    </select>
    <button id="tm-refresh" class="st-btn st-btn-primary st-btn-sm">
      <span class="material-symbols-outlined" style="font-size:1rem;">refresh</span> Refresh
    </button>
  </div>
</div>

<div class="stats" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));margin-bottom:24px;">
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Monitor Events</p>
    <p class="st-stat-value" id="tm-monitor-events">0</p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Blocked Events</p>
    <p class="st-stat-value" id="tm-blocked-events">0</p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Top Risk IPs</p>
    <p class="st-stat-value" id="tm-top-risk-count">0</p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Active Blocks</p>
    <p class="st-stat-value" id="tm-active-blocks">0</p>
  </div>
</div>

<div class="st-card" style="margin-bottom:24px;">
  <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Recommended Immediate Blocks</p>
  <div id="tm-recommended" style="display:grid;gap:8px;"></div>
</div>

<div class="st-card" style="margin-bottom:24px;">
  <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Top IP Offenders</p>
  <div style="overflow:auto;">
    <table class="st-table" style="width:100%;">
      <thead>
        <tr>
          <th>IP</th>
          <th>Score</th>
          <th>Blocked</th>
          <th>Burst</th>
          <th>Samples</th>
          <th>Routes</th>
          <th>Max Count/Limit</th>
          <th>Last Seen (UTC)</th>
        </tr>
      </thead>
      <tbody id="tm-ip-body">
        <tr><td colspan="8" style="text-align:center;color:var(--on-surface-variant);">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="st-card" style="margin-bottom:24px;">
  <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Top IP + Route Pairs</p>
  <div style="overflow:auto;">
    <table class="st-table" style="width:100%;">
      <thead>
        <tr>
          <th>IP</th>
          <th>Route</th>
          <th>Blocked</th>
          <th>Burst</th>
          <th>Total Events</th>
          <th>Last Seen (UTC)</th>
        </tr>
      </thead>
      <tbody id="tm-pair-body">
        <tr><td colspan="6" style="text-align:center;color:var(--on-surface-variant);">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="st-card">
  <p style="font-weight:700;color:var(--on-surface);margin:0 0 12px;">Currently Blocked Buckets</p>
  <div style="overflow:auto;">
    <table class="st-table" style="width:100%;">
      <thead>
        <tr>
          <th>IP</th>
          <th>Route</th>
          <th>Scope</th>
          <th>Count</th>
          <th>Retry After (s)</th>
        </tr>
      </thead>
      <tbody id="tm-block-body">
        <tr><td colspan="5" style="text-align:center;color:var(--on-surface-variant);">Loading…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<script>
  (function() {
    const hoursInput = document.getElementById('tm-hours');
    const refreshBtn = document.getElementById('tm-refresh');

    function fmtTs(epoch) {
      if (!epoch) return '-';
      const d = new Date(epoch * 1000);
      if (isNaN(d.getTime())) return '-';
      return d.toISOString().replace('T', ' ').replace('.000Z', '');
    }

    function renderRecommended(topIps) {
      const box = document.getElementById('tm-recommended');
      const candidates = (topIps || []).filter(r => (r.blocked_events || 0) >= 3 || (r.burst_events || 0) >= 2 || (r.score || 0) >= 15).slice(0, 10);
      if (!candidates.length) {
        box.innerHTML = '<p style="margin:0;color:var(--on-surface-variant);">No high-risk candidates in selected window yet.</p>';
        return;
      }

      box.innerHTML = candidates.map(r => {
        const routes = (r.routes || []).slice(0, 3).join(', ');
        return `<div style="border:1px solid var(--outline-variant);border-radius:10px;padding:10px;background:var(--surface-container-low);">
          <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;">
            <strong>${r.ip}</strong>
            <span class="st-chip" style="background:#fef2f2;color:#b91c1c;">score ${r.score}</span>
          </div>
          <div style="font-size:0.82rem;color:var(--on-surface-variant);margin-top:6px;">blocked=${r.blocked_events}, burst=${r.burst_events}, samples=${r.request_samples}, routes=${r.route_count}</div>
          <div style="font-size:0.8rem;color:var(--on-surface-variant);margin-top:4px;">${routes || '-'}</div>
        </div>`;
      }).join('');
    }

    function renderTableRows(id, rows, columns, emptyText) {
      const body = document.getElementById(id);
      if (!rows || !rows.length) {
        body.innerHTML = `<tr><td colspan="${columns}" style="text-align:center;color:var(--on-surface-variant);">${emptyText}</td></tr>`;
        return;
      }
      body.innerHTML = rows.join('');
    }

    async function loadThreatData() {
      const hours = parseInt(hoursInput.value || '24', 10);
      refreshBtn.disabled = true;
      try {
        const res = await fetch(`request_guard_monitor_api.php?hours=${encodeURIComponent(hours)}`, { cache: 'no-store' });
        const data = await res.json();
        if (!data || !data.ok) {
          throw new Error('Threat monitor API response invalid');
        }

        document.getElementById('tm-monitor-events').textContent = Number(data.monitor_events || 0).toLocaleString();
        document.getElementById('tm-blocked-events').textContent = Number(data.blocked_events || 0).toLocaleString();
        document.getElementById('tm-top-risk-count').textContent = Number((data.top_ips || []).length).toLocaleString();
        document.getElementById('tm-active-blocks').textContent = Number((data.active_blocks || []).length).toLocaleString();

        renderRecommended(data.top_ips || []);

        const ipRows = (data.top_ips || []).slice(0, 20).map(r => `<tr>
          <td style="font-weight:600;">${r.ip || '-'}</td>
          <td>${r.score || 0}</td>
          <td>${r.blocked_events || 0}</td>
          <td>${r.burst_events || 0}</td>
          <td>${r.request_samples || 0}</td>
          <td>${r.route_count || 0}</td>
          <td>${r.max_count_seen || 0}/${r.max_limit_seen || 0}</td>
          <td>${fmtTs(r.last_seen)}</td>
        </tr>`);
        renderTableRows('tm-ip-body', ipRows, 8, 'No IP telemetry in selected window.');

        const pairRows = (data.top_ip_routes || []).slice(0, 25).map(r => `<tr>
          <td style="font-weight:600;">${r.ip || '-'}</td>
          <td>${r.route || '-'}</td>
          <td>${r.blocked_events || 0}</td>
          <td>${r.burst_events || 0}</td>
          <td>${r.events || 0}</td>
          <td>${fmtTs(r.last_seen)}</td>
        </tr>`);
        renderTableRows('tm-pair-body', pairRows, 6, 'No IP+route telemetry in selected window.');

        const blockRows = (data.active_blocks || []).slice(0, 40).map(r => `<tr>
          <td style="font-weight:600;">${r.ip || '-'}</td>
          <td>${r.route || '-'}</td>
          <td>${r.scope || '-'}</td>
          <td>${r.count || 0}</td>
          <td>${r.retry_after || 0}</td>
        </tr>`);
        renderTableRows('tm-block-body', blockRows, 5, 'No active request guard blocks right now.');
      } catch (err) {
        renderTableRows('tm-ip-body', [], 8, 'Failed to load threat telemetry.');
        renderTableRows('tm-pair-body', [], 6, 'Failed to load threat telemetry.');
        renderTableRows('tm-block-body', [], 5, 'Failed to load threat telemetry.');
      } finally {
        refreshBtn.disabled = false;
      }
    }

    refreshBtn.addEventListener('click', loadThreatData);
    hoursInput.addEventListener('change', loadThreatData);

    loadThreatData();
    setInterval(loadThreatData, 15000);
  })();
</script>
