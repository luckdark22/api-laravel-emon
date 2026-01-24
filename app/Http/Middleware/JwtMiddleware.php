<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        try {
            $secret = config('app.jwt_secret', env('JWT_SECRET', 'SECRET_KEY_DEV_e_monitoring_pkl_app_2026'));
            $decoded = JWT::decode($token, new Key($secret, 'HS256'));

            $user = User::find($decoded->sub);
            if (!$user) {
                return response()->json(['message' => 'User not found'], 401);
            }

            // Set the user resolver so $request->user() works
            $request->setUserResolver(function () use ($user) {
                return $user;
            });

            // Also store as attribute for direct access
            $request->attributes->set('auth_user', $user);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Invalid token: ' . $e->getMessage()], 401);
        }

        return $next($request);
    }
}
