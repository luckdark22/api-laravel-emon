<?php

use App\Models\User;
use App\Models\Placement;
use App\Models\Teacher;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Target Teacher (Bambang)
$teacherName = 'Bambang Sudibyo supoto';
$teacher = User::where('name', 'like', "%$teacherName%")->where('role', 'TEACHER')->first();

if (!$teacher) {
    // Fallback to strict 'Bambang Sudibyo' if the 'supoto' one is gone
    $teacher = User::where('name', 'like', 'Bambang Sudibyo%')->where('role', 'TEACHER')->first();
}

if (!$teacher) {
    echo "Teacher '$teacherName' not found. Aborting.\n";
    exit;
}

echo "Target Teacher: {$teacher->name} (ID: {$teacher->id})\n";

// 2. Target Student (Siti Aisyah)
$student = User::where('name', 'like', 'Siti Aisyah%')->first();
if (!$student) {
    // Fallback to any active student
    $placement = Placement::where('status', 'ACTIVE')->first();
    if ($placement) {
        $student = User::find($placement->student_id);
    }
}

if (!$student) {
    echo "No student found to assign.\n";
    exit;
}
echo "Target Student: {$student->name} (ID: {$student->id})\n";

// 3. Update Placement
$placement = Placement::where('student_id', $student->id)->where('status', 'ACTIVE')->first();

if ($placement) {
    $oldTeacherId = $placement->teacher_id;
    $placement->teacher_id = $teacher->id;
    $placement->save();
    echo "SUCCESS: Transferred student '{$student->name}' from Teacher ID '$oldTeacherId' to '{$teacher->name}' (ID: {$teacher->id}).\n";
    echo "Please refresh the Teacher Dashboard/Journal Page.\n";
} else {
    echo "No ACTIVE placement found for this student.\n";
}
