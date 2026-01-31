<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\FcmService;
use App\Models\User;
use App\Models\Student; // Assuming Student model exists and is linked to User, or we use User directly
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    protected $fcmService;

    public function __construct(FcmService $fcmService)
    {
        $this->fcmService = $fcmService;
    }

    public function send(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'target_type' => 'required|in:ALL,DUDI,STUDENT',
            'target_id' => 'nullable|string', // Can be DUDI name or Student ID
        ]);

        $title = $request->title;
        $message = $request->message;
        $targetType = $request->target_type;
        $targetId = $request->target_id;

        $tokens = [];

        try {
            switch ($targetType) {
                case 'ALL':
                    // Send to ALL Students
                    // Ideally, we might use a Topic, but collecting tokens allows more control if Topics aren't set up
                    // Let's assume we want to target all users with role STUDENT
                    $tokens = User::where('role', 'STUDENT')
                        ->whereNotNull('fcm_token')
                        ->pluck('fcm_token')
                        ->toArray();
                    break;

                case 'DUDI':
                    // Send to Students in specific DUDI
                    if (!$targetId) {
                        return response()->json(['message' => 'Target DUDI is required'], 422);
                    }

                    // Find placements where DUDI name matches targetId
                    $studentIds = \App\Models\Placement::whereHas('dudi', function ($q) use ($targetId) {
                        $q->where('name', $targetId);
                    })
                        ->pluck('student_id');

                    $tokens = User::whereIn('id', $studentIds)
                        ->whereNotNull('fcm_token')
                        ->pluck('fcm_token')
                        ->toArray();
                    break;

                case 'STUDENT':
                    if (!$targetId) {
                        return response()->json(['message' => 'Target Student ID is required'], 422);
                    }
                    $user = User::find($targetId);
                    if ($user && $user->fcm_token) {
                        $tokens = [$user->fcm_token];
                    }
                    break;
            }

            // Filter empty
            $tokens = array_filter($tokens);
            $tokens = array_values(array_unique($tokens));

            if (empty($tokens)) {
                return response()->json(['message' => 'No target devices found (no tokens).'], 404);
            }

            // Send
            $this->fcmService->sendMulticast($tokens, $title, $message);

            return response()->json(['message' => 'Notification sent successfully', 'recipient_count' => count($tokens)]);

        } catch (\Exception $e) {
            Log::error('Broadcast Error: ' . $e->getMessage());
            return response()->json(['message' => 'Failed to send notification: ' . $e->getMessage()], 500);
        }
    }
}
