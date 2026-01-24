<?php

use App\Models\Placement;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$statuses = Placement::select('status', \Illuminate\Support\Facades\DB::raw('count(*) as count'))
    ->groupBy('status')
    ->get();

echo "Placement Status distribution:\n";
foreach ($statuses as $s) {
    echo "- {$s->status}: {$s->count}\n";
}
