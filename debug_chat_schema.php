<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

$table = 'chat_messages';
if (!Schema::hasTable($table)) {
    die("Table $table does not exist.\n");
}

$columns = Schema::getColumnListing($table);
echo "Columns in $table:\n";
foreach ($columns as $column) {
    echo "- $column\n";
}
