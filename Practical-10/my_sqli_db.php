<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: 'localhost';
$database = getenv('DB_NAME') ?: 'studenthub';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

$connection = new mysqli($host, $username, $password, $database);
$connection->set_charset('utf8mb4');
