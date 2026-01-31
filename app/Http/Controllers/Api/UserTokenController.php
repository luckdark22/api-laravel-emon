<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserTokenController extends Controller
{
    public function update(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $user = $request->user();
        if ($user) {
            $user->update(['fcm_token' => $request->fcm_token]);
            return response()->json(['message' => 'Token updated successfully']);
        }

        return response()->json(['message' => 'User not found'], 404);
    }
}
