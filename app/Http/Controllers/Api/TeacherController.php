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
        // Frontend expects structure with nested 'user' object: item.user.name
        $teachers = Teacher::with('user')
            ->whereHas('user')
            ->get();

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
            $defaultPassword = '123456'; // User requested default

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

            // Return flattened structure for store response if needed, 
            // OR consistent structure. adminApi.addTeacher expects:
            // return { id: item.user_id, name: item.user.name... } -> based on current implementation
            // But api.post returns `item` which is response.data
            // adminService.ts Line 203: const item = response.data;
            // Let's return the simplified object because adminService manually reconstructs the return

            // Wait, adminService.ts (Line 203) uses response.data.user.name
            // Actually: 
            // const item = response.data;
            // return { id: item.user_id, name: item.user.name ... }
            // So we must return nested structure here too to key match, 
            // OR we return what we constructed below and update the frontend service?
            // The previous code returned:
            /*
             return response()->json([
                'id' => $userId,
                'name' => $user->name, 
                ...
             ])
            */
            // If we look at adminService.ts addTeacher:
            /*
             const item = response.data;
             return { id: item.user_id, name: item.user.name ... }
            */
            // This suggests it EXPECTS the same nested structure as GET /teachers or similar.
            // But the PREVIOUS code returned flat. Meaning ADD operation would CRASH the frontend service return mapping if not fixed.

            // Let's fix this to be consistent by reloading the model

            return response()->json(
                Teacher::with('user')->find($userId)
                ,
                201
            );
        });
    }

    public function import(Request $request)
    {
        $request->validate([
            '*.name' => 'required|string',
            '*.email' => 'required|email',
            '*.nip' => 'required|string',
        ]);

        $data = $request->all(); // Array of teachers

        DB::transaction(function () use ($data) {
            foreach ($data as $row) {
                // Check if exists (including soft deleted)
                if (Teacher::withTrashed()->where('nip', $row['nip'])->exists()) {
                    continue; // Skip existing
                }
                if (User::withTrashed()->where('email', $row['email'])->exists()) {
                    continue; // Skip existing
                }

                $userId = Str::uuid()->toString();
                $defaultPassword = '123456';

                $user = new User();
                $user->id = $userId;
                $user->name = $row['name'];
                $user->email = $row['email'];
                $user->password_hash = Hash::make($defaultPassword);
                $user->role = User::ROLE_TEACHER;
                $user->phone = $row['phone'] ?? null;
                $user->is_default_password = true;
                $user->save();

                $teacher = new Teacher();
                $teacher->user_id = $userId;
                $teacher->nip = $row['nip'];
                $teacher->address = $row['address'] ?? null;
                $teacher->specialty = $row['specialty'] ?? null;
                $teacher->save();
            }
        });

        return response()->json(['message' => 'Import successful']);
    }

    public function show($id)
    {
        $teacher = Teacher::with('user')->where('user_id', $id)->firstOrFail();
        // Return raw nested structure to allow frontend to map it
        return response()->json($teacher);
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

        return response()->json($teacher);
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
        $defaultPassword = '123456'; // User requested default

        $teacher->user->password_hash = Hash::make($defaultPassword);
        $teacher->user->is_default_password = true;
        $teacher->user->save();

        return response()->json(['message' => 'Password reset successfully. Default: 123456']);
    }
}
