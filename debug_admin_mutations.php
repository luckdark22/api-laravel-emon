<?php

use App\Models\PlacementMutation;
use App\Http\Controllers\Api\PlacementMutationController;
use Illuminate\Http\Request;

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new PlacementMutationController();
$request = Request::create('/api/placements/mutations', 'GET');
$response = $controller->index($request);

header('Content-Type: application/json');
echo $response->getContent();
