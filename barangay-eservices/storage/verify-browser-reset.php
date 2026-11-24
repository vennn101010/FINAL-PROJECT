<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$email = 'realtime.verify.check@gmail.com';
$uid = (int) db_value('SELECT user_id FROM users WHERE email = ?', [$email]);
db_exec('UPDATE users SET email_verified_at = NULL WHERE user_id = ?', [$uid]);
db_query('UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$uid]);
echo "reset $uid\n";
