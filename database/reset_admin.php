<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=highq_attendance', 'root', '');
$hash = password_hash('admin123', PASSWORD_DEFAULT);

// Check if admin exists
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute(['admin']);
$user = $stmt->fetch();

if ($user) {
    $update = $pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
    $update->execute([$hash, 'admin']);
    echo "Admin password reset successfully. Hash: $hash\n";
} else {
    $insert = $pdo->prepare("INSERT INTO users (username, password, fullname, role) VALUES ('admin', ?, 'Administrator', 'superadmin')");
    $insert->execute([$hash]);
    echo "Admin user created successfully. Hash: $hash\n";
}

echo "Login with username: admin / password: admin123\n";
