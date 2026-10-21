<?php
require __DIR__ . '/auth.php';
require_role('admin');
try {
    require __DIR__ . '/mysqli_db.php';
} catch (mysqli_sql_exception $exception) {
    error_log('StudentHub student management connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('Student management is temporarily unavailable. Please try again later.');
}

$courses = ['Computer Science', 'Information Technology', 'Electronics', 'Mechanical', 'Civil', 'General'];
$messages = [
    'added' => 'Student added.',
    'updated' => 'Student updated.',
    'deleted' => 'Student moved to deleted records.',
    'restored' => 'Student restored.',
    'invalid' => 'Check the student details and try again.',
    'duplicate' => 'That enrollment number or email already exists.',
    'error' => 'The request could not be completed. Please try again.'
];

function student_management_redirect(string $status, string $search, string $filter, string $courseFilter): void
{
    header('Location: student-management.php?' . http_build_query([
        'status' => $status,
        'search' => $search,
        'filter' => $filter,
        'course_filter' => $courseFilter,
    ]));
    exit;
}

function student_management_value(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function bind_student_parameters(mysqli_stmt $statement, string $types, array &$parameters): void
{
    if ($types === '') {
        return;
    }
    $arguments = [$types];
    foreach ($parameters as &$parameter) {
        $arguments[] = &$parameter;
    }
    call_user_func_array([$statement, 'bind_param'], $arguments);
}

$requestParams = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$searchValue = $requestParams['search'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$search = function_exists('mb_substr')
    ? mb_substr($search, 0, 100, 'UTF-8')
    : substr($search, 0, 100);
$filterValue = $requestParams['filter'] ?? 'active';
$filter = is_string($filterValue) ? $filterValue : 'active';
if (!in_array($filter, ['active', 'deleted', 'all'], true)) {
    $filter = 'active';
}
$courseFilterValue = $requestParams['course_filter'] ?? '';
$courseFilter = is_string($courseFilterValue) ? $courseFilterValue : '';
if ($courseFilter !== '' && !in_array($courseFilter, $courses, true)) {
    $courseFilter = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        student_management_redirect('invalid', $search, $filter, $courseFilter);
    }

    $action = student_management_value('action');
    $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
    try {
        if ($action === 'add' || $action === 'update') {
            $enrollment = student_management_value('enrollment_no');
            $name = student_management_value('name');
            $email = student_management_value('email');
            $course = student_management_value('course');
            $valid = preg_match('/^[A-Za-z0-9-]{4,20}$/', $enrollment)
                && preg_match("/^[A-Za-z ]{2,100}$/", $name)
                && filter_var($email, FILTER_VALIDATE_EMAIL)
                && strlen($email) <= 254
                && in_array($course, $courses, true)
                && ($action !== 'update' || $studentId !== false);

            if (!$valid) {
                student_management_redirect('invalid', $search, $filter, $courseFilter);
            }

            if ($action === 'add') {
                $statement = $connection->prepare(
                    'INSERT INTO students (enrollment_no, name, email, course) VALUES (?, ?, ?, ?)'
                );
                $statement->bind_param('ssss', $enrollment, $name, $email, $course);
                $statement->execute();
                $statement->close();
                student_management_redirect('added', $search, $filter, $courseFilter);
            }

            $statement = $connection->prepare(
                'UPDATE students SET enrollment_no = ?, name = ?, email = ?, course = ?
                 WHERE student_id = ? AND status = \'active\''
            );
            $statement->bind_param('ssssi', $enrollment, $name, $email, $course, $studentId);
            $statement->execute();
            $changed = $statement->affected_rows;
            $statement->close();
            if ($changed === 0) {
                $exists = $connection->prepare(
                    'SELECT student_id FROM students WHERE student_id = ? AND status = \'active\''
                );
                $exists->bind_param('i', $studentId);
                $exists->execute();
                $exists->store_result();
                $changed = $exists->num_rows;
                $exists->close();
            }
            student_management_redirect($changed > 0 ? 'updated' : 'error', $search, $filter, $courseFilter);
        }

        if (($action === 'delete' || $action === 'restore') && $studentId !== false) {
            $newStatus = $action === 'delete' ? 'deleted' : 'active';
            $statement = $connection->prepare('UPDATE students SET status = ? WHERE student_id = ?');
            $statement->bind_param('si', $newStatus, $studentId);
            $statement->execute();
            $changed = $statement->affected_rows;
            $statement->close();
            student_management_redirect(
                $changed > 0 ? ($action === 'delete' ? 'deleted' : 'restored') : 'error',
                $search,
                $filter,
                $courseFilter
            );
        }

        student_management_redirect('invalid', $search, $filter, $courseFilter);
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            student_management_redirect('duplicate', $search, $filter, $courseFilter);
        }
        error_log('StudentHub student management error: ' . $exception->getMessage());
        student_management_redirect('error', $search, $filter, $courseFilter);
    }
}

$conditions = [];
$parameters = [];
$types = '';
if ($filter !== 'all') {
    $conditions[] = 'status = ?';
    $types .= 's';
    $parameters[] = $filter;
}
if ($search !== '') {
    $conditions[] = '(name LIKE ? OR email LIKE ? OR course LIKE ?)';
    $term = '%' . $search . '%';
    $types .= 'sss';
    array_push($parameters, $term, $term, $term);
}
if ($courseFilter !== '') {
    $conditions[] = 'course = ?';
    $types .= 's';
    $parameters[] = $courseFilter;
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$pageSize = 10;
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);

$count = $connection->prepare('SELECT COUNT(*) AS total FROM students' . $where);
bind_student_parameters($count, $types, $parameters);
$count->execute();
$total = (int)$count->get_result()->fetch_assoc()['total'];
$count->close();
$totalPages = max(1, (int)ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;

$export = ($_GET['export'] ?? '') === 'csv';
$sql = 'SELECT student_id, enrollment_no, name, email, course, status FROM students'
    . $where . ' ORDER BY student_id DESC';
if (!$export) {
    $sql .= ' LIMIT ? OFFSET ?';
    $types .= 'ii';
    $parameters[] = $pageSize;
    $parameters[] = $offset;
}
$statement = $connection->prepare($sql);
bind_student_parameters($statement, $types, $parameters);
$statement->execute();
$result = $statement->get_result();

if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students.csv"');
    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to create CSV export.');
    }
    fputcsv($output, ['Enrollment No', 'Name', 'Email', 'Course', 'Status']);
    while ($student = $result->fetch_assoc()) {
        $row = [
            $student['enrollment_no'],
            $student['name'],
            $student['email'],
            $student['course'],
            $student['status'],
        ];
        foreach ($row as &$cell) {
            if (preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $cell)) {
                $cell = "'" . $cell;
            }
        }
        unset($cell);
        fputcsv($output, $row);
    }
    fclose($output);
    $statement->close();
    exit;
}

$csrfToken = escape_html(csrf_token());
$statusMessage = is_string($_GET['status'] ?? null) ? ($_GET['status']) : '';
$statusClass = in_array($statusMessage, ['added', 'updated', 'deleted', 'restored'], true)
    ? 'success'
    : 'error';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Management</title>
    <link rel="stylesheet" href="../css/script.css">
</head>
<body>
    <h1 align="center">Student Management</h1>
    <button id="themeBtn" type="button">🌙 Theme</button>
    <nav><a href="../pages/admin-dashboard.html">Admin Dashboard</a></nav>
    <?php if (isset($messages[$statusMessage])): ?>
        <p class="form-message <?= $statusClass ?>" role="status"><?= escape_html($messages[$statusMessage]) ?></p>
    <?php endif; ?>

    <main class="container">
        <h2>Add student</h2>
        <form action="../php/student-management.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="search" value="<?= escape_html($search) ?>">
            <input type="hidden" name="filter" value="<?= escape_html($filter) ?>">
            <input type="hidden" name="course_filter" value="<?= escape_html($courseFilter) ?>">
            <label>Enrollment <input name="enrollment_no" required minlength="4" maxlength="20" pattern="[A-Za-z0-9-]+"></label>
            <label>Name <input name="name" required minlength="2" maxlength="100" pattern="[A-Za-z ]+"></label>
            <label>Email <input type="email" name="email" required maxlength="254"></label>
            <label>Course
                <select name="course" required>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= escape_html($course) ?>"><?= escape_html($course) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Add student</button>
        </form>

        <h2>Search and filter</h2>
        <form action="../php/student-management.php" method="get">
            <label>Search name, email, or course
                <input type="search" name="search" maxlength="100" value="<?= escape_html($search) ?>">
            </label>
            <label>Status
                <select name="filter">
                    <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="deleted" <?= $filter === 'deleted' ? 'selected' : '' ?>>Deleted</option>
                    <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                </select>
            </label>
            <label>Course filter
                <select name="course_filter">
                    <option value="">All courses</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= escape_html($course) ?>" <?= $courseFilter === $course ? 'selected' : '' ?>><?= escape_html($course) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Search</button>
            <a href="../php/student-management.php?<?= escape_html(http_build_query(['search' => $search, 'filter' => $filter, 'course_filter' => $courseFilter, 'export' => 'csv'])) ?>">Export CSV</a>
        </form>

        <p><?= $total ?> student record(s)</p>
        <table border="1" cellpadding="8">
            <thead>
                <tr><th>Enrollment</th><th>Name</th><th>Email</th><th>Course</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php while ($student = $result->fetch_assoc()): ?>
                <?php $formId = 'student-' . (int)$student['student_id']; ?>
                <tr>
                    <td>
                        <?php if ($student['status'] === 'active'): ?>
                            <form id="<?= $formId ?>" action="../php/student-management.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="student_id" value="<?= (int)$student['student_id'] ?>">
                                <input type="hidden" name="search" value="<?= escape_html($search) ?>">
                                <input type="hidden" name="filter" value="<?= escape_html($filter) ?>">
                                <input type="hidden" name="course_filter" value="<?= escape_html($courseFilter) ?>">
                            </form>
                            <input form="<?= $formId ?>" name="enrollment_no" aria-label="Enrollment" required minlength="4" maxlength="20" pattern="[A-Za-z0-9-]+" value="<?= escape_html($student['enrollment_no']) ?>">
                        <?php else: ?>
                            <?= escape_html($student['enrollment_no']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($student['status'] === 'active'): ?>
                            <input form="<?= $formId ?>" name="name" aria-label="Name" required minlength="2" maxlength="100" pattern="[A-Za-z ]+" value="<?= escape_html($student['name']) ?>">
                        <?php else: ?>
                            <?= escape_html($student['name']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($student['status'] === 'active'): ?>
                            <input form="<?= $formId ?>" type="email" name="email" aria-label="Email" required maxlength="254" value="<?= escape_html($student['email']) ?>">
                        <?php else: ?>
                            <?= escape_html($student['email']) ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($student['status'] === 'active'): ?>
                            <select form="<?= $formId ?>" name="course" aria-label="Course" required>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= escape_html($course) ?>" <?= $student['course'] === $course ? 'selected' : '' ?>><?= escape_html($course) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <?= escape_html($student['course']) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= escape_html($student['status']) ?></td>
                    <td>
                        <?php if ($student['status'] === 'active'): ?>
                            <button type="submit" form="<?= $formId ?>">Save</button>
                            <form action="../php/student-management.php" method="post" onsubmit="return confirm('Move this student to deleted records?');" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="student_id" value="<?= (int)$student['student_id'] ?>">
                                <input type="hidden" name="search" value="<?= escape_html($search) ?>">
                                <input type="hidden" name="filter" value="<?= escape_html($filter) ?>">
                                <input type="hidden" name="course_filter" value="<?= escape_html($courseFilter) ?>">
                                <button type="submit">Delete</button>
                            </form>
                        <?php else: ?>
                            <form action="../php/student-management.php" method="post" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="student_id" value="<?= (int)$student['student_id'] ?>">
                                <input type="hidden" name="search" value="<?= escape_html($search) ?>">
                                <input type="hidden" name="filter" value="<?= escape_html($filter) ?>">
                                <input type="hidden" name="course_filter" value="<?= escape_html($courseFilter) ?>">
                                <button type="submit">Restore</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            <?php if ($total === 0): ?>
                <tr><td colspan="6">No students match this search.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <nav aria-label="Pagination">
            <?php if ($page > 1): ?>
                <a href="../php/student-management.php?<?= escape_html(http_build_query(['search' => $search, 'filter' => $filter, 'course_filter' => $courseFilter, 'page' => $page - 1])) ?>">Previous</a>
            <?php endif; ?>
            Page <?= $page ?> of <?= $totalPages ?>
            <?php if ($page < $totalPages): ?>
                <a href="../php/student-management.php?<?= escape_html(http_build_query(['search' => $search, 'filter' => $filter, 'course_filter' => $courseFilter, 'page' => $page + 1])) ?>">Next</a>
            <?php endif; ?>
        </nav>
    </main>
    <script src="../java/script.js"></script>
</body>
</html>
<?php
$statement->close();
