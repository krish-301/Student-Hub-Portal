<?php
require __DIR__ . '/auth.php';
require_role('admin');

try {
    require __DIR__ . '/mysqli_db.php';
} catch (mysqli_sql_exception $exception) {
    error_log('StudentHub event management connection failed: ' . $exception->getMessage());
    http_response_code(503);
    exit('Event management is temporarily unavailable.');
}

$categories = ['General', 'Workshop', 'Festival', 'Seminar', 'Competition', 'Other'];
$statuses = ['open', 'closed'];
$messages = [
    'added' => 'Event added.',
    'updated' => 'Event updated.',
    'deleted' => 'Event deleted.',
    'invalid' => 'Check the event details and try again.',
    'poster_invalid' => 'Poster must be a JPEG, PNG, or WebP image no larger than 2 MB.',
    'error' => 'The event could not be saved. Please try again.'
];

function event_redirect(string $status, string $search, string $categoryFilter, string $statusFilter): void
{
    header('Location: event-management.php?' . http_build_query([
        'status' => $status,
        'search' => $search,
        'category' => $categoryFilter,
        'filter' => $statusFilter,
    ]));
    exit;
}

function event_post_value(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function event_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function event_poster_url(?string $posterPath): string
{
    return is_string($posterPath)
        && preg_match('#^uploads/event-posters/[a-f0-9]{32}\.(jpg|png|webp)$#', $posterPath)
        ? '../' . $posterPath
        : '';
}

function event_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function event_bind_parameters(mysqli_stmt $statement, string $types, array &$parameters): void
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

function event_remove_poster(?string $relativePath): void
{
    if (!is_string($relativePath)
        || !preg_match('#^uploads/event-posters/[a-f0-9]{32}\.(jpg|png|webp)$#', $relativePath)
    ) {
        return;
    }
    $poster = dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($poster) && !unlink($poster)) {
        error_log('StudentHub could not remove event poster: ' . $relativePath);
    }
}

function event_upload_poster(): ?string
{
    if (!isset($_FILES['poster'])) {
        return null;
    }
    $upload = $_FILES['poster'];
    if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['size'])) {
        throw new RuntimeException('Invalid event poster upload.');
    }
    if ($upload['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (!is_string($upload['tmp_name'])
        || $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])
        || $upload['size'] < 1 || $upload['size'] > 2 * 1024 * 1024
    ) {
        throw new RuntimeException('Invalid event poster upload.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $extensionByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $mime = $finfo->file($upload['tmp_name']);
    if (!is_string($mime) || !isset($extensionByMime[$mime]) || @getimagesize($upload['tmp_name']) === false) {
        throw new RuntimeException('Invalid event poster image.');
    }

    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'event-posters';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create event poster directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensionByMime[$mime];
    if (!move_uploaded_file($upload['tmp_name'], $directory . DIRECTORY_SEPARATOR . $filename)) {
        throw new RuntimeException('Could not save event poster.');
    }
    return 'uploads/event-posters/' . $filename;
}

function event_audit(mysqli $connection, ?int $eventId, string $title, string $action, string $changedBy): void
{
    $statement = $connection->prepare(
        'INSERT INTO event_audit_logs (event_id, event_title, action, changed_by) VALUES (?, ?, ?, ?)'
    );
    $statement->bind_param('isss', $eventId, $title, $action, $changedBy);
    $statement->execute();
    $statement->close();
}

$request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$searchValue = $request['search'] ?? '';
$search = is_string($searchValue) ? trim($searchValue) : '';
$search = function_exists('mb_substr') ? mb_substr($search, 0, 100, 'UTF-8') : substr($search, 0, 100);
$categoryValue = $request['category'] ?? '';
$categoryFilter = is_string($categoryValue) ? $categoryValue : '';
if ($categoryFilter !== '' && !in_array($categoryFilter, $categories, true)) {
    $categoryFilter = '';
}
$statusValue = $request['filter'] ?? 'all';
$statusFilter = is_string($statusValue) ? $statusValue : 'all';
if (!in_array($statusFilter, ['all', 'open', 'closed', 'deleted'], true)) {
    $statusFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        event_redirect('invalid', $search, $categoryFilter, $statusFilter);
    }

    $action = event_post_value('action');
    $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
    $newPoster = null;
    try {
        if ($action === 'delete' && $eventId !== false && $eventId > 0) {
            $connection->begin_transaction();
            $find = $connection->prepare('SELECT title, poster_path FROM events WHERE event_id = ? AND status <> \'deleted\' FOR UPDATE');
            $find->bind_param('i', $eventId);
            $find->execute();
            $existing = $find->get_result()->fetch_assoc();
            $find->close();
            if (!$existing) {
                $connection->rollback();
                event_redirect('error', $search, $categoryFilter, $statusFilter);
            }
            $delete = $connection->prepare('UPDATE events SET status = \'deleted\', poster_path = NULL WHERE event_id = ?');
            $delete->bind_param('i', $eventId);
            $delete->execute();
            $delete->close();
            event_audit($connection, $eventId, $existing['title'], 'deleted', $_SESSION['username']);
            $connection->commit();
            event_remove_poster($existing['poster_path']);
            event_redirect('deleted', $search, $categoryFilter, $statusFilter);
        }

        if ($action !== 'save') {
            event_redirect('invalid', $search, $categoryFilter, $statusFilter);
        }

        $title = event_post_value('title');
        $description = event_post_value('description');
        $eventDate = event_post_value('event_date');
        $category = event_post_value('category');
        $status = event_post_value('status');
        $date = DateTime::createFromFormat('Y-m-d\TH:i', $eventDate);
        $valid = $title !== '' && event_text_length($title) <= 150
            && event_text_length($description) <= 5000
            && $date instanceof DateTime && $date->format('Y-m-d\TH:i') === $eventDate
            && in_array($category, $categories, true)
            && in_array($status, $statuses, true)
            && $eventId !== false && $eventId >= 0;
        if (!$valid) {
            event_redirect('invalid', $search, $categoryFilter, $statusFilter);
        }
        $eventDate = $date->format('Y-m-d H:i:s');

        $oldPoster = null;
        if ($eventId > 0) {
            $find = $connection->prepare('SELECT title, poster_path FROM events WHERE event_id = ? AND status <> \'deleted\'');
            $find->bind_param('i', $eventId);
            $find->execute();
            $existing = $find->get_result()->fetch_assoc();
            $find->close();
            if (!$existing) {
                event_redirect('error', $search, $categoryFilter, $statusFilter);
            }
            $oldPoster = $existing['poster_path'];
        }

        $newPoster = event_upload_poster();
        $posterPath = $newPoster ?? $oldPoster;
        $connection->begin_transaction();
        if ($eventId > 0) {
            $statement = $connection->prepare(
                'UPDATE events SET title = ?, description = ?, event_date = ?, category = ?, status = ?, poster_path = ?
                 WHERE event_id = ? AND status <> \'deleted\''
            );
            $statement->bind_param('ssssssi', $title, $description, $eventDate, $category, $status, $posterPath, $eventId);
            $statement->execute();
            $statement->close();
            event_audit($connection, $eventId, $title, 'updated', $_SESSION['username']);
            $auditAction = 'updated';
        } else {
            $statement = $connection->prepare(
                'INSERT INTO events (title, description, event_date, category, status, poster_path)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $statement->bind_param('ssssss', $title, $description, $eventDate, $category, $status, $posterPath);
            $statement->execute();
            $eventId = $connection->insert_id;
            $statement->close();
            event_audit($connection, $eventId, $title, 'created', $_SESSION['username']);
            $auditAction = 'added';
        }
        $connection->commit();
        if ($newPoster !== null && $oldPoster !== null && $oldPoster !== $newPoster) {
            event_remove_poster($oldPoster);
        }
        event_redirect($auditAction, $search, $categoryFilter, $statusFilter);
    } catch (RuntimeException $exception) {
        if ($newPoster !== null) {
            event_remove_poster($newPoster);
        }
        try {
            $connection->rollback();
        } catch (mysqli_sql_exception $rollbackException) {
            error_log('StudentHub event rollback failed: ' . $rollbackException->getMessage());
        }
        $uploadError = strpos($exception->getMessage(), 'poster') !== false;
        event_redirect($uploadError ? 'poster_invalid' : 'error', $search, $categoryFilter, $statusFilter);
    } catch (mysqli_sql_exception $exception) {
        if ($newPoster !== null) {
            event_remove_poster($newPoster);
        }
        try {
            $connection->rollback();
        } catch (mysqli_sql_exception $rollbackException) {
            error_log('StudentHub event rollback failed: ' . $rollbackException->getMessage());
        }
        error_log('StudentHub event management error: ' . $exception->getMessage());
        event_redirect('error', $search, $categoryFilter, $statusFilter);
    }
}

$conditions = [];
$parameters = [];
$types = '';
if ($statusFilter !== 'all') {
    $conditions[] = 'status = ?';
    $types .= 's';
    $parameters[] = $statusFilter;
}
if ($search !== '') {
    $conditions[] = '(title LIKE ? OR description LIKE ? OR category LIKE ?)';
    $term = '%' . $search . '%';
    $types .= 'sss';
    array_push($parameters, $term, $term, $term);
}
if ($categoryFilter !== '') {
    $conditions[] = 'category = ?';
    $types .= 's';
    $parameters[] = $categoryFilter;
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$pageSize = 10;
$pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
$page = max(1, $pageValue === false ? 1 : $pageValue);
$count = $connection->prepare('SELECT COUNT(*) AS total FROM events' . $where);
event_bind_parameters($count, $types, $parameters);
$count->execute();
$total = (int)$count->get_result()->fetch_assoc()['total'];
$count->close();
$totalPages = max(1, (int)ceil($total / $pageSize));
$page = min($page, $totalPages);
$offset = ($page - 1) * $pageSize;
$queryTypes = $types . 'ii';
$queryParameters = $parameters;
$queryParameters[] = $pageSize;
$queryParameters[] = $offset;
$statement = $connection->prepare(
    'SELECT event_id, title, description, event_date, category, status, poster_path
     FROM events' . $where . ' ORDER BY event_date DESC LIMIT ? OFFSET ?'
);
event_bind_parameters($statement, $queryTypes, $queryParameters);
$statement->execute();
$events = $statement->get_result();
$csrfToken = event_escape(csrf_token());
$statusMessage = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
$successStatuses = ['added', 'updated', 'deleted'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Management</title>
    <link rel="stylesheet" href="../css/script.css">
</head>
<body>
    <h1 align="center">Event Management</h1>
    <button id="themeBtn" type="button">🌙 Theme</button>
    <nav><a href="../pages/admin-dashboard.html">Admin Dashboard</a></nav>
    <?php if (isset($messages[$statusMessage])): ?>
        <p class="form-message <?= in_array($statusMessage, $successStatuses, true) ? 'success' : 'error' ?>" role="status">
            <?= event_escape($messages[$statusMessage]) ?>
        </p>
    <?php endif; ?>

    <main class="container">
        <h2><?= isset($_GET['edit']) ? 'Edit event' : 'Add event' ?></h2>
        <?php
        $editing = null;
        $editId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
        if ($editId !== false && $editId > 0) {
            $editQuery = $connection->prepare('SELECT * FROM events WHERE event_id = ? AND status <> \'deleted\'');
            $editQuery->bind_param('i', $editId);
            $editQuery->execute();
            $editing = $editQuery->get_result()->fetch_assoc();
            $editQuery->close();
        }
        ?>
        <form action="../php/event-management.php" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="event_id" value="<?= (int)($editing['event_id'] ?? 0) ?>">
            <input type="hidden" name="search" value="<?= event_escape($search) ?>">
            <input type="hidden" name="category" value="<?= event_escape($categoryFilter) ?>">
            <input type="hidden" name="filter" value="<?= event_escape($statusFilter) ?>">
            <label>Title <input name="title" required maxlength="150" value="<?= event_escape($editing['title'] ?? '') ?>"></label>
            <label>Description <textarea name="description" maxlength="5000"><?= event_escape($editing['description'] ?? '') ?></textarea></label>
            <label>Date and time <input type="datetime-local" name="event_date" required value="<?= isset($editing['event_date']) ? event_escape(date('Y-m-d\TH:i', strtotime($editing['event_date']))) : '' ?>"></label>
            <label>Category
                <select name="category" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= event_escape($category) ?>" <?= ($editing['category'] ?? 'General') === $category ? 'selected' : '' ?>><?= event_escape($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select name="status" required>
                    <?php foreach ($statuses as $status): ?>
                        <option value="<?= $status ?>" <?= ($editing['status'] ?? 'open') === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Poster (JPEG, PNG, WebP; max 2 MB)
                <input type="file" name="poster" accept="image/jpeg,image/png,image/webp">
            </label>
            <?php if ($editing && ($posterUrl = event_poster_url($editing['poster_path'])) !== ''): ?>
                <p>Current poster: <img src="<?= event_escape($posterUrl) ?>" alt="Current event poster" width="120"></p>
            <?php endif; ?>
            <button type="submit"><?= $editing ? 'Save changes' : 'Add event' ?></button>
            <?php if ($editing): ?><a href="../php/event-management.php">Cancel edit</a><?php endif; ?>
        </form>

        <h2>Search and filter events</h2>
        <form action="../php/event-management.php" method="get">
            <label>Search <input type="search" name="search" maxlength="100" value="<?= event_escape($search) ?>"></label>
            <label>Category
                <select name="category">
                    <option value="">All categories</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= event_escape($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= event_escape($category) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select name="filter">
                    <?php foreach (['all', 'open', 'closed', 'deleted'] as $status): ?>
                        <option value="<?= $status ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= ucfirst($status) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit">Filter</button>
        </form>

        <p><?= $total ?> event(s)</p>
        <table border="1" cellpadding="8">
            <thead><tr><th>Poster</th><th>Title</th><th>Date</th><th>Category</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            <?php while ($event = $events->fetch_assoc()): ?>
                <tr>
                    <td><?php $posterUrl = event_poster_url($event['poster_path']); ?><?php if ($posterUrl !== ''): ?><img src="<?= event_escape($posterUrl) ?>" alt="Poster for <?= event_escape($event['title']) ?>" width="100"><?php else: ?>No poster<?php endif; ?></td>
                    <td><?= event_escape($event['title']) ?></td>
                    <td><?= event_escape($event['event_date']) ?></td>
                    <td><?= event_escape($event['category']) ?></td>
                    <td><?= event_escape($event['status']) ?></td>
                    <td>
                        <?php if ($event['status'] !== 'deleted'): ?>
                            <a href="../php/event-management.php?<?= event_escape(http_build_query(['edit' => $event['event_id'], 'search' => $search, 'category' => $categoryFilter, 'filter' => $statusFilter, 'page' => $page])) ?>">Edit</a>
                            <form action="../php/event-management.php" method="post" onsubmit="return confirm('Delete this event?');" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="event_id" value="<?= (int)$event['event_id'] ?>">
                                <input type="hidden" name="search" value="<?= event_escape($search) ?>">
                                <input type="hidden" name="category" value="<?= event_escape($categoryFilter) ?>">
                                <input type="hidden" name="filter" value="<?= event_escape($statusFilter) ?>">
                                <button type="submit">Delete</button>
                            </form>
                        <?php else: ?>
                            Deleted
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            <?php if ($total === 0): ?><tr><td colspan="6">No events found.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <nav aria-label="Pagination">
            <?php if ($page > 1): ?><a href="?<?= event_escape(http_build_query(['search' => $search, 'category' => $categoryFilter, 'filter' => $statusFilter, 'page' => $page - 1])) ?>">Previous</a><?php endif; ?>
            Page <?= $page ?> of <?= $totalPages ?>
            <?php if ($page < $totalPages): ?><a href="?<?= event_escape(http_build_query(['search' => $search, 'category' => $categoryFilter, 'filter' => $statusFilter, 'page' => $page + 1])) ?>">Next</a><?php endif; ?>
        </nav>
    </main>
    <script src="../java/script.js"></script>
</body>
</html>
<?php
$statement->close();
