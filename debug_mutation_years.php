<?php

use App\Models\PlacementMutation;
use App\Models\AcademicYear;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$activeYear = AcademicYear::where('is_active', true)->first();
echo "Active Year: " . ($activeYear ? "{$activeYear->name} ({$activeYear->id})" : "None") . "\n\n";

$counts = PlacementMutation::select('academic_year_id', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
    ->groupBy('academic_year_id')
    ->get();

echo "Mutation Academic Year distribution:\n";
foreach ($counts as $c) {
    if (!$c->academic_year_id) {
        echo "- NULL: {$c->count}\n";
        continue;
    }
    $year = AcademicYear::find($c->academic_year_id);
    echo "- " . ($year ? "{$year->name}" : "Unknown") . " ({$c->academic_year_id}): {$c->count}\n";
}
