<?php
require __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['csrf_token' => csrf_token()]);
