<?php
require __DIR__ . '/form_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_status('contact.html', 'invalid');
}

$name = post_value('name');
$email = filter_var(post_value('email'), FILTER_SANITIZE_EMAIL);
$phone = post_value('phone');
$subject = post_value('subject');
$message = post_value('message');

$valid = preg_match("/^[A-Za-z ]{2,50}$/", $name)
    && filter_var($email, FILTER_VALIDATE_EMAIL)
    && preg_match('/^[0-9]{10}$/', $phone)
    && preg_match("/^[A-Za-z0-9 .,!?'-]{3,80}$/", $subject)
    && text_length($message) >= 10 && text_length($message) <= 500;

if (!$valid) {
    redirect_with_status('contact.html', 'invalid');
}

$saved = save_csv_record('contacts.csv', [
    'name' => $name,
    'email' => $email,
    'phone' => $phone,
    'subject' => $subject,
    'message' => $message,
    'submitted_at' => date(DATE_ATOM)
]);

redirect_with_status('contact.html', $saved ? 'contact_success' : 'save_error');
