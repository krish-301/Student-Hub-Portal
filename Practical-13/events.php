<?php
require __DIR__ . '/../auth.php';

function api_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function api_error(int $status, string $message): void
{
    api_response($status, ['success' => false, 'error' => $message]);
}

function api_bind(mysqli_stmt $statement, string $types, array &$parameters): void
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

set_exception_handler(static function (Throwable $exception): void {
    error_log('StudentHub events API error: ' . $exception->getMessage());
    api_error(500, 'Event service encountered an error.');
});

require_role('admin', true);

try {
    require __DIR__ . '/../mysqli_db.php';
} catch (mysqli_sql_exception $exception) {
    error_log('StudentHub events API connection failed: ' . $exception->getMessage());
    api_error(503, 'Event service is temporarily unavailable.');
}

$method = $_SERVER['REQUEST_METHOD'];
$categories = ['General', 'Workshop', 'Festival', 'Seminar', 'Competition', 'Other'];
$statuses = ['open', 'closed'];
$payload = [];

if ($method === 'GET') {
    $searchValue = $_GET['search'] ?? '';
    $search = is_string($searchValue) ? trim($searchValue) : '';
    $search = function_exists('mb_substr') ? mb_substr($search, 0, 100, 'UTF-8') : substr($search, 0, 100);
    $categoryValue = $_GET['category'] ?? '';
    $category = is_string($categoryValue) ? $categoryValue : '';
    $statusValue = $_GET['status'] ?? 'all';
    $status = is_string($statusValue) ? $statusValue : 'all';
    if (($category !== '' && !in_array($category, $categories, true))
        || !in_array($status, ['all', 'open', 'closed'], true)
    ) {
        api_error(422, 'Invalid event filter.');
    }

    $conditions = ["status <> 'deleted'"];
    $types = '';
    $parameters = [];
    if ($search !== '') {
        $conditions[] = '(title LIKE ? OR description LIKE ? OR category LIKE ?)';
        $term = '%' . $search . '%';
        $types .= 'sss';
        array_push($parameters, $term, $term, $term);
    }
    if ($category !== '') {
        $conditions[] = 'category = ?';
        $types .= 's';
        $parameters[] = $category;
    }
    if ($status !== 'all') {
        $conditions[] = 'status = ?';
        $types .= 's';
        $parameters[] = $status;
    }

    $pageValue = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $page = max(1, $pageValue === false ? 1 : $pageValue);
    $pageSize = 10;
    $where = ' WHERE ' . implode(' AND ', $conditions);
    $count = $connection->prepare('SELECT COUNT(*) AS total FROM events' . $where);
    api_bind($count, $types, $parameters);
    $count->execute();
    $total = (int)$count->get_result()->fetch_assoc()['total'];
    $count->close();

    $totalPages = max(1, (int)ceil($total / $pageSize));
    $page = min($page, $totalPages);
    $queryTypes = $types . 'ii';
    $queryParameters = $parameters;
    $queryParameters[] = $pageSize;
    $queryParameters[] = ($page - 1) * $pageSize;
    $statement = $connection->prepare(
        'SELECT event_id, title, description, event_date, category, status
         FROM events' . $where . ' ORDER BY event_date DESC LIMIT ? OFFSET ?'
    );
    api_bind($statement, $queryTypes, $queryParameters);
    $statement->execute();
    $rows = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement->close();

    api_response(200, [
        'success' => true,
        'data' => $rows,
        'pagination' => ['page' => $page, 'pages' => $totalPages, 'total' => $total],
        'csrf_token' => csrf_token(),
    ]);
}

if (!in_array($method, ['POST', 'PUT', 'DELETE'], true)) {
    header('Allow: GET, POST, PUT, DELETE');
    api_error(405, 'Method not allowed.');
}

$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($submittedToken) || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $submittedToken)
) {
    api_error(403, 'Security token is invalid or expired. Refresh and try again.');
}

$eventId = null;
if ($method === 'POST') {
    $rawPayload = file_get_contents('php://input');
    $decodedPayload = is_string($rawPayload) ? json_decode($rawPayload, true) : null;
    if (!is_array($decodedPayload)) {
        api_error(400, 'Request body must contain valid JSON.');
    }
    $payload = $decodedPayload;
} elseif ($method === 'PUT') {
    $eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    $rawPayload = file_get_contents('php://input');
    $decodedPayload = is_string($rawPayload) ? json_decode($rawPayload, true) : null;
    if ($eventId === false || $eventId < 1 || !is_array($decodedPayload)) {
        api_error(400, 'Event ID and valid JSON are required.');
    }
    $payload = $decodedPayload;
} else {
    $eventId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if ($eventId === false || $eventId < 1) {
        api_error(400, 'A valid event ID is required.');
    }
}

try {
    if ($method === 'DELETE') {
        $connection->begin_transaction();
        $find = $connection->prepare('SELECT title FROM events WHERE event_id = ? AND status <> \'deleted\' FOR UPDATE');
        $find->bind_param('i', $eventId);
        $find->execute();
        $event = $find->get_result()->fetch_assoc();
        $find->close();
        if (!$event) {
            $connection->rollback();
            api_error(404, 'Event not found.');
        }

        $delete = $connection->prepare('UPDATE events SET status = \'deleted\' WHERE event_id = ?');
        $delete->bind_param('i', $eventId);
        $delete->execute();
        $delete->close();
        $action = 'deleted';
        $changedBy = $_SESSION['username'];
        $audit = $connection->prepare(
            'INSERT INTO event_audit_logs (event_id, event_title, action, changed_by) VALUES (?, ?, ?, ?)'
        );
        $audit->bind_param('isss', $eventId, $event['title'], $action, $changedBy);
        $audit->execute();
        $audit->close();
        $connection->commit();
        api_response(200, ['success' => true, 'message' => 'Event deleted.']);
    }

    $title = $payload['title'] ?? null;
    $description = $payload['description'] ?? '';
    $eventDateInput = $payload['event_date'] ?? null;
    $category = $payload['category'] ?? 'General';
    $status = $payload['status'] ?? 'open';
    if (!is_string($title) || !is_string($description) || !is_string($eventDateInput)
        || !is_string($category) || !is_string($status)
    ) {
        api_error(422, 'Event fields must be strings.');
    }
    $length = static fn(string $value): int => function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $eventDateInput)) {
        api_error(422, 'Event date must be a valid date and time.');
    }
    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $eventDateInput);
    $dateErrors = DateTime::getLastErrors();
    $validDate = $date instanceof DateTime
        && ($dateErrors === false || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $date->format('Y-m-d\TH:i') === $eventDateInput;
    if (trim($title) === '' || $length($title) > 150 || $length($description) > 5000
        || !$validDate || !in_array($category, $categories, true) || !in_array($status, $statuses, true)
    ) {
        api_error(422, 'Check the title, description, date, category, and status.');
    }
    $title = trim($title);
    $description = trim($description);
    $eventDate = $date->format('Y-m-d H:i:s');
    $changedBy = $_SESSION['username'];
    $connection->begin_transaction();

    if ($method === 'POST') {
        $statement = $connection->prepare(
            'INSERT INTO events (title, description, event_date, category, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $statement->bind_param('sssss', $title, $description, $eventDate, $category, $status);
        $statement->execute();
        $eventId = $connection->insert_id;
        $statement->close();
        $action = 'created';
        $message = 'Event added.';
        $httpStatus = 201;
    } else {
        $check = $connection->prepare('SELECT event_id FROM events WHERE event_id = ? AND status <> \'deleted\'');
        $check->bind_param('i', $eventId);
        $check->execute();
        $check->store_result();
        $exists = $check->num_rows > 0;
        $check->close();
        if (!$exists) {
            $connection->rollback();
            api_error(404, 'Event not found.');
        }
        $statement = $connection->prepare(
            'UPDATE events SET title = ?, description = ?, event_date = ?, category = ?, status = ?
             WHERE event_id = ? AND status <> \'deleted\''
        );
        $statement->bind_param('sssssi', $title, $description, $eventDate, $category, $status, $eventId);
        $statement->execute();
        $statement->close();
        $action = 'updated';
        $message = 'Event updated.';
        $httpStatus = 200;
    }

    $audit = $connection->prepare(
        'INSERT INTO event_audit_logs (event_id, event_title, action, changed_by) VALUES (?, ?, ?, ?)'
    );
    $audit->bind_param('isss', $eventId, $title, $action, $changedBy);
    $audit->execute();
    $audit->close();
    $connection->commit();
    api_response($httpStatus, ['success' => true, 'message' => $message, 'data' => [
        'event_id' => $eventId,
        'title' => $title,
        'description' => $description,
        'event_date' => $eventDate,
        'category' => $category,
        'status' => $status,
    ]]);
} catch (mysqli_sql_exception $exception) {
    try {
        $connection->rollback();
    } catch (mysqli_sql_exception $rollbackException) {
        error_log('StudentHub events API rollback failed: ' . $rollbackException->getMessage());
    }
    error_log('StudentHub events API database error: ' . $exception->getMessage());
    api_error(500, 'Event could not be saved.');
}
