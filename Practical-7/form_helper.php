<?php
function post_value(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function redirect_with_status(string $page, string $status): void
{
    header('Location: ../pages/' . $page . '?status=' . rawurlencode($status));
    exit;
}

function save_csv_record(string $filename, array $record): bool
{
    $directory = dirname(__DIR__) . '/data';
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return false;
    }

    $file = fopen($directory . '/' . $filename, 'a+');
    if ($file === false) {
        return false;
    }
    if (!flock($file, LOCK_EX)) {
        fclose($file);
        return false;
    }

    $isNewFile = fstat($file)['size'] === 0;
    $cells = array_map(static function ($value): string {
        $value = (string)$value;
        return preg_match('/^[\s\x00-\x1F]*[=+\-@]/u', $value) ? "'" . $value : $value;
    }, array_values($record));

    $saved = true;
    if ($isNewFile) {
        $saved = fwrite($file, "\xEF\xBB\xBF") !== false
            && fputcsv($file, array_keys($record), ',', '"', '') !== false;
    }
    $saved = $saved && fputcsv($file, $cells, ',', '"', '') !== false;

    flock($file, LOCK_UN);
    fclose($file);
    return $saved;
}
