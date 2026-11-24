<?php
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['verified' => false]);
    exit;
}

$verified = is_verified($user);
echo json_encode([
    'verified' => $verified,
    'redirect' => $verified ? url(home_path_for_role($user['role_key'])) : null,
]);
