<?php

try {
    require __DIR__ . '/db.php';

    $statement = $pdo->prepare('SELECT name, email FROM students WHERE email = :email');
    $statement->execute(['email' => 'alex@example.com']);
    $student = $statement->fetch();
} catch (PDOException $exception) {
    error_log('StudentHub database test failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Check the database settings and try again.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StudentHub Database Test</title>
</head>
<body>
    <h1>Database connected successfully</h1>
    <?php if ($student): ?>
        <p>Prepared statement found: <?= htmlspecialchars($student['name'], ENT_QUOTES, 'UTF-8') ?>
            (<?= htmlspecialchars($student['email'], ENT_QUOTES, 'UTF-8') ?>)</p>
    <?php else: ?>
        <p>Prepared statement ran successfully; no matching student was found.</p>
    <?php endif; ?>
</body>
</html>
