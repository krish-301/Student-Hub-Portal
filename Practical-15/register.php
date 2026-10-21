<?php
/**
 * Practical 9: Secure User Registration
 * Validates inputs, hashes password with bcrypt, inserts into users and students tables,
 * records in audit_logs, and stores submission record in storage/ (Practical 7).
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/form_helpers.php';
require __DIR__ . '/admin_audit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_status('register.html', 'invalid');
}

if (!csrf_is_valid()) {
    redirect_with_status('register.html', 'invalid');
}

$name = post_value('name');
$enrollment = post_value('enrollment');
$email = post_value('email');
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$confirmation = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
$gender = post_value('gender');
$dateOfBirth = post_value('date_of_birth');
$branch = post_value('branch');
$semester = post_value('semester');
$mobile = post_value('mobile');
$address = post_value('address');

$branches = ['Computer Science', 'Information Technology', 'Electronics', 'Mechanical', 'Civil'];
$semesters = array_map(static fn(int $num): string => 'Semester ' . $num, range(1, 8));
$birthDate = DateTime::createFromFormat('Y-m-d', $dateOfBirth);

$valid = preg_match("/^[A-Za-z ]{2,50}$/", $name)
    && preg_match('/^[A-Za-z0-9-]{4,20}$/', $enrollment)
    && filter_var($email, FILTER_VALIDATE_EMAIL)
    && text_length($email) <= 254
    && strlen($password) >= 8
    && strlen($password) <= 255
    && preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,255}$/', $password)
    && hash_equals($password, $confirmation)
    && in_array($gender, ['Male', 'Female', 'Other'], true)
    && $birthDate !== false && $birthDate->format('Y-m-d') === $dateOfBirth
    && in_array($branch, $branches, true)
    && in_array($semester, $semesters, true)
    && preg_match('/^[0-9]{10}$/', $mobile)
    && text_length($address) >= 10 && text_length($address) <= 500;

if (!$valid) {
    redirect_with_status('register.html', 'invalid');
}

try {
    require __DIR__ . '/mysqli_db.php';
    $connection->begin_transaction();

    // Check duplicate email or username
    $duplicateCheck = $connection->prepare(
        'SELECT username FROM users WHERE email = ? OR username = ? LIMIT 1'
    );
    $duplicateCheck->bind_param('ss', $email, $enrollment);
    $duplicateCheck->execute();
    $duplicateCheck->store_result();
    if ($duplicateCheck->num_rows > 0) {
        $duplicateCheck->close();
        $connection->rollback();
        redirect_with_status('register.html', 'duplicate');
    }
    $duplicateCheck->close();

    // Hash password with bcrypt default
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $role = 'student';
    $status = 'active';

    // 1. Insert into unified users table
    $insertUser = $connection->prepare(
        'INSERT INTO users
            (username, name, email, password_hash, role, status, gender, date_of_birth, branch, semester, mobile, address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insertUser->bind_param(
        'ssssssssssss',
        $enrollment,
        $name,
        $email,
        $passwordHash,
        $role,
        $status,
        $gender,
        $dateOfBirth,
        $branch,
        $semester,
        $mobile,
        $address
    );
    $insertUser->execute();
    $userId = (int)$connection->insert_id;
    $insertUser->close();

    // 2. Insert into students table so the student appears in student management & events
    $insertStudent = $connection->prepare(
        'INSERT INTO students (user_id, enrollment_no, name, email, course, department, status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $department = $branch . ' Department';
    $studentStatus = 'active';
    $insertStudent->bind_param(
        'issssss',
        $userId,
        $enrollment,
        $name,
        $email,
        $branch,
        $department,
        $studentStatus
    );
    $insertStudent->execute();
    $insertStudent->close();

    // 3. Record audit log
    record_admin_action(
        $connection,
        'user_registered',
        'user',
        $userId,
        $enrollment . ' registered as student',
        $enrollment,
        $userId
    );

    $connection->commit();

    // 4. File-based record storage (Practical 7)
    $submissionRecord = [
        'user_id'       => $userId,
        'name'          => $name,
        'enrollment'    => $enrollment,
        'email'         => $email,
        'gender'        => $gender,
        'date_of_birth' => $dateOfBirth,
        'branch'        => $branch,
        'semester'      => $semester,
        'mobile'        => $mobile,
        'address'       => $address,
        'submitted_at'  => date(DATE_ATOM),
    ];
    save_csv_record('registrations.csv', $submissionRecord);
    save_json_record('registrations.json', $submissionRecord);

    redirect_with_status('register.html', 'registration_success');
} catch (mysqli_sql_exception $e) {
    if (isset($connection) && $connection instanceof mysqli) {
        try {
            $connection->rollback();
        } catch (mysqli_sql_exception $ignored) {}
    }
    if ($e->getCode() === 1062) {
        redirect_with_status('register.html', 'duplicate');
    }
    error_log('StudentHub registration database error: ' . $e->getMessage());
    redirect_with_status('register.html', 'save_error');
}
