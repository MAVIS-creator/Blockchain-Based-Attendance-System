<?php
session_start();
require_once 'includes/header.php';
require_once 'includes/csrf.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Check if user is super admin for account settings access
$isSuperAdmin = isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin';

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        die('CSRF verification failed');
    }

    if (isset($_POST['change_name'])) {
        $newName = trim($_POST['new_name']);
        if (!empty($newName)) {
            // Update name in accounts.json
            $accounts = json_decode(file_get_contents('accounts.json'), true);
            if (isset($accounts[$_SESSION['admin_user']])) {
                $accounts[$_SESSION['admin_user']]['name'] = $newName;
                file_put_contents('accounts.json', json_encode($accounts, JSON_PRETTY_PRINT));
                $_SESSION['admin_name'] = $newName;
                $success = 'Name updated successfully';
            }
        }
    }
    
    if (isset($_POST['change_password'])) {
        $currentPass = $_POST['current_password'];
        $newPass = $_POST['new_password'];
        $confirmPass = $_POST['confirm_password'];
        
        if ($newPass === $confirmPass) {
            // Verify current password and update
            $accounts = json_decode(file_get_contents('accounts.json'), true);
            if (isset($accounts[$_SESSION['admin_user']]) && 
                password_verify($currentPass, $accounts[$_SESSION['admin_user']]['password'])) {
                $accounts[$_SESSION['admin_user']]['password'] = password_hash($newPass, PASSWORD_DEFAULT);
                file_put_contents('accounts.json', json_encode($accounts, JSON_PRETTY_PRINT));
                $success = 'Password changed successfully';
            } else {
                $error = 'Current password is incorrect';
            }
        } else {
            $error = 'New passwords do not match';
        }
    }
    
    if (isset($_FILES['profile_picture'])) {
        $file = $_FILES['profile_picture'];
        if ($file['error'] === 0) {
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (in_array($file['type'], $allowedTypes)) {
                $maxSize = 5 * 1024 * 1024; // 5MB
                if ($file['size'] <= $maxSize) {
                    $fileName = 'avatar_' . $_SESSION['admin_user'] . '_' . time() . '.jpg';
                    $uploadPath = '../asset/avatars/';
                    if (!file_exists($uploadPath)) {
                        mkdir($uploadPath, 0777, true);
                    }
                    if (move_uploaded_file($file['tmp_name'], $uploadPath . $fileName)) {
                        // Update avatar in accounts.json
                        $accounts = json_decode(file_get_contents('accounts.json'), true);
                        if (isset($accounts[$_SESSION['admin_user']])) {
                            // Remove old avatar if exists
                            if (!empty($accounts[$_SESSION['admin_user']]['avatar'])) {
                                $oldAvatar = $uploadPath . basename($accounts[$_SESSION['admin_user']]['avatar']);
                                if (file_exists($oldAvatar)) {
                                    unlink($oldAvatar);
                                }
                            }
                            $accounts[$_SESSION['admin_user']]['avatar'] = 'asset/avatars/' . $fileName;
                            file_put_contents('accounts.json', json_encode($accounts, JSON_PRETTY_PRINT));
                            $_SESSION['admin_avatar'] = 'asset/avatars/' . $fileName;
                            $success = 'Profile picture updated successfully';
                        }
                    } else {
                        $error = 'Failed to upload file';
                    }
                } else {
                    $error = 'File is too large (max 5MB)';
                }
            } else {
                $error = 'Invalid file type. Please upload JPG, PNG or GIF';
            }
        } else if ($file['error'] !== UPLOAD_ERR_NO_FILE) {
            $error = 'File upload failed';
        }
    }
}
?>

<div class="content-wrapper">
    <div class="profile-settings">
        <?php if (isset($success)): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="profile-section">
            <h2>Change Display Name</h2>
            <form method="POST" class="settings-form">
                <?= csrf_token_tag() ?>
                <div class="form-group">
                    <label for="new_name">New Display Name</label>
                    <input type="text" id="new_name" name="new_name" class="form-control" 
                           value="<?= htmlspecialchars($_SESSION['admin_name'] ?? '') ?>" required>
                </div>
                <button type="submit" name="change_name" class="btn btn-primary">Update Name</button>
            </form>
        </div>

        <div class="profile-section">
            <h2>Change Password</h2>
            <form method="POST" class="settings-form">
                <?= csrf_token_tag() ?>
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>
                <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
            </form>
        </div>

        <div class="profile-section">
            <h2>Change Profile Picture</h2>
            <form method="POST" enctype="multipart/form-data" class="settings-form">
                <?= csrf_token_tag() ?>
                <div class="form-group">
                    <label for="profile_picture">New Profile Picture</label>
                    <input type="file" id="profile_picture" name="profile_picture" class="form-control" accept="image/*" required>
                    <small class="form-text text-muted">Supported formats: JPG, PNG, GIF (max 5MB)</small>
                </div>
                <button type="submit" class="btn btn-primary">Upload Picture</button>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>