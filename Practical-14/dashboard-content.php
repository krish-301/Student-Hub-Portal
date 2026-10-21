<?php
require __DIR__ . '/auth.php';

$role = $_GET['role'] ?? '';
if (!in_array($role, ['student', 'admin'], true)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid role']);
    exit;
}

require_role($role, true);

$name = htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$adminStats = [];
$latestRegistrations = [];
$latestAdminActions = [];
$adminUsers = [];
if ($role === 'admin') {
    try {
        require __DIR__ . '/mysqli_db.php';
        $statsResult = $connection->query(
            "SELECT
                (SELECT COUNT(*) FROM user_accounts u JOIN account_auth a ON a.user_id = u.user_id WHERE a.status = 'active') AS active_users,
                (SELECT COUNT(*) FROM user_accounts u JOIN account_auth a ON a.user_id = u.user_id WHERE a.status = 'inactive') AS inactive_users,
                (SELECT COUNT(*) FROM students WHERE status = 'active') AS active_students,
                (SELECT COUNT(*) FROM events WHERE status <> 'deleted') AS total_events,
                (SELECT COUNT(*) FROM events WHERE status = 'open') AS open_events"
        );
        $adminStats = $statsResult->fetch_assoc();

        $registrations = $connection->query(
            'SELECT s.name AS student_name, s.enrollment_no, e.title AS event_title, r.registered_at
             FROM registrations r
             JOIN students s ON s.student_id = r.student_id
             JOIN events e ON e.event_id = r.event_id
             ORDER BY r.registered_at DESC LIMIT 5'
        );
        $latestRegistrations = $registrations->fetch_all(MYSQLI_ASSOC);

        $actions = $connection->query(
            'SELECT action, entity_type, details, performed_by, created_at
             FROM admin_audit_logs ORDER BY created_at DESC LIMIT 10'
        );
        $latestAdminActions = $actions->fetch_all(MYSQLI_ASSOC);

        $users = $connection->query(
            'SELECT u.user_id, u.username, u.name, a.role, a.status
             FROM user_accounts u JOIN account_auth a ON a.user_id = u.user_id
             ORDER BY u.user_id DESC LIMIT 10'
        );
        $adminUsers = $users->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $exception) {
        error_log('StudentHub admin dashboard query failed: ' . $exception->getMessage());
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        exit('Admin dashboard data is temporarily unavailable.');
    }
}
header('Content-Type: text/html; charset=utf-8');
?>
<nav>
    <a href="index.html">Home</a>
    <?php if ($role === 'student'): ?>
        | <a href="profile.html">Profile</a>
        | <a href="courses.html">Courses</a>
        | <a href="attendance.html">Attendance</a>
        | <a href="data-portal.html">Data Portal</a>
        | <a href="result.html">Results</a>
        | <a href="contact.html">Contact</a>
    <?php else: ?>
        | <a href="../php/student-management.php">Manage students</a>
        | <a href="../php/event-management.php">Manage events</a>
        | <a href="../pages/events-api.html">Events AJAX demo</a>
    <?php endif; ?>
    <form action="../php/logout.php" method="post" style="display:inline">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <button type="submit">Logout</button>
    </form>
</nav>
<hr>
<h2>Welcome, <?= $name ?></h2>
<?php if ($role === 'student'): ?>
    <p>This is your Student Dashboard. You are signed in as <?= $username ?>.</p>
    <hr>
    <table border="1" cellpadding="20" width="90%">
        <tr><th>Total Courses</th><th>Attendance</th><th>Assignments</th><th>Results</th></tr>
        <tr align="center"><td>6</td><td>92%</td><td>5 Pending</td><td>View Result</td></tr>
    </table>
    <br>
    <h2>Quick Actions</h2>
    <ul>
        <li><a href="courses.html">View Courses</a></li>
        <li><a href="attendance.html">Check Attendance</a></li>
        <li><a href="assignment.html">Submit Assignment</a></li>
        <li><a href="result.html">View Results</a></li>
        <li><a href="contact.html">Contact Us</a></li>
    </ul>
    <br>
    <h2>Latest Announcements</h2>
    <ol>
        <li>Semester exams start from 15 December.</li>
        <li>Assignment submission deadline is 20 December.</li>
        <li>College workshop on Web Development.</li>
    </ol>
<?php else: ?>
    <p>You are signed in with the administrator role.</p>
    <?php if (isset($_GET['status']) && $_GET['status'] === 'user_status_updated'): ?>
        <p class="form-message success" role="status">User status updated.</p>
    <?php elseif (isset($_GET['status']) && $_GET['status'] === 'user_status_error'): ?>
        <p class="form-message error" role="status">Could not update user status.</p>
    <?php endif; ?>
    <section class="container">
        <h2>Overview</h2>
        <table border="1" cellpadding="10">
            <tr><th>Active users</th><td><?= (int)$adminStats['active_users'] ?></td><th>Inactive users</th><td><?= (int)$adminStats['inactive_users'] ?></td></tr>
            <tr><th>Active students</th><td><?= (int)$adminStats['active_students'] ?></td><th>Events</th><td><?= (int)$adminStats['total_events'] ?> (<?= (int)$adminStats['open_events'] ?> open)</td></tr>
        </table>

        <h2>Latest registrations</h2>
        <table border="1" cellpadding="8">
            <thead><tr><th>Student</th><th>Enrollment</th><th>Event</th><th>Registered</th></tr></thead>
            <tbody>
            <?php foreach ($latestRegistrations as $registration): ?>
                <tr>
                    <td><?= htmlspecialchars($registration['student_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($registration['enrollment_no'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($registration['event_title'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($registration['registered_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$latestRegistrations): ?><tr><td colspan="4">No registrations yet.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h2>Recent admin actions</h2>
        <table border="1" cellpadding="8">
            <thead><tr><th>Action</th><th>Record</th><th>Details</th><th>By</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($latestAdminActions as $action): ?>
                <tr>
                    <td><?= htmlspecialchars($action['action'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($action['entity_type'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($action['details'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($action['performed_by'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($action['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$latestAdminActions): ?><tr><td colspan="5">No admin actions recorded.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h2>User status</h2>
        <table border="1" cellpadding="8">
            <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($adminUsers as $account): ?>
                <tr>
                    <td><?= htmlspecialchars($account['username'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($account['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($account['role'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($account['status'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ((int)$account['user_id'] !== (int)$_SESSION['user_id']): ?>
                            <form action="../php/admin-user-status.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="user_id" value="<?= (int)$account['user_id'] ?>">
                                <input type="hidden" name="status" value="<?= $account['status'] === 'active' ? 'inactive' : 'active' ?>">
                                <button type="submit"><?= $account['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        <?php else: ?>Current admin<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
<hr>
<footer align="center"><p>© 2026 Student Hub Portal | All Rights Reserved</p></footer>
