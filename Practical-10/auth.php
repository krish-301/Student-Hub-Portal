<?php

$isSecure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

const SESSION_IDLE_TIMEOUT = 900;
const REMEMBER_COOKIE = 'studenthub_remember';

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_is_valid(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';
    return is_string($submitted)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submitted);
}

function set_remember_cookie(string $value, int $expires): void
{
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(REMEMBER_COOKIE, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_remember_cookie(): void
{
    set_remember_cookie('', time() - 3600);
}

function redirect_by_role(): void
{
    $destination = ($_SESSION['role'] ?? '') === 'admin' ? 'admin-dashboard.html' : 'dashboard.html';
    header('Location: ../pages/' . $destination);
    exit;
}

function establish_user_session(array $user): void
{
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function remember_user(mysqli $connection, int $userId): void
{
    $selector = bin2hex(random_bytes(16));
    $validator = bin2hex(random_bytes(32));
    $validatorHash = hash('sha256', $validator);
    $expires = time() + (30 * 24 * 60 * 60);
    $expiresAt = date('Y-m-d H:i:s', $expires);

    $insert = $connection->prepare(
        'INSERT INTO remember_tokens (selector, user_id, validator_hash, expires_at) VALUES (?, ?, ?, ?)'
    );
    $insert->bind_param('siss', $selector, $userId, $validatorHash, $expiresAt);
    $insert->execute();
    $insert->close();

    $_SESSION['remember_selector'] = $selector;
    set_remember_cookie($selector . ':' . $validator, $expires);
}

function delete_remember_selector(mysqli $connection, string $selector): void
{
    $delete = $connection->prepare('DELETE FROM remember_tokens WHERE selector = ?');
    $delete->bind_param('s', $selector);
    $delete->execute();
    $delete->close();
}

function restore_remembered_user(mysqli $connection): bool
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (!is_string($cookie) || $cookie === '') {
        return false;
    }
    if (!preg_match('/^([a-f0-9]{32}):([a-f0-9]{64})$/', $cookie, $parts)) {
        clear_remember_cookie();
        return false;
    }

    $selector = $parts[1];
    $validator = $parts[2];
    $connection->begin_transaction();
    try {
        $query = $connection->prepare(
            'SELECT t.validator_hash, u.user_id, u.username, u.name, a.role
             FROM remember_tokens t
             JOIN user_accounts u ON u.user_id = t.user_id
             JOIN account_auth a ON a.user_id = u.user_id
             WHERE t.selector = ? AND t.expires_at > CURRENT_TIMESTAMP LIMIT 1
             FOR UPDATE'
        );
        $query->bind_param('s', $selector);
        $query->execute();
        $user = $query->get_result()->fetch_assoc();
        $query->close();

        if (!$user || !hash_equals($user['validator_hash'], hash('sha256', $validator))) {
            delete_remember_selector($connection, $selector);
            $connection->commit();
            clear_remember_cookie();
            return false;
        }

        delete_remember_selector($connection, $selector);
        establish_user_session($user);
        remember_user($connection, (int)$user['user_id']);
        $connection->commit();
        return true;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

function revoke_current_remember_token(mysqli $connection): void
{
    $selector = $_SESSION['remember_selector'] ?? '';
    if (!is_string($selector) || $selector === '') {
        $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
        if (is_string($cookie) && preg_match('/^([a-f0-9]{32}):[a-f0-9]{64}$/', $cookie, $parts)) {
            $selector = $parts[1];
        }
    }
    if ($selector !== '') {
        delete_remember_selector($connection, $selector);
    }
    clear_remember_cookie();
}

function destroy_auth_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

function require_role(string $requiredRole, bool $jsonResponse = false): void
{
    if (!isset($_SESSION['user_id']) && isset($_COOKIE[REMEMBER_COOKIE])) {
        try {
            require __DIR__ . '/mysqli_db.php';
            if (restore_remembered_user($connection)) {
                $lastLogin = $connection->prepare('UPDATE account_auth SET last_login_at = CURRENT_TIMESTAMP WHERE user_id = ?');
                $userId = (int)$_SESSION['user_id'];
                $lastLogin->bind_param('i', $userId);
                $lastLogin->execute();
                $lastLogin->close();
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('StudentHub remember-me restore failed: ' . $exception->getMessage());
            http_response_code(503);
            exit('Authentication service is temporarily unavailable.');
        }
    }

    if (!isset($_SESSION['user_id'])) {
        if ($jsonResponse) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['authenticated' => false]);
            exit;
        }
        header('Location: ../pages/login.html');
        exit;
    }

    if (time() - (int)($_SESSION['last_activity'] ?? 0) > SESSION_IDLE_TIMEOUT) {
        try {
            require __DIR__ . '/mysqli_db.php';
            revoke_current_remember_token($connection);
        } catch (mysqli_sql_exception $exception) {
            error_log('StudentHub timeout token cleanup failed: ' . $exception->getMessage());
        }
        clear_remember_cookie();
        destroy_auth_session();
        if ($jsonResponse) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['authenticated' => false, 'reason' => 'timeout']);
            exit;
        }
        header('Location: ../pages/login.html?status=timeout');
        exit;
    }

    $_SESSION['last_activity'] = time();
    if (($_SESSION['role'] ?? '') !== $requiredRole) {
        if ($jsonResponse) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'authenticated' => true,
                'role' => $_SESSION['role'],
                'redirect' => ($_SESSION['role'] === 'admin' ? 'admin-dashboard.html' : 'dashboard.html'),
            ]);
            exit;
        }
        redirect_by_role();
    }
}
