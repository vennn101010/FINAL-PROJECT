<?php
require dirname(__DIR__) . '/app/bootstrap.php';

ensure_email_verification_code_columns();
$row = db_one("SHOW COLUMNS FROM email_verifications LIKE 'code_hash'");
echo $row ? "code_hash ok\n" : "missing\n";

$v = new Validator(['email' => 'person@gmail.com']);
$v->email('email', 'Email')->gmail('email', 'Email');
echo $v->fails() ? "gmail rejected\n" : "gmail accepted\n";

$v2 = new Validator(['email' => 'person@yahoo.com']);
$v2->email('email', 'Email')->gmail('email', 'Email');
echo $v2->fails() ? "yahoo rejected\n" : "yahoo accepted\n";

$uid = (int) db_value('SELECT user_id FROM users WHERE email_verified_at IS NULL LIMIT 1');
echo "unverified user $uid\n";
if (!$uid) {
    exit(0);
}

db_query('UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$uid]);
$code = '482913';
db_query(
    'INSERT INTO email_verifications (user_id, token_hash, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
    [$uid, hash('sha256', bin2hex(random_bytes(16))), hash('sha256', $code)]
);
echo 'bad=' . confirm_email_code($uid, '000000') . "\n";
echo 'good=' . confirm_email_code($uid, $code) . "\n";
echo 'again=' . confirm_email_code($uid, $code) . "\n";
$verified = db_value('SELECT email_verified_at FROM users WHERE user_id = ?', [$uid]);
echo 'verified_at=' . ($verified ?: 'null') . "\n";
db_exec('UPDATE users SET email_verified_at = NULL WHERE user_id = ?', [$uid]);
echo "reset user\n";
