<?php
/**
 * Email sending through PHPMailer (SMTP), configured in config/config.php.
 * The "log" transport writes messages to storage/mail/ for offline testing only;
 * the live demo uses the "smtp" transport so a real email is received.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once APP_ROOT . '/vendor/phpmailer/src/Exception.php';
require_once APP_ROOT . '/vendor/phpmailer/src/PHPMailer.php';
require_once APP_ROOT . '/vendor/phpmailer/src/SMTP.php';

/** Send an HTML email. Returns true on success; failures are logged, never shown with SMTP details. */
function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    $html = mail_layout($subject, $htmlBody);
    $text = trim(html_entity_decode(strip_tags(str_replace(['</p>', '<br>'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));

    if (config('mail.transport') === 'log') {
        $dir = APP_ROOT . '/storage/mail';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Microsecond timestamp first, so sorting by name also sorts by time.
        $file = $dir . '/' . date('Ymd-His') . sprintf('-%06d-', (int) (fmod(microtime(true), 1) * 1e6)) . bin2hex(random_bytes(3)) . '.html';
        $meta = '<!-- To: ' . htmlspecialchars("$toName <$toEmail>") . ' | Subject: ' . htmlspecialchars($subject) . " -->\n";
        return file_put_contents($file, $meta . $html) !== false;
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = (string) config('mail.host');
        $mail->Port       = (int) config('mail.port', 587);
        $mail->SMTPAuth   = config('mail.username', '') !== '';
        $mail->Username   = (string) config('mail.username', '');
        $mail->Password   = (string) config('mail.password', '');
        $enc = (string) config('mail.encryption', 'tls');
        if ($enc === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->Timeout  = 15;
        $mail->CharSet  = 'UTF-8';
        $mail->setFrom((string) config('mail.from_email'), (string) (config('mail.from_name') ?: app_name()));
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;
        $mail->send();
        return true;
    } catch (MailException $e) {
        error_log('[barangay-eservices] mail failed: ' . $mail->ErrorInfo);
        return false;
    }
}

function mail_layout(string $title, string $body): string
{
    $brgy = e(app_name());
    return '<!doctype html><html><body style="margin:0;background:#f3f5f8;font-family:Arial,Helvetica,sans-serif;color:#1b2433">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #dde1e7">'
        . '<tr><td style="background:#8f1d21;color:#ffffff;padding:16px 24px;font-size:16px;font-weight:bold">' . $brgy . '</td></tr>'
        . '<tr><td style="padding:24px;font-size:15px;line-height:1.55">' . $body . '</td></tr>'
        . '<tr><td style="padding:16px 24px;border-top:1px solid #dde1e7;font-size:12px;color:#5b6575">This is an automated message from ' . $brgy . '. Please do not reply.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

// ---------------------------------------------------------------- Email verification

const EMAIL_CODE_MAX_ATTEMPTS = 5;

/**
 * Add the Gmail-code columns on databases created before this feature.
 * Safe to call more than once; the check runs only on the first call in a request.
 */
function ensure_email_verification_code_columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $cols = db_all(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_verifications'
            AND COLUMN_NAME IN ('code_hash', 'code_attempts')"
    );
    $names = array_column($cols, 'COLUMN_NAME');
    if (!in_array('code_hash', $names, true)) {
        db()->query('ALTER TABLE email_verifications ADD COLUMN code_hash CHAR(64) NULL AFTER token_hash');
    }
    if (!in_array('code_attempts', $names, true)) {
        db()->query('ALTER TABLE email_verifications ADD COLUMN code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER code_hash');
    }
    $done = true;
}

/**
 * Mark one verification row used and the account verified. Returns false if another
 * request already consumed the row.
 */
function mark_email_verified(int $verificationId, int $userId): bool
{
    $ok = db_transaction(function () use ($verificationId, $userId) {
        $marked = db_exec(
            'UPDATE email_verifications SET used_at = NOW() WHERE verification_id = ? AND used_at IS NULL',
            [$verificationId]
        );
        if ($marked !== 1) {
            return false;
        }
        db_exec('UPDATE users SET email_verified_at = NOW() WHERE user_id = ? AND email_verified_at IS NULL', [$userId]);
        return true;
    });
    if ($ok) {
        log_activity('email_verified', 'user', $userId, 'Email address verified', $userId);
    }
    return (bool) $ok;
}

/**
 * Check a 6-digit code against the latest unused verification for this user.
 * Returns ok, format, missing, expired, locked, or invalid.
 */
function confirm_email_code(int $userId, string $code): string
{
    ensure_email_verification_code_columns();
    $digits = preg_replace('/\D+/', '', $code) ?? '';
    if (!preg_match('/^\d{6}$/', $digits)) {
        return 'format';
    }

    $row = db_one(
        'SELECT verification_id, code_hash, code_attempts, expires_at
           FROM email_verifications
          WHERE user_id = ? AND used_at IS NULL
          ORDER BY verification_id DESC LIMIT 1',
        [$userId]
    );
    if (!$row || $row['code_hash'] === null) {
        return 'missing';
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        return 'expired';
    }
    if ((int) $row['code_attempts'] >= EMAIL_CODE_MAX_ATTEMPTS) {
        return 'locked';
    }
    if (!hash_equals((string) $row['code_hash'], hash('sha256', $digits))) {
        db_exec(
            'UPDATE email_verifications SET code_attempts = code_attempts + 1 WHERE verification_id = ? AND used_at IS NULL',
            [(int) $row['verification_id']]
        );
        $left = EMAIL_CODE_MAX_ATTEMPTS - ((int) $row['code_attempts'] + 1);
        return $left > 0 ? 'invalid' : 'locked';
    }

    return mark_email_verified((int) $row['verification_id'], $userId) ? 'ok' : 'invalid';
}

/**
 * Create a new verification token and a 6-digit code, then email both through Gmail SMTP.
 * Any older unused tokens for the user are invalidated, so only the latest code and link work.
 * The raw token and code are only in the email; the database keeps SHA-256 hashes.
 */
function send_verification_email(array $user): bool
{
    ensure_email_verification_code_columns();
    $token = bin2hex(random_bytes(32));
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $ttl = (int) config('verification_ttl_minutes', 60);

    db_query('UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [(int) $user['user_id']]);
    db_query(
        'INSERT INTO email_verifications (user_id, token_hash, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))',
        [(int) $user['user_id'], hash('sha256', $token), hash('sha256', $code), $ttl]
    );

    $link = absolute_url('auth/verify.php?token=' . $token);
    $body = '<p>Good day, ' . e($user['first_name']) . '.</p>'
        . '<p>Enter this code on the verification page to activate your ' . e(app_name()) . ' account. The page updates as soon as the code is accepted.</p>'
        . '<p style="margin:24px 0;font-size:32px;letter-spacing:8px;font-weight:bold">' . e($code) . '</p>'
        . '<p>You can also select <strong>Verify my email</strong> in this message. That confirms the same request immediately.</p>'
        . '<p style="margin:24px 0"><a href="' . e($link) . '" style="background:#8f1d21;color:#ffffff;padding:12px 20px;text-decoration:none;display:inline-block">Verify my email</a></p>'
        . '<p>Or open this link: <br><a href="' . e($link) . '">' . e($link) . '</a></p>'
        . '<p>The code and link expire in ' . $ttl . ' minutes and can be used only once. If you did not create this account, you can ignore this email.</p>';

    $sent = send_mail($user['email'], full_name($user), 'Your Gmail verification code', $body);
    log_activity($sent ? 'verification_email_sent' : 'verification_email_failed', 'user', (int) $user['user_id'], 'To ' . $user['email'], (int) $user['user_id']);
    return $sent;
}
