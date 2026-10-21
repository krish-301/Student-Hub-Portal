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
header('Content-Type: text/html; charset=utf-8');

$name = htmlspecialchars($_SESSION['name'], ENT_QUOTES, 'UTF-8');
$username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
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
<?php endif; ?>
<hr>
<footer align="center"><p>© 2026 Student Hub Portal | All Rights Reserved</p></footer>
