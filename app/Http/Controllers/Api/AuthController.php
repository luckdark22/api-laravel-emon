<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Firebase\JWT\JWT;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Use password_verify directly for compatibility with Node.js bcrypt hashes
        if (!password_verify($request->password, $user->password_hash)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $secret = config('app.jwt_secret', env('JWT_SECRET', 'SECRET_KEY_DEV'));
        $payload = [
            'email' => $user->email,
            'sub' => $user->id,
            'role' => $user->role,
            'iat' => time(),
            'exp' => time() + (60 * 60 * 24), // 1 day
        ];

        $token = JWT::encode($payload, $secret, 'HS256');

        return response()->json([
            'access_token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'isDefaultPassword' => $user->is_default_password,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        // For JWT, we don't need to do anything server-side
        // The client should just remove the token
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'newPassword' => 'required|min:6',
        ]);

        $user = $request->user();
        $user->password_hash = Hash::make($request->newPassword);
        $user->is_default_password = false;
        $user->save();

        return response()->json(['message' => 'Password changed successfully']);
    }
}
