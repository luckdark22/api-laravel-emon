<?php

use App\Models\User;
use App\Models\Placement;
use App\Models\Journal;
use App\Models\Teacher;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. List All Placements (Limit 10)
$allPlacements = Placement::with(['student.user', 'teacher.user'])->take(10)->get();

echo "Total Placements in DB: " . Placement::count() . "\n";
foreach ($allPlacements as $p) {
    echo "Placement ID: {$p->id}\n";
    echo "  Student: " . ($p->student->user->name ?? 'Unknown') . "\n";
    echo "  Teacher ID in Placement: {$p->teacher_id}\n";
    echo "  Teacher Name: " . ($p->teacher->user->name ?? 'UNKNOWN TEACHER') . "\n";
    echo "--------------------------\n";
}

// 2. List All Teachers
$teachers = User::where('role', 'TEACHER')->get();
echo "\nList of All Teachers:\n";
foreach ($teachers as $t) {
    echo "ID: {$t->id} | Name: {$t->name}\n";
}
