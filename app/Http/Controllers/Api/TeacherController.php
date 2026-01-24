<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherController extends Controller
{
    public function index()
    {
        $teachers = Teacher::with('user')
            ->whereHas('user')
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->user_id,
                    'name' => $teacher->user->name,
                    'email' => $teacher->user->email,
                    'phone' => $teacher->user->phone,
                    'avatarUrl' => $teacher->user->avatar_url,
                    'nip' => $teacher->nip,
                    'address' => $teacher->address,
                    'specialty' => $teacher->specialty,
                ];
            });

        return response()->json($teachers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'nip' => 'required|string|unique:teachers,nip',
        ]);

        return DB::transaction(function () use ($request) {
            $userId = Str::uuid()->toString();
            $defaultPassword = 'guru123';

            $user = new User();
            $user->id = $userId;
            $user->name = $request->name;
            $user->email = $request->email;
            $user->password_hash = Hash::make($defaultPassword);
            $user->role = User::ROLE_TEACHER;
            $user->phone = $request->phone;
            $user->is_default_password = true;
            $user->save();

            $teacher = new Teacher();
            $teacher->user_id = $userId;
            $teacher->nip = $request->nip;
            $teacher->address = $request->address;
            $teacher->specialty = $request->specialty;
            $teacher->save();

            return response()->json([
                'id' => $userId,
                'name' => $user->name,
                'email' => $user->email,
                'nip' => $teacher->nip,
            ], 201);
        });
    }

    public function show($id)
    {
        $teacher = Teacher::with('user')->where('user_id', $id)->firstOrFail();

        return response()->json([
            'id' => $teacher->user_id,
            'name' => $teacher->user->name,
            'email' => $teacher->user->email,
            'phone' => $teacher->user->phone,
            'avatarUrl' => $teacher->user->avatar_url,
            'nip' => $teacher->nip,
            'address' => $teacher->address,
            'specialty' => $teacher->specialty,
        ]);
    }

    public function update(Request $request, $id)
    {
        $teacher = Teacher::with('user')->where('user_id', $id)->firstOrFail();

        if ($request->has('name'))
            $teacher->user->name = $request->name;
        if ($request->has('email'))
            $teacher->user->email = $request->email;
        if ($request->has('phone'))
            $teacher->user->phone = $request->phone;
        if ($request->has('nip'))
            $teacher->nip = $request->nip;
        if ($request->has('address'))
            $teacher->address = $request->address;
        if ($request->has('specialty'))
            $teacher->specialty = $request->specialty;

        $teacher->user->save();
        $teacher->save();

        return response()->json([
            'id' => $teacher->user_id,
            'name' => $teacher->user->name,
            'email' => $teacher->user->email,
            'nip' => $teacher->nip,
        ]);
    }

    public function destroy($id)
    {
        $teacher = Teacher::with('user')->where('user_id', $id)->firstOrFail();
        $teacher->delete();
        $teacher->user->delete();

        return response()->json(['message' => 'Teacher deleted successfully']);
    }

    public function resetPassword($id)
    {
        $teacher = Teacher::with('user')->where('user_id', $id)->firstOrFail();
        $defaultPassword = 'guru123';

        $teacher->user->password_hash = Hash::make($defaultPassword);
        $teacher->user->is_default_password = true;
        $teacher->user->save();

        return response()->json(['message' => 'Password reset successfully']);
    }
}
