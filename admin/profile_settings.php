<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/runtime_storage.php';
require_once __DIR__ . '/state_helpers.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$user = $_SESSION['admin_user'] ?? '';
$accountsFile = admin_accounts_file();
$accounts = admin_load_accounts_cached(15);

function admin_avatar_url($storedValue)
{
    $storedValue = trim((string)$storedValue);
    if ($storedValue === '') {
        return null;
    }
    if (strpos($storedValue, 'asset/') === 0 || strpos($storedValue, 'http') === 0 || strpos($storedValue, 'admin/avatar.php') === 0) {
        return $storedValue;
    }
    return 'avatar.php?file=' . rawurlencode($storedValue);
}

// ==========================================
// API ENDPOINT LOGIC (POST REQUESTS)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!function_exists('csrf_check_request') || !csrf_check_request()) {
        echo json_encode(['status' => 'error', 'message' => 'CSRF verification failed']);
        exit;
    }

    if (!isset($accounts[$user])) {
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit;
    }

    if (isset($_POST['update_identity'])) {
        $newName = trim($_POST['name'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');

        if (empty($newName)) {
            echo json_encode(['status' => 'error', 'message' => 'Name cannot be empty']);
            exit;
        }

        $accounts[$user]['name'] = $newName;
        $_SESSION['admin_name'] = $newName;

        if (!empty($newEmail)) {
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid email address']);
                exit;
            }
            $accounts[$user]['email'] = $newEmail;
            $_SESSION['admin_email'] = $newEmail;
        }

        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === 0) {
            $file = $_FILES['avatar'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (in_array($file['type'], $allowedTypes)) {
                $maxSize = 5 * 1024 * 1024;
                if ($file['size'] <= $maxSize) {
                    $fileName = 'avatar_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $user) . '_' . time() . '.jpg';
                    $uploadPath = admin_storage_file('avatars');
                    if (!file_exists($uploadPath)) mkdir($uploadPath, 0775, true);

                    if (move_uploaded_file($file['tmp_name'], $uploadPath . DIRECTORY_SEPARATOR . $fileName)) {
                        if (!empty($accounts[$user]['avatar'])) {
                            $oldAvatar = (string)$accounts[$user]['avatar'];
                            if (strpos($oldAvatar, 'asset/avatars/') === 0) {
                                $oldAvatarAbs = realpath(__DIR__ . '/../' . $oldAvatar);
                            } else {
                                $oldAvatarAbs = admin_storage_file('avatars/' . basename($oldAvatar));
                            }
                            if ($oldAvatarAbs && file_exists($oldAvatarAbs)) unlink($oldAvatarAbs);
                        }
                        $accounts[$user]['avatar'] = $fileName;
                        $_SESSION['admin_avatar'] = admin_avatar_url($fileName);
                    }
                } else {
                    echo json_encode(['status' => 'error', 'message' => 'File is too large (max 5MB)']);
                    exit;
                }
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Please upload JPG, PNG or GIF']);
                exit;
            }
        }

        file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT));
        echo json_encode(['status' => 'success', 'message' => 'Identity updated successfully']);
        exit;
    }

    if (isset($_POST['update_password'])) {
        $currentPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';

        if (empty($currentPass) || empty($newPass)) {
            echo json_encode(['status' => 'error', 'message' => 'Missing password fields']);
            exit;
        }

        if (!password_verify($currentPass, $accounts[$user]['password'])) {
            echo json_encode(['status' => 'error', 'message' => 'Current password is incorrect']);
            exit;
        }

        $accounts[$user]['password'] = password_hash($newPass, PASSWORD_DEFAULT);
        file_put_contents($accountsFile, json_encode($accounts, JSON_PRETTY_PRINT));

        echo json_encode(['status' => 'success', 'message' => 'Security updated successfully']);
        exit;
    }

    if (isset($_POST['check_sessions'])) {
        $sessionsFile = admin_sessions_file();
        $activeCount = 0;
        if (file_exists($sessionsFile)) {
            $activeSessions = admin_load_sessions_cached(10);
            if (is_array($activeSessions)) {
                foreach ($activeSessions as $sid => $sessData) {
                    if ($sessData['user'] === $user) {
                        $activeCount++;
                    }
                }
            }
        }
        echo json_encode(['status' => 'success', 'count' => $activeCount]);
        exit;
    }

    if (isset($_POST['terminate_sessions'])) {
        $sessionsFile = admin_sessions_file();
        if (file_exists($sessionsFile)) {
            $activeSessions = admin_load_sessions_cached(10);
            if (is_array($activeSessions)) {
                $currentSessionId = session_id();
                foreach ($activeSessions as $sid => $sessData) {
                    if ($sid !== $currentSessionId && $sessData['user'] === $user) {
                        unset($activeSessions[$sid]);
                    }
                }
                admin_write_json_atomic($sessionsFile, $activeSessions);
            }
        }
        echo json_encode(['status' => 'success', 'message' => 'Other sessions terminated.']);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

// ==========================================
// RENDER HTML (GET REQUESTS)
// ==========================================
// We're included within index.php, so just render the markup!
$currentUser = $accounts[$user] ?? [];
$emailAddr = $currentUser['email'] ?? '';
if (!empty($currentUser['avatar'])) {
    $_SESSION['admin_avatar'] = admin_avatar_url($currentUser['avatar']);
}
?>

<style>
    .profile-modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 22000;
        background: rgba(8, 17, 30, 0.6);
        backdrop-filter: blur(5px);
        -webkit-backdrop-filter: blur(5px);
        padding: 18px;
        overflow-y: auto;
    }

    .profile-modal-dialog {
        width: min(1240px, 100%);
        margin: 0 auto;
        background: var(--surface-container-lowest);
        border: 1px solid var(--outline-variant);
        border-radius: 18px;
        box-shadow: var(--shadow-elevated);
        overflow: hidden;
    }

    .profile-modal-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 18px 22px;
        border-bottom: 1px solid var(--outline-variant);
        background: linear-gradient(135deg, var(--primary-fixed), var(--surface-container-low));
    }

    .profile-modal-close {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border-radius: 10px;
        border: 1px solid var(--outline-variant);
        background: var(--surface-container-lowest);
        color: var(--on-surface-variant);
        cursor: pointer;
    }

    .profile-modal-content {
        padding: 20px;
    }

    .profile-grid {
        display: grid;
        grid-template-columns: minmax(400px, 1.2fr) minmax(350px, 0.8fr);
        gap: 24px;
    }

    @media (max-width: 980px) {
        .profile-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 640px) {
        .profile-modal-overlay {
            padding: 10px;
        }

        .profile-modal-header {
            padding: 14px;
        }

        .profile-modal-content {
            padding: 12px;
        }
    }
</style>

<div class="profile-modal-overlay" id="profileSettingsModalRoot">
    <div class="profile-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
        <div class="profile-modal-header">
            <div>
                <h2 id="profileModalTitle" class="page-title" style="font-size: 1.45rem; font-weight: 900; letter-spacing: -0.02em; margin:0;">Account Architecture</h2>
                <p class="page-subtitle" style="color: var(--on-surface-variant); font-size: 0.9rem; margin: 4px 0 0;">Configure administrative identity and security parameters.</p>
            </div>
            <button type="button" class="profile-modal-close" onclick="window.location.href='index.php?page=dashboard'" aria-label="Close profile settings">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <div class="profile-modal-content">
            <div class="profile-grid">

                <!-- LEFT COLUMN -->
                <div style="display:flex; flex-direction:column; gap:32px;">

                    <!-- Card 1: Identity -->
                    <div style="border: 1px solid var(--outline-variant); border-radius: 16px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px;">
                            <h3 style="margin:0; font-size:1.05rem; font-weight:700; display:flex; align-items:center; gap:8px; color: var(--on-surface);">
                                <span class="material-symbols-outlined" style="color:var(--primary);">person</span>
                                Administrative Identity
                            </h3>
                            <span style="background:var(--primary-container); color:var(--on-primary-container); font-size:0.65rem; font-weight:800; padding:6px 12px; border-radius:12px; letter-spacing:0.05em; text-transform:uppercase;"><?= htmlspecialchars($_SESSION['admin_role'] === 'superadmin' ? 'PRIMARY NODE' : 'SECONDARY NODE') ?></span>
                        </div>

                        <form id="identityForm" style="display:flex; gap: 24px; flex-wrap: wrap;">
                            <!-- Avatar -->
                            <div style="display:flex; flex-direction:column; align-items:center; gap:12px; flex-shrink: 0;">
                                <?php
                                $avatarUrl = $_SESSION['admin_avatar'] ?? admin_avatar_url($currentUser['avatar'] ?? '');
                                $bgClass = $avatarUrl ? 'transparent' : 'var(--primary-container)';
                                ?>
                                <div style="width: 110px; height: 110px; background: <?= $bgClass ?>; border-radius: 16px; position:relative; display:flex; align-items:center; justify-content:center;">
                                    <?php if ($avatarUrl): ?>
                                        <img id="avatarPreview" src="<?= htmlspecialchars($avatarUrl) ?>" alt="Avatar" style="width:100%; height:100%; object-fit:cover; border-radius:16px;">
                                    <?php else: ?>
                                        <span class="material-symbols-outlined" style="font-size:3.5rem; color:var(--on-primary-container); opacity:0.6;">account_circle</span>
                                        <img id="avatarPreview" src="" alt="" style="display:none; width:100%; height:100%; object-fit:cover; border-radius:16px; position:absolute; top:0; left:0;">
                                    <?php endif; ?>
                                    <label for="avatar_upload" style="position:absolute; bottom:-10px; right:-10px; background:var(--primary); color:white; width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; cursor:pointer; box-shadow:0 4px 12px rgba(0,0,0,0.15); border:2px solid #fff; transition: transform 0.2s;">
                                        <span class="material-symbols-outlined" style="font-size:1.1rem;">photo_camera</span>
                                    </label>
                                    <input type="file" id="avatar_upload" name="avatar" accept="image/png, image/jpeg, image/gif" style="display:none;" onchange="previewAvatar(this)">
                                </div>
                                <span style="font-size:0.65rem; color:var(--on-surface-variant); font-weight:800; letter-spacing:0.04em; text-transform:uppercase;">MAX 5MB &bull; PNG/JPG</span>
                            </div>

                            <!-- Fields -->
                            <div style="flex:1; display:flex; flex-direction:column; gap:16px; min-width: 200px;">
                                <div style="display:flex; gap: 16px; flex-wrap: wrap;">
                                    <div style="flex:1; min-width: 120px;">
                                        <label class="st-label" style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--on-surface-variant);">DISPLAY NAME</label>
                                        <input type="text" id="prof_name" class="st-input" value="<?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?>" style="font-weight:600;">
                                    </div>
                                    <div style="flex:1; min-width: 120px;">
                                        <label class="st-label" style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--outline);">ADMINISTRATIVE ROLE</label>
                                        <input type="text" class="st-input" value="<?= htmlspecialchars(ucwords(str_replace('_', ' ', $_SESSION['admin_role'] ?? 'Admin'))) ?>" disabled style="background:rgba(0,0,0,0.02); color:var(--outline); font-weight:600;">
                                    </div>
                                </div>
                                <div>
                                    <label class="st-label" style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--on-surface-variant);">RECOVERY EMAIL ADDRESS</label>
                                    <input type="email" id="prof_email" class="st-input" value="<?= htmlspecialchars($emailAddr) ?>" placeholder="admin@attendancesystem.com" style="font-weight:500;">
                                </div>
                                <div style="display:flex; justify-content:flex-end; gap:12px; margin-top: 8px;">
                                    <button type="button" class="st-btn st-btn-text" style="color:var(--on-surface-variant); font-weight:600;" onclick="window.location.reload()">Discard</button>
                                    <button type="button" id="saveIdentityBtn" class="st-btn st-btn-primary" style="font-weight:600;" onclick="saveIdentity()">Save Identity</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Card 2: Security -->
                    <div style="border: 1px solid var(--outline-variant); border-radius: 16px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.02);">
                        <h3 style="margin:0 0 24px 0; font-size:1.05rem; font-weight:700; display:flex; align-items:center; gap:8px; color: var(--on-surface);">
                            <span class="material-symbols-outlined" style="color:var(--primary);">shield</span>
                            Security Protocol
                        </h3>
                        <form id="securityForm" style="display:flex; flex-direction:column; gap: 16px;">
                            <div style="display:flex; gap: 16px; flex-wrap: wrap;">
                                <div style="flex:1; min-width: 100px;">
                                    <label class="st-label" style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--on-surface-variant);">Current Password</label>
                                    <input type="password" id="prof_old_pass" class="st-input" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" style="letter-spacing: 2px;">
                                </div>
                                <div style="flex:1; min-width: 100px;">
                                    <label class="st-label" style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--on-surface-variant);">New Password</label>
                                    <input type="password" id="prof_new_pass" class="st-input" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" style="letter-spacing: 2px;">
                                </div>
                                <div style="flex:1; min-width: 100px;">
                                    <label class="st-label" style="font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; color:var(--on-surface-variant);">Confirm Password</label>
                                    <input type="password" id="prof_conf_pass" class="st-input" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;" style="letter-spacing: 2px;">
                                </div>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top: 12px; flex-wrap: wrap; gap: 12px;">
                                <span style="font-size:0.85rem; color:var(--on-surface-variant); font-weight:500;">Ensure a strong password sequence.</span>
                                <button type="button" id="updateSecBtn" class="st-btn st-btn-secondary" style="background:var(--surface); border:1px solid var(--outline-variant); color:var(--primary); font-weight:700;" onclick="saveSecurity()">Update Security</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- RIGHT COLUMN -->
                <div style="display:flex; flex-direction:column; gap:32px;">

                    <!-- Card 3: Access Logs -->
                    <div style="border: 1px solid var(--outline-variant); border-radius: 16px; padding: 24px; box-shadow: 0 4px 12px rgba(0,0,0,0.02); display:flex; flex-direction:column; flex: 1;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 24px;">
                            <h3 style="margin:0; font-size:0.95rem; font-weight:800; letter-spacing:0.05em; text-transform:uppercase; display:flex; align-items:center; gap:8px; color:var(--on-surface-variant);">
                                <span class="material-symbols-outlined" style="font-size:1.2rem;">history</span> ACTIVE SESSIONS
                            </h3>
                        </div>

                        <div style="display:flex; flex-direction:column; gap:16px; flex:1;">
                            <?php
                            $sessionsFile = admin_sessions_file();
                            $mySessions = [];
                            if (file_exists($sessionsFile)) {
                                $allSess = admin_load_sessions_cached(10);
                                if (is_array($allSess)) {
                                    foreach ($allSess as $sid => $sessData) {
                                        if (isset($sessData['user']) && $sessData['user'] === $user) {
                                            $sessData['is_current'] = ($sid === session_id());
                                            $mySessions[] = $sessData;
                                        }
                                    }
                                }
                            }
                            // Sort: current first, then newest
                            usort($mySessions, function ($a, $b) {
                                if ($a['is_current']) return -1;
                                if ($b['is_current']) return 1;
                                return $b['last_activity'] <=> $a['last_activity'];
                            });

                            if (empty($mySessions)):
                                // Fallback if session file missing/empty
                            ?>
                                <!-- Active Session Fallback -->
                                <div class="session-item" style="display:flex; align-items:center; gap:16px; padding:16px; border-radius:12px; background:var(--surface-container-high); border:1px solid var(--primary-container);">
                                    <div style="width:40px; height:40px; flex-shrink: 0; border-radius:10px; background:var(--primary-container); color:var(--on-primary-container); display:flex; align-items:center; justify-content:center;">
                                        <span class="material-symbols-outlined" style="font-size:1.2rem;"><?= strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false ? 'smartphone' : 'laptop_mac' ?></span>
                                    </div>
                                    <div style="flex:1;">
                                        <div style="font-size:0.95rem; font-weight:700; color:var(--on-surface);">Current Session</div>
                                        <div style="font-size:0.8rem; color:var(--on-surface-variant); margin-top:2px;">Browser &bull; <?= $_SERVER['REMOTE_ADDR'] ?></div>
                                    </div>
                                    <div style="font-size:0.65rem; font-weight:800; color:var(--primary); letter-spacing:0.05em; background:var(--surface); padding: 4px 8px; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">ACTIVE</div>
                                </div>
                                <?php else:
                                foreach ($mySessions as $s):
                                    $isMobile = strpos($s['user_agent'] ?? '', 'Mobile') !== false;
                                    $icon = $isMobile ? 'smartphone' : 'laptop_mac';
                                    if ($s['is_current']):
                                ?>
                                        <!-- Active Session -->
                                        <div class="session-item" style="display:flex; align-items:center; gap:16px; padding:16px; border-radius:12px; background:var(--surface-container-high); border:1px solid var(--primary-container);">
                                            <div style="width:40px; height:40px; flex-shrink: 0; border-radius:10px; background:var(--primary-container); color:var(--on-primary-container); display:flex; align-items:center; justify-content:center;">
                                                <span class="material-symbols-outlined" style="font-size:1.2rem;"><?= $icon ?></span>
                                            </div>
                                            <div style="flex:1;">
                                                <div style="font-size:0.95rem; font-weight:700; color:var(--on-surface);">Current Session</div>
                                                <div style="font-size:0.8rem; color:var(--on-surface-variant); margin-top:2px;">Browser &bull; <?= htmlspecialchars($s['ip'] ?? 'Unknown IP') ?></div>
                                            </div>
                                            <div style="font-size:0.65rem; font-weight:800; color:var(--primary); letter-spacing:0.05em; background:var(--surface); padding: 4px 8px; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">ACTIVE</div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Previous Session -->
                                        <div class="session-item" style="display:flex; align-items:center; gap:16px; padding:16px; border-radius:12px; background:transparent; border:1px solid var(--outline-variant);">
                                            <div style="width:40px; height:40px; flex-shrink: 0; border-radius:10px; background:var(--surface-container); color:var(--on-surface-variant); display:flex; align-items:center; justify-content:center;">
                                                <span class="material-symbols-outlined" style="font-size:1.2rem;"><?= $icon ?></span>
                                            </div>
                                            <div style="flex:1;">
                                                <div style="font-size:0.95rem; font-weight:700; color:var(--on-surface);">System Access</div>
                                                <div style="font-size:0.8rem; color:var(--on-surface-variant); margin-top:2px;">IP: <?= htmlspecialchars($s['ip'] ?? 'Unknown IP') ?> &bull; <?= date('M j, Y g:i A', $s['last_activity']) ?></div>
                                            </div>
                                            <div style="font-size:0.65rem; font-weight:700; color:var(--on-surface-variant);">PREV</div>
                                        </div>
                            <?php endif;
                                endforeach;
                            endif; ?>
                        </div>

                        <button class="st-btn st-btn-text" id="terminateSessionsBtn" style="width:100%; color:var(--error); font-weight:700; justify-content:center; text-transform:uppercase; letter-spacing:0.05em; margin-top:24px; padding:12px; border-radius:12px; background:var(--error-container);" onclick="terminateSessions()">TERMINATE ALL OTHER SESSIONS</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const csrfToken = '<?= function_exists("csrf_token") ? csrf_token() : "" ?>';

        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const p = document.getElementById('avatarPreview');
                    p.src = e.target.result;
                    p.style.display = 'block';
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function saveIdentity() {
            const name = document.getElementById('prof_name').value.trim();
            const email = document.getElementById('prof_email').value.trim();
            const btn = document.getElementById('saveIdentityBtn');
            if (!name) return;

            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined st-spin">sync</span> Saving...';

            const fileInput = document.getElementById('avatar_upload');
            const formData = new FormData();
            formData.append('update_identity', '1');
            formData.append('csrf_token', csrfToken);
            formData.append('name', name);
            formData.append('email', email);
            if (fileInput.files.length > 0) formData.append('avatar', fileInput.files[0]);

            fetch('profile_settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(d => {
                    btn.disabled = false;
                    btn.innerText = 'Save Identity';
                    if (d.status === 'success') {
                        alert(d.message);
                        window.location.reload();
                    } else {
                        alert(d.message || "Error saving identity");
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = 'Save Identity';
                    alert('Server connection failed.');
                });
        }

        function saveSecurity() {
            const op = document.getElementById('prof_old_pass').value;
            const np = document.getElementById('prof_new_pass').value;
            const cp = document.getElementById('prof_conf_pass').value;
            if (!op || !np) {
                alert("Please fill current and new password.");
                return;
            }
            if (np !== cp) {
                alert("New password and confirm do not match.");
                return;
            }

            const btn = document.getElementById('updateSecBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined st-spin">sync</span> Updating...';

            const formData = new FormData();
            formData.append('update_password', '1');
            formData.append('csrf_token', csrfToken);
            formData.append('old_password', op);
            formData.append('new_password', np);

            fetch('profile_settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(d => {
                    btn.disabled = false;
                    btn.innerText = 'Update Security';
                    if (d.status === 'success') {
                        alert("Security updated successfully.");
                        document.getElementById('prof_old_pass').value = '';
                        document.getElementById('prof_new_pass').value = '';
                        document.getElementById('prof_conf_pass').value = '';
                    } else {
                        alert(d.message || "Error updating security");
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = 'Update Security';
                    alert("Server Error.");
                });
        }

        function terminateSessions() {
            const btn = document.getElementById('terminateSessionsBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined st-spin">sync</span> TERMINATING...';

            const formData = new FormData();
            formData.append('terminate_sessions', '1');
            formData.append('csrf_token', csrfToken);

            fetch('profile_settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(d => {
                    btn.disabled = false;
                    btn.innerText = 'TERMINATE ALL OTHER SESSIONS';
                    if (d.status === 'success') {
                        alert(d.message);
                        window.location.reload();
                    } else {
                        alert(d.message || "Error terminating sessions");
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.innerText = 'TERMINATE ALL OTHER SESSIONS';
                    alert("Server Error.");
                });
        }

        function checkActiveSessions() {
            const formData = new FormData();
            formData.append('check_sessions', '1');
            formData.append('csrf_token', csrfToken);

            fetch('profile_settings.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'success') {
                        const currentCount = document.querySelectorAll('.session-item').length;
                        if (d.count !== currentCount) {
                            // If count changed, reload the page to show accurate session data
                            window.location.reload();
                        }
                    }
                }).catch(err => {});
        }

        // Poll every 10 seconds for live checking
        setInterval(checkActiveSessions, 10000);
    </script>
