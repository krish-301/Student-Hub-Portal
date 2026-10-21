<?php
require __DIR__ . '/auth.php';
require_role('admin');
require __DIR__ . '/admin_audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_is_valid()) {
    header('Location: ../pages/admin-dashboard.html?status=user_status_error');
    exit;
}

$userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
$newStatus = is_string($_POST['status'] ?? null) ? $_POST['status'] : '';
if ($userId === false || $userId < 1 || !in_array($newStatus, ['active', 'inactive'], true)
    || $userId === (int)$_SESSION['user_id']
) {
    header('Location: ../pages/admin-dashboard.html?status=user_status_error');
    exit;
}

try {
    require __DIR__ . '/mysqli_db.php';
    $connection->begin_transaction();

    $targetQuery = $connection->prepare(
        'SELECT a.role, a.status, u.username
         FROM account_auth a JOIN user_accounts u ON u.user_id = a.user_id
         WHERE a.user_id = ? FOR UPDATE'
    );
    $targetQuery->bind_param('i', $userId);
    $targetQuery->execute();
    $target = $targetQuery->get_result()->fetch_assoc();
    $targetQuery->close();
    if (!$target) {
        $connection->rollback();
        header('Location: ../pages/admin-dashboard.html?status=user_status_error');
        exit;
    }

    if ($target['role'] === 'admin' && $target['status'] === 'active' && $newStatus === 'inactive') {
        $activeAdmins = $connection->query(
            "SELECT user_id FROM account_auth WHERE role = 'admin' AND status = 'active' FOR UPDATE"
        );
        if ($activeAdmins->num_rows <= 1) {
            $connection->rollback();
            header('Location: ../pages/admin-dashboard.html?status=user_status_error');
            exit;
        }
    }

    $update = $connection->prepare('UPDATE account_auth SET status = ? WHERE user_id = ?');
    $update->bind_param('si', $newStatus, $userId);
    $update->execute();
    $update->close();

    record_admin_action(
        $connection,
        $newStatus === 'active' ? 'activated' : 'deactivated',
        'user',
        $userId,
        $target['username'],
        $_SESSION['username']
    );
    $connection->commit();
    header('Location: ../pages/admin-dashboard.html?status=user_status_updated');
    exit;
} catch (mysqli_sql_exception $exception) {
    try {
        $connection->rollback();
    } catch (mysqli_sql_exception $rollbackException) {
        error_log('StudentHub user status rollback failed: ' . $rollbackException->getMessage());
    }
    error_log('StudentHub user status update failed: ' . $exception->getMessage());
    header('Location: ../pages/admin-dashboard.html?status=user_status_error');
    exit;
}
