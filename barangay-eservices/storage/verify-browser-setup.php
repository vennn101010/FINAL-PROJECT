<?php
require dirname(__DIR__) . '/app/bootstrap.php';

$email = 'realtime.verify.check@gmail.com';
$existing = (int) db_value('SELECT user_id FROM users WHERE email = ?', [$email]);
if ($existing) {
    db_exec('DELETE FROM users WHERE user_id = ?', [$existing]);
}
$hash = db_value('SELECT password_hash FROM users WHERE user_id = 1');
$purok = (int) db_value('SELECT purok_id FROM puroks ORDER BY purok_id LIMIT 1');
$uid = db_insert(
    'INSERT INTO users (role_id, purok_id, first_name, last_name, email, contact_no, street_address, password_hash)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    [role_id(ROLE_RESIDENT), $purok, 'Realtime', 'Check', $email, '09170000000', '1 Test St.', $hash]
);
$code = '739204';
db_query(
    'INSERT INTO email_verifications (user_id, token_hash, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))',
    [$uid, hash('sha256', bin2hex(random_bytes(16))), hash('sha256', $code)]
);
echo "user=$uid code=$code\n";
