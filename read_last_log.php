<?php
$logPath = 'storage/logs/laravel.log';
if (!file_exists($logPath)) {
    die("Log file not found.\n");
}

$file = new SplFileObject($logPath, 'r');
$file->seek(PHP_INT_MAX);
$lastLine = $file->key();

$start = max(0, $lastLine - 100);
$file->seek($start);

while (!$file->eof()) {
    echo $file->current();
    $file->next();
}
