<?php

use App\Models\Placement;
use App\Models\User;
use App\Models\AcademicYear;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Find Sudirman
$teacher = User::where('name', 'like', '%Sudirman%')->first();
if (!$teacher) {
    echo "Teacher Sudirman not found.\n";
    exit;
}

echo "Found Teacher: {$teacher->name} (ID: {$teacher->id})\n";

// 2. Active Year
$activeYear = AcademicYear::where('is_active', true)->first();
echo "Active Year: " . ($activeYear ? $activeYear->name : "None") . "\n\n";

// 3. Current placements logic simulation
$query = Placement::with(['student.user', 'dudi'])
    ->where('teacher_id', $teacher->id);

if ($activeYear) {
    echo "Filtering by academic_year_id: {$activeYear->id}\n";
    $query->where('academic_year_id', $activeYear->id);

    echo "Filtering by student.academic_year: {$activeYear->name}\n";
    $query->whereHas('student', function ($q) use ($activeYear) {
        $q->where('academic_year', $activeYear->name);
        $q->whereNull('deleted_at');
    });
}

$placements = $query->get();

echo "\nPlacements Found (" . $placements->count() . "):\n";
foreach ($placements as $p) {
    echo "- Placement ID: {$p->id}\n";
    echo "  Student: " . ($p->student->user->name ?? 'Unknown') . " (ID: {$p->student_id})\n";
    echo "  DUDI: " . ($p->dudi->name ?? 'Unknown') . "\n";
    echo "  Status: {$p->status}\n";
    echo "  Academic Year Registered: " . ($p->student->academic_year ?? 'N/A') . "\n";
    echo "  Deleted At: " . ($p->student->deleted_at ?? 'Active') . "\n";
}

echo "\nUnique Students by student_id:\n";
$unique = $placements->unique('student_id');
foreach ($unique as $p) {
    echo "- " . ($p->student->user->name ?? 'Unknown') . "\n";
}
