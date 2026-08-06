<?php
/**
 * PHPMailer Helper Utility
 * High-Q Solid Academy Biometric Attendance System
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/db.php';

function send_system_email(string $to, string $subject, string $htmlBody): array {
    $mail = new PHPMailer(true);

    try {
        // SMTP Server Settings from .env
        $mail->isSMTP();
        $mail->Host       = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = getenv('SMTP_USER') ?: '';
        $mail->Password   = getenv('SMTP_PASS') ?: '';
        $mail->SMTPSecure = getenv('SMTP_SECURE') ?: PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)(getenv('SMTP_PORT') ?: 587);

        // Sender & Recipient
        $senderEmail = getenv('FROM_EMAIL') ?: (getenv('SMTP_USER') ?: 'admin@highqsoldacademy.com');
        $senderName  = getenv('FROM_NAME') ?: 'High-Q Solid Academy';
        
        $mail->setFrom($senderEmail, $senderName);
        $mail->addAddress($to);
        $mail->addReplyTo(getenv('SUPPORT_EMAIL') ?: 'admin@highqsoldacademy.com', 'High-Q Support');

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        return ['success' => true, 'message' => 'Email dispatched successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => "Email dispatch failed: {$mail->ErrorInfo}"];
    }
}
