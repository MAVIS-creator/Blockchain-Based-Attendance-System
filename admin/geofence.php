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

<link
  rel="stylesheet"
  href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
  integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
  crossorigin="" />
<style>
  .geo-map-shell {
    position: relative;
    margin-top: 10px;
    border: 1px solid var(--outline-variant);
    border-radius: 14px;
    overflow: hidden;
    background: var(--surface-container-low);
  }

  .geo-map-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(180deg, rgba(246, 250, 255, 0.9), rgba(240, 244, 249, 0.92));
    color: var(--on-surface-variant);
    font-size: 0.9rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    z-index: 2;
    transition: opacity 0.2s ease;
  }

  .geo-map-loading.hidden {
    opacity: 0;
    pointer-events: none;
  }

  .geo-map-canvas {
    height: 330px;
    width: 100%;
    z-index: 0;
  }

  .geo-map-controls {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) auto;
    gap: 14px;
    align-items: center;
    padding: 12px;
    border-top: 1px solid var(--outline-variant);
    background: var(--surface-container-lowest);
  }

  .geo-map-meta {
    color: var(--on-surface-variant);
    font-size: 0.84rem;
    line-height: 1.35;
  }

  .geo-map-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .geo-place-search {
    display: grid;
    grid-template-columns: minmax(0, 1.75fr) auto;
    gap: 10px;
    width: 100%;
  }

  .geo-place-input {
    width: 100%;
    border: 1px solid var(--outline-variant);
    background: var(--surface-container-low);
    color: var(--on-surface);
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 0.92rem;
    min-height: 44px;
  }

  .geo-radius-wrap {
    display: grid;
    gap: 8px;
  }

  .geo-radius-label {
    font-size: 0.82rem;
    color: var(--on-surface-variant);
    font-weight: 600;
  }

  .geo-radius-slider {
    width: 100%;
  }

  @media (max-width: 760px) {
    .geo-map-controls {
      grid-template-columns: 1fr;
      gap: 12px;
    }

    .geo-place-search {
      grid-template-columns: 1fr;
    }

    .geo-place-input {
      min-height: 48px;
      padding: 13px 14px;
      font-size: 0.95rem;
    }
  }
</style>

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
  <div class="st-card" style="grid-column:1/-1;">
    <p style="font-weight:700;color:var(--on-surface);margin:0 0 16px;">Active Geo-fence</p>
    <form method="post" style="display:grid;gap:12px;">
      <?php csrf_field(); ?>
      <label class="flex items-center gap-3">
        <input type="checkbox" name="geo_fence_enabled" value="1" <?= !empty($settings['geo_fence_enabled']) ? 'checked' : '' ?>>
        <span>Enable geo-fence enforcement in `submit.php`</span>
      </label>
      <input id="geo_lat" class="st-input" type="text" name="geo_lat" placeholder="Latitude" value="<?= htmlspecialchars((string)($activeGeo['lat'] ?? '')) ?>">
      <input id="geo_lng" class="st-input" type="text" name="geo_lng" placeholder="Longitude" value="<?= htmlspecialchars((string)($activeGeo['lng'] ?? '')) ?>">
      <input id="geo_radius_m" class="st-input" type="number" min="1" name="geo_radius_m" placeholder="Radius (meters)" value="<?= htmlspecialchars((string)($activeGeo['radius_m'] ?? 0)) ?>">

      <div class="geo-map-shell">
        <div id="geo_map_loading" class="geo-map-loading">Loading map…</div>
        <div id="geo_map_canvas" class="geo-map-canvas"></div>
        <div class="geo-map-controls">
          <div class="geo-radius-wrap">
            <label class="geo-radius-label" for="geo_radius_slider">Radius from map: <strong id="geo_radius_value">0</strong> m</label>
            <input id="geo_radius_slider" class="geo-radius-slider" type="range" min="10" max="1000" step="5" value="100">
            <div class="geo-place-search">
              <input id="geo_place_query" class="geo-place-input" type="text" placeholder="Search place name (e.g. Lagos State University)">
              <button type="button" id="geo_place_search_btn" class="st-btn st-btn-sm st-btn-secondary">Search</button>
            </div>
            <div id="geo_map_status" class="geo-map-meta">Tip: click anywhere on the map to set center point. Radius updates live.</div>
          </div>
          <div class="geo-map-actions">
            <button type="button" id="geo_map_my_location" class="st-btn st-btn-sm st-btn-secondary">Use My Location</button>
            <button type="button" id="geo_map_use_inputs" class="st-btn st-btn-sm st-btn-primary">Center from Inputs</button>
          </div>
        </div>
      </div>

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

<script
  src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
  integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
  crossorigin=""></script>
<script>
  (function() {
    var btn = document.getElementById('geo_test_btn');
    var out = document.getElementById('geo_test_result');
    var lat = document.getElementById('geo_test_lat');
    var lng = document.getElementById('geo_test_lng');
    var geoLatInput = document.getElementById('geo_lat');
    var geoLngInput = document.getElementById('geo_lng');
    var geoRadiusInput = document.getElementById('geo_radius_m');
    var geoRadiusSlider = document.getElementById('geo_radius_slider');
    var geoRadiusValue = document.getElementById('geo_radius_value');
    var geoMapStatus = document.getElementById('geo_map_status');
    var geoMyLocationBtn = document.getElementById('geo_map_my_location');
    var geoUseInputsBtn = document.getElementById('geo_map_use_inputs');
    var geoPlaceQuery = document.getElementById('geo_place_query');
    var geoPlaceSearchBtn = document.getElementById('geo_place_search_btn');
    var geoMapEl = document.getElementById('geo_map_canvas');
    var geoMapLoading = document.getElementById('geo_map_loading');

    if (btn && out && lat && lng) {
      btn.addEventListener('click', function() {
        var body = new URLSearchParams();
        body.append('csrf_token', window.ADMIN_CSRF_TOKEN || '');
        body.append('test_lat', lat.value.trim());
        body.append('test_lng', lng.value.trim());

        out.textContent = 'Running geofence test...';
        fetch('geofence_test.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
          },
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
    }

    if (!geoMapEl || !geoLatInput || !geoLngInput || !geoRadiusInput || !geoRadiusSlider || !window.L) return;

    function parseNum(v, fallback) {
      var n = Number(v);
      return Number.isFinite(n) ? n : fallback;
    }

    var defaultLat = parseNum(geoLatInput.value, 6.5244);
    var defaultLng = parseNum(geoLngInput.value, 3.3792);
    var defaultRadius = Math.max(10, parseNum(geoRadiusInput.value, 120));
    var defaultZoom = 14;

    if (geoMapStatus) {
      geoMapStatus.textContent = 'Loading map tiles… if this stays slow, it is usually your internet connection or the tile server response time.';
    }

    geoRadiusSlider.value = String(defaultRadius);
    geoRadiusValue.textContent = String(defaultRadius);

    var map = L.map('geo_map_canvas', {
      zoomControl: true,
      attributionControl: true
    }).setView([defaultLat, defaultLng], defaultZoom);

    function hideMapLoading() {
      if (!geoMapLoading) return;
      geoMapLoading.classList.add('hidden');
      setTimeout(function() {
        if (geoMapLoading && geoMapLoading.parentNode) {
          geoMapLoading.parentNode.removeChild(geoMapLoading);
        }
      }, 240);
    }

    map.whenReady(function() {
      setTimeout(function() {
        map.invalidateSize();
      }, 0);
    });

    var tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 20,
      updateWhenIdle: true,
      updateWhenZooming: false,
      keepBuffer: 1,
      reuseTiles: true,
      attribution: '&copy; OpenStreetMap contributors'
    });

    tiles.on('load', hideMapLoading);
    tiles.on('tileload', hideMapLoading);
    tiles.on('tileerror', function() {
      if (geoMapStatus) {
        geoMapStatus.textContent = 'Map tiles are taking longer to load. You can still use the map controls.';
      }
      hideMapLoading();
    });

    tiles.addTo(map);

    var marker = L.marker([defaultLat, defaultLng], {
      draggable: true
    }).addTo(map);

    var circle = L.circle([defaultLat, defaultLng], {
      radius: defaultRadius,
      color: '#2563eb',
      fillColor: '#3b82f6',
      fillOpacity: 0.22,
      weight: 2
    }).addTo(map);

    function updateInputsAndMap(latVal, lngVal, radiusVal, moveMap) {
      var latFixed = Number(latVal).toFixed(6);
      var lngFixed = Number(lngVal).toFixed(6);
      var safeRadius = Math.max(10, Math.round(Number(radiusVal) || 10));

      geoLatInput.value = latFixed;
      geoLngInput.value = lngFixed;
      geoRadiusInput.value = String(safeRadius);
      geoRadiusSlider.value = String(safeRadius);
      geoRadiusValue.textContent = String(safeRadius);

      marker.setLatLng([Number(latFixed), Number(lngFixed)]);
      circle.setLatLng([Number(latFixed), Number(lngFixed)]);
      circle.setRadius(safeRadius);

      if (moveMap) {
        map.panTo([Number(latFixed), Number(lngFixed)], {
          animate: true
        });
      }

      if (geoMapStatus) {
        geoMapStatus.textContent = 'Center: ' + latFixed + ', ' + lngFixed + ' • Radius: ' + safeRadius + 'm';
      }
    }

    map.on('click', function(ev) {
      updateInputsAndMap(ev.latlng.lat, ev.latlng.lng, geoRadiusInput.value, false);
    });

    marker.on('dragend', function() {
      var pos = marker.getLatLng();
      updateInputsAndMap(pos.lat, pos.lng, geoRadiusInput.value, false);
    });

    geoRadiusSlider.addEventListener('input', function() {
      updateInputsAndMap(geoLatInput.value, geoLngInput.value, geoRadiusSlider.value, false);
    });

    geoRadiusInput.addEventListener('input', function() {
      updateInputsAndMap(geoLatInput.value, geoLngInput.value, geoRadiusInput.value, false);
    });

    geoLatInput.addEventListener('change', function() {
      updateInputsAndMap(geoLatInput.value, geoLngInput.value, geoRadiusInput.value, true);
    });

    geoLngInput.addEventListener('change', function() {
      updateInputsAndMap(geoLatInput.value, geoLngInput.value, geoRadiusInput.value, true);
    });

    if (geoUseInputsBtn) {
      geoUseInputsBtn.addEventListener('click', function() {
        updateInputsAndMap(geoLatInput.value, geoLngInput.value, geoRadiusInput.value, true);
      });
    }

    function searchPlaceByName() {
      if (!geoPlaceQuery || !geoPlaceSearchBtn) return;

      var query = (geoPlaceQuery.value || '').trim();
      if (!query) {
        if (geoMapStatus) geoMapStatus.textContent = 'Enter a place name first (e.g. Lagos State University).';
        geoPlaceQuery.focus();
        return;
      }

      geoPlaceSearchBtn.disabled = true;
      geoPlaceSearchBtn.textContent = 'Searching...';
      if (geoMapStatus) geoMapStatus.textContent = 'Searching for "' + query + '"...';

      var url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&q=' + encodeURIComponent(query);
      fetch(url, {
        headers: {
          'Accept': 'application/json'
        }
      }).then(function(resp) {
        return resp.json();
      }).then(function(results) {
        if (!Array.isArray(results) || !results.length) {
          if (geoMapStatus) geoMapStatus.textContent = 'No place found for "' + query + '". Try a more specific name.';
          return;
        }

        var hit = results[0] || {};
        var foundLat = parseNum(hit.lat, NaN);
        var foundLng = parseNum(hit.lon, NaN);
        if (!Number.isFinite(foundLat) || !Number.isFinite(foundLng)) {
          if (geoMapStatus) geoMapStatus.textContent = 'Found a place, but coordinates were invalid. Try another result.';
          return;
        }

        updateInputsAndMap(foundLat, foundLng, geoRadiusInput.value, true);
        map.setZoom(Math.max(map.getZoom(), defaultZoom + 1));
        if (geoMapStatus) geoMapStatus.textContent = 'Place found: ' + (hit.display_name || query);
      }).catch(function() {
        if (geoMapStatus) geoMapStatus.textContent = 'Place search failed. Please try again in a moment.';
      }).finally(function() {
        geoPlaceSearchBtn.disabled = false;
        geoPlaceSearchBtn.textContent = 'Search';
      });
    }

    if (geoPlaceSearchBtn) {
      geoPlaceSearchBtn.addEventListener('click', searchPlaceByName);
    }

    if (geoPlaceQuery) {
      geoPlaceQuery.addEventListener('keydown', function(ev) {
        if (ev.key === 'Enter') {
          ev.preventDefault();
          searchPlaceByName();
        }
      });
    }

    if (geoMyLocationBtn && navigator.geolocation) {
      geoMyLocationBtn.addEventListener('click', function() {
        geoMyLocationBtn.disabled = true;
        geoMyLocationBtn.textContent = 'Locating...';

        navigator.geolocation.getCurrentPosition(function(position) {
          updateInputsAndMap(position.coords.latitude, position.coords.longitude, geoRadiusInput.value, true);
          geoMyLocationBtn.disabled = false;
          geoMyLocationBtn.textContent = 'Use My Location';
        }, function() {
          if (geoMapStatus) {
            geoMapStatus.textContent = 'Could not fetch your location. You can still click the map manually.';
          }
          geoMyLocationBtn.disabled = false;
          geoMyLocationBtn.textContent = 'Use My Location';
        }, {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 0
        });
      });
    } else if (geoMyLocationBtn) {
      geoMyLocationBtn.disabled = true;
      geoMyLocationBtn.title = 'Geolocation is not available in this browser.';
    }

    updateInputsAndMap(defaultLat, defaultLng, defaultRadius, false);

    setTimeout(hideMapLoading, 4000);
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
