<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_logged_in'])) {
  header('Location: login.php');
  exit;
}
if (($_SESSION['admin_role'] ?? 'admin') !== 'superadmin') {
  echo '<div style="padding:20px;"><h2>Access denied</h2><p>You do not have permission to view this page.</p></div>';
  return;
}

require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/includes/csrf.php';
csrf_token();

$settingsFile = admin_storage_migrate_file('settings.json');
$keyFile = admin_storage_migrate_file('.settings_key');

function geofence_load_settings($settingsFile, $keyFile)
{
  if (!file_exists($settingsFile)) return [];
  $raw = file_get_contents($settingsFile);
  $decoded = json_decode($raw, true);
  if (is_array($decoded)) return $decoded;

  if (strpos($raw, 'ENC:') === 0 && file_exists($keyFile)) {
    $key = trim(file_get_contents($keyFile));
    $blob = base64_decode(substr($raw, 4));
    $iv = substr($blob, 0, 16);
    $ct = substr($blob, 16);
    $plain = openssl_decrypt($ct, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
    $decoded = json_decode((string)$plain, true);
    if (is_array($decoded)) return $decoded;
  }

  return [];
}

function geofence_save_settings($settingsFile, $keyFile, array $settings)
{
  $raw = file_exists($settingsFile) ? file_get_contents($settingsFile) : '';
  $encrypt = strpos((string)$raw, 'ENC:') === 0 || !empty($settings['encrypted_settings']);
  $payload = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

  if ($encrypt) {
    if (!file_exists($keyFile)) {
      $generated = base64_encode(random_bytes(32));
      file_put_contents($keyFile, $generated, LOCK_EX);
      @chmod($keyFile, 0600);
    }
    $key = trim(file_get_contents($keyFile));
    $iv = random_bytes(16);
    $ct = openssl_encrypt($payload, 'AES-256-CBC', base64_decode($key), OPENSSL_RAW_DATA, $iv);
    $payload = 'ENC:' . base64_encode($iv . $ct);
  }

  file_put_contents($settingsFile, $payload, LOCK_EX);
}

$settings = geofence_load_settings($settingsFile, $keyFile);
$settings = array_replace([
  'geo_fence_enabled' => false,
  'geo_fence' => ['lat' => '', 'lng' => '', 'radius_m' => 0],
  'geo_landmarks' => [],
], $settings);

if (!is_array($settings['geo_landmarks'])) {
  $settings['geo_landmarks'] = [];
}

$message = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_check_request()) {
    $errors[] = 'Invalid CSRF token.';
  } else {
    if (isset($_POST['save_geofence'])) {
      $enabled = isset($_POST['geo_fence_enabled']) && $_POST['geo_fence_enabled'] === '1';
      $lat = trim((string)($_POST['geo_lat'] ?? ''));
      $lng = trim((string)($_POST['geo_lng'] ?? ''));
      $radius = (int)($_POST['geo_radius_m'] ?? 0);

      if ($enabled) {
        if ($lat === '' || !is_numeric($lat)) $errors[] = 'Latitude must be a valid number.';
        if ($lng === '' || !is_numeric($lng)) $errors[] = 'Longitude must be a valid number.';
        if ($radius <= 0) $errors[] = 'Radius must be greater than 0 meters.';
      }

      if (empty($errors)) {
        $settings['geo_fence_enabled'] = $enabled;
        $settings['geo_fence'] = [
          'lat' => $lat === '' ? null : (float)$lat,
          'lng' => $lng === '' ? null : (float)$lng,
          'radius_m' => max(0, $radius),
        ];
        geofence_save_settings($settingsFile, $keyFile, $settings);
        $message = 'Geo-fence settings saved.';
      }
    }

    if (isset($_POST['save_landmark'])) {
      $name = trim((string)($_POST['landmark_name'] ?? ''));
      $lat = trim((string)($_POST['landmark_lat'] ?? ''));
      $lng = trim((string)($_POST['landmark_lng'] ?? ''));
      $radius = (int)($_POST['landmark_radius_m'] ?? 0);
      $notes = trim((string)($_POST['landmark_notes'] ?? ''));

      if ($name === '') $errors[] = 'Landmark name is required.';
      if ($lat === '' || !is_numeric($lat)) $errors[] = 'Landmark latitude must be a valid number.';
      if ($lng === '' || !is_numeric($lng)) $errors[] = 'Landmark longitude must be a valid number.';
      if ($radius <= 0) $errors[] = 'Landmark radius must be greater than 0 meters.';

      if (empty($errors)) {
        $settings['geo_landmarks'][] = [
          'id' => 'lm_' . bin2hex(random_bytes(6)),
          'name' => $name,
          'lat' => (float)$lat,
          'lng' => (float)$lng,
          'radius_m' => $radius,
          'notes' => $notes,
          'created_at' => date('c'),
        ];
        geofence_save_settings($settingsFile, $keyFile, $settings);
        $message = 'Landmark saved.';
      }
    }

    if (isset($_POST['apply_landmark'])) {
      $landmarkId = trim((string)($_POST['landmark_id'] ?? ''));
      foreach ($settings['geo_landmarks'] as $landmark) {
        if (($landmark['id'] ?? '') === $landmarkId) {
          $settings['geo_fence'] = [
            'lat' => (float)$landmark['lat'],
            'lng' => (float)$landmark['lng'],
            'radius_m' => (int)$landmark['radius_m'],
          ];
          if (isset($_POST['enable_after_apply'])) {
            $settings['geo_fence_enabled'] = true;
          }
          geofence_save_settings($settingsFile, $keyFile, $settings);
          $message = 'Landmark applied to active geo-fence.';
          break;
        }
      }
      if ($message === '' && empty($errors)) {
        $errors[] = 'Landmark not found.';
      }
    }

    if (isset($_POST['delete_landmark'])) {
      $landmarkId = trim((string)($_POST['landmark_id'] ?? ''));
      $settings['geo_landmarks'] = array_values(array_filter($settings['geo_landmarks'], function ($landmark) use ($landmarkId) {
        return ($landmark['id'] ?? '') !== $landmarkId;
      }));
      geofence_save_settings($settingsFile, $keyFile, $settings);
      $message = 'Landmark deleted.';
    }
  }
}

$activeGeo = is_array($settings['geo_fence']) ? $settings['geo_fence'] : ['lat' => '', 'lng' => '', 'radius_m' => 0];
?>

<div style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
  <div>
    <h2 style="font-size:1.5rem;font-weight:800;color:var(--on-surface);margin:0;">Geo-fence Manager</h2>
    <p style="color:var(--on-surface-variant);font-size:0.88rem;margin:4px 0 0;">Manage the active geo-fence, save landmarks, and test distance checks against the same settings used by `submit.php`.</p>
  </div>
</div>

<div class="stats" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));margin-bottom:24px;">
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Geo-fence</p>
    <p class="st-stat-value"><?= !empty($settings['geo_fence_enabled']) ? 'Enabled' : 'Disabled' ?></p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Active Radius</p>
    <p class="st-stat-value"><?= number_format((int)($activeGeo['radius_m'] ?? 0)) ?> m</p>
  </div>
  <div class="stat" style="text-align:left;border-top:none;">
    <p class="st-stat-label">Saved Landmarks</p>
    <p class="st-stat-value"><?= number_format(count($settings['geo_landmarks'])) ?></p>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:20px;">
  <div class="st-card">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;">Active Geo-fence</p>
    <form method="post" style="display:grid;gap:12px;">
      <?php csrf_field(); ?>
      <label class="flex items-center gap-3">
        <input type="checkbox" name="geo_fence_enabled" value="1" <?= !empty($settings['geo_fence_enabled']) ? 'checked' : '' ?>>
        <span>Enable geo-fence enforcement in `submit.php`</span>
      </label>
      <input class="st-input" type="text" name="geo_lat" placeholder="Latitude" value="<?= htmlspecialchars((string)($activeGeo['lat'] ?? '')) ?>">
      <input class="st-input" type="text" name="geo_lng" placeholder="Longitude" value="<?= htmlspecialchars((string)($activeGeo['lng'] ?? '')) ?>">
      <input class="st-input" type="number" min="1" name="geo_radius_m" placeholder="Radius (meters)" value="<?= htmlspecialchars((string)($activeGeo['radius_m'] ?? 0)) ?>">
      <button type="submit" name="save_geofence" value="1" class="st-btn st-btn-primary">
        <span class="material-symbols-outlined" style="font-size:1rem;">save</span> Save Active Geo-fence
      </button>
    </form>
  </div>

  <div class="st-card">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;">Save Landmark</p>
    <form method="post" style="display:grid;gap:12px;">
      <?php csrf_field(); ?>
      <input class="st-input" type="text" name="landmark_name" placeholder="Landmark name">
      <input class="st-input" type="text" name="landmark_lat" placeholder="Latitude">
      <input class="st-input" type="text" name="landmark_lng" placeholder="Longitude">
      <input class="st-input" type="number" min="1" name="landmark_radius_m" placeholder="Default radius (meters)">
      <textarea class="st-input" name="landmark_notes" placeholder="Optional note to remember this place"></textarea>
      <button type="submit" name="save_landmark" value="1" class="st-btn st-btn-secondary">
        <span class="material-symbols-outlined" style="font-size:1rem;">add_location</span> Save Landmark
      </button>
    </form>
  </div>

  <div class="st-card" style="grid-column:1/-1;">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;">Test Current Geo-fence</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;align-items:end;">
      <input class="st-input" id="geo_test_lat" type="text" placeholder="Test latitude">
      <input class="st-input" id="geo_test_lng" type="text" placeholder="Test longitude">
      <button id="geo_test_btn" type="button" class="st-btn st-btn-primary">Run Test</button>
    </div>
    <div id="geo_test_result" style="margin-top:12px;color:var(--on-surface-variant);font-size:0.9rem;"></div>
  </div>

  <div class="st-card" style="grid-column:1/-1;">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;">Saved Landmarks</p>
    <?php if (!empty($settings['geo_landmarks'])): ?>
      <div style="display:grid;gap:12px;">
        <?php foreach (array_reverse($settings['geo_landmarks']) as $landmark): ?>
          <div style="border:1px solid var(--outline-variant);border-radius:12px;padding:14px;background:var(--surface-container-low);">
            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start;">
              <div>
                <strong><?= htmlspecialchars((string)$landmark['name']) ?></strong>
                <div style="font-size:0.85rem;color:var(--on-surface-variant);margin-top:4px;">
                  <?= htmlspecialchars((string)$landmark['lat']) ?>, <?= htmlspecialchars((string)$landmark['lng']) ?> • <?= number_format((int)$landmark['radius_m']) ?> m
                </div>
                <?php if (!empty($landmark['notes'])): ?>
                  <div style="font-size:0.85rem;color:var(--on-surface-variant);margin-top:6px;"><?= htmlspecialchars((string)$landmark['notes']) ?></div>
                <?php endif; ?>
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="post" style="margin:0;">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="landmark_id" value="<?= htmlspecialchars((string)$landmark['id']) ?>">
                  <label style="display:inline-flex;align-items:center;gap:6px;font-size:0.8rem;margin-right:8px;">
                    <input type="checkbox" name="enable_after_apply" value="1" checked> Enable
                  </label>
                  <button type="submit" name="apply_landmark" value="1" class="st-btn st-btn-sm st-btn-primary">Apply</button>
                </form>
                <form method="post" style="margin:0;">
                  <?php csrf_field(); ?>
                  <input type="hidden" name="landmark_id" value="<?= htmlspecialchars((string)$landmark['id']) ?>">
                  <button type="submit" name="delete_landmark" value="1" class="st-btn st-btn-sm st-btn-danger">Delete</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="margin:0;color:var(--on-surface-variant);">No landmarks saved yet.</p>
    <?php endif; ?>
  </div>
</div>

<script>
  (function() {
    var btn = document.getElementById('geo_test_btn');
    var out = document.getElementById('geo_test_result');
    var lat = document.getElementById('geo_test_lat');
    var lng = document.getElementById('geo_test_lng');
    if (!btn || !out || !lat || !lng) return;

    btn.addEventListener('click', function() {
      var body = new URLSearchParams();
      body.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');
      body.append('test_lat', lat.value.trim());
      body.append('test_lng', lng.value.trim());

      out.textContent = 'Running geofence test...';
      fetch('geofence_test.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
      }).then(function(r) {
        return r.json();
      }).then(function(data) {
        if (!data || !data.ok) {
          out.style.color = '#b91c1c';
          out.textContent = 'Test failed: ' + ((data && data.message) ? data.message : 'Unknown error');
          return;
        }

        if (data.enforced === false) {
          out.style.color = '#0369a1';
          out.textContent = 'Geo-fence is disabled, so submit.php will not reject by location.';
          return;
        }

        out.style.color = data.inside ? '#166534' : '#b91c1c';
        out.textContent = (data.inside ? 'Inside' : 'Outside') + ' geo-fence. Distance: ' + data.distance_m + 'm. Radius: ' + data.radius_m + 'm.';
      }).catch(function() {
        out.style.color = '#b91c1c';
        out.textContent = 'Request error while testing geo-fence.';
      });
    });
  })();
</script>
<?php if ($message !== '' || !empty($errors)): ?>
<script>
  window.adminAlert(
    <?= json_encode($message !== '' ? 'Success' : 'Action failed') ?>,
    <?= json_encode($message !== '' ? $message : implode("\n", $errors)) ?>,
    <?= json_encode($message !== '' ? 'success' : 'error') ?>
  );
</script>
<?php endif; ?>
