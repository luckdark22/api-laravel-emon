<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatMessage;
use App\Models\Placement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $placementId = $request->placementId;

        if (!$placementId) {
            // Get placement based on user role
            if ($user->role === User::ROLE_STUDENT) {
                $placement = Placement::where('student_id', $user->id)
                    ->where('status', 'ACTIVE')
                    ->first();
                $placementId = $placement?->id;
            }
        }

        if (!$placementId) {
            return response()->json([]);
        }

        $messages = ChatMessage::with('sender')
            ->where('placementId', $placementId)
            ->orderBy('createdAt', 'asc')
            ->get()
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'placementId' => $m->placementId,
                    'senderId' => $m->senderId,
                    'sender' => [
                        'name' => $m->sender->name ?? '',
                        'role' => $m->sender->role ?? '',
                    ],
                    'role' => $m->role,
                    'message' => $m->message,
                    'createdAt' => $m->createdAt,
                ];
            });

        return response()->json($messages);
    }

    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $user = $request->user();
        $placementId = $request->placementId;

        if (!$placementId && $user->role === User::ROLE_STUDENT) {
            $placement = Placement::where('student_id', $user->id)
                ->where('status', 'ACTIVE')
                ->first();
            $placementId = $placement?->id;
        }

        if (!$placementId) {
            return response()->json(['message' => 'Placement ID required'], 400);
        }

        $chat = new ChatMessage();
        $chat->id = Str::uuid();
        $chat->placementId = $placementId;
        $chat->senderId = $user->id;
        $chat->role = $user->role;
        $chat->message = $request->message;
        $chat->createdAt = now();
        $chat->save();

        return response()->json([
            'id' => $chat->id,
            'message' => $chat->message,
            'createdAt' => $chat->createdAt,
        ], 201);
    }
}
