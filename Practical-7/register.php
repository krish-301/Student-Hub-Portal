<?php
require __DIR__ . '/form_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_status('register.html', 'invalid');
}

$name = post_value('name');
$enrollment = post_value('enrollment');
$email = filter_var(post_value('email'), FILTER_SANITIZE_EMAIL);
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
$confirmation = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
$gender = post_value('gender');
$dateOfBirth = post_value('date_of_birth');
$branch = post_value('branch');
$semester = post_value('semester');
$mobile = post_value('mobile');
$address = post_value('address');
$branches = ['Computer Science', 'Information Technology', 'Electronics', 'Mechanical', 'Civil'];
$semesters = array_map(static fn(int $number): string => 'Semester ' . $number, range(1, 8));
$birthDate = DateTime::createFromFormat('Y-m-d', $dateOfBirth);

$valid = preg_match("/^[A-Za-z ]{2,50}$/", $name)
    && preg_match('/^[A-Za-z0-9-]{4,20}$/', $enrollment)
    && filter_var($email, FILTER_VALIDATE_EMAIL)
    && preg_match('/^(?=.*[A-Za-z])(?=.*\d).{8,}$/', $password)
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

$saved = save_csv_record('registrations.csv', [
    'name' => $name,
    'enrollment' => $enrollment,
    'email' => $email,
    'gender' => $gender,
    'date_of_birth' => $dateOfBirth,
    'branch' => $branch,
    'semester' => $semester,
    'mobile' => $mobile,
    'address' => $address,
    'submitted_at' => date(DATE_ATOM)
]);

redirect_with_status('register.html', $saved ? 'registration_success' : 'save_error');
