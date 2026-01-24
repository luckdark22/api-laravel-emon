<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentController extends Controller
{
    public function index()
    {
        $students = Student::with(['user', 'activePlacement.dudi', 'activePlacement.teacher.user'])
            ->whereHas('user')
            ->get()
            ->map(function ($student) {
                return [
                    'id' => $student->user_id,
                    'name' => $student->user->name,
                    'email' => $student->user->email,
                    'phone' => $student->user->phone,
                    'avatarUrl' => $student->user->avatar_url,
                    'nis' => $student->nis,
                    'className' => $student->class_name,
                    'major' => $student->major,
                    'address' => $student->address,
                    'bio' => $student->bio,
                    'academicYear' => $student->academic_year,
                    'placement' => $student->activePlacement ? [
                        'id' => $student->activePlacement->id,
                        'dudiName' => $student->activePlacement->dudi->name ?? null,
                        'teacherName' => $student->activePlacement->teacher->user->name ?? null,
                        'status' => $student->activePlacement->status,
                    ] : null,
                ];
            });

        return response()->json($students);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'nis' => 'required|string|unique:students,nis',
            'className' => 'required|string',
            'major' => 'required|string',
        ]);

        return DB::transaction(function () use ($request) {
            $userId = Str::uuid()->toString();
            $defaultPassword = 'siswa123';

            $user = new User();
            $user->id = $userId;
            $user->name = $request->name;
            $user->email = $request->email;
            $user->password_hash = Hash::make($defaultPassword);
            $user->role = User::ROLE_STUDENT;
            $user->phone = $request->phone;
            $user->is_default_password = true;
            $user->save();

            $student = new Student();
            $student->user_id = $userId;
            $student->nis = $request->nis;
            $student->class_name = $request->className;
            $student->major = $request->major;
            $student->address = $request->address;
            $student->bio = $request->bio;
            $student->academic_year = $request->academicYear;
            $student->save();

            return response()->json([
                'id' => $userId,
                'name' => $user->name,
                'email' => $user->email,
                'nis' => $student->nis,
                'className' => $student->class_name,
                'major' => $student->major,
            ], 201);
        });
    }

    public function show($id)
    {
        $student = Student::with(['user', 'activePlacement.dudi', 'activePlacement.teacher.user'])
            ->where('user_id', $id)
            ->firstOrFail();

        return response()->json([
            'id' => $student->user_id,
            'name' => $student->user->name,
            'email' => $student->user->email,
            'phone' => $student->user->phone,
            'avatarUrl' => $student->user->avatar_url,
            'nis' => $student->nis,
            'className' => $student->class_name,
            'major' => $student->major,
            'address' => $student->address,
            'bio' => $student->bio,
            'academicYear' => $student->academic_year,
        ]);
    }

    public function update(Request $request, $id)
    {
        $student = Student::with('user')->where('user_id', $id)->firstOrFail();

        if ($request->has('name'))
            $student->user->name = $request->name;
        if ($request->has('email'))
            $student->user->email = $request->email;
        if ($request->has('phone'))
            $student->user->phone = $request->phone;
        if ($request->has('nis'))
            $student->nis = $request->nis;
        if ($request->has('className'))
            $student->class_name = $request->className;
        if ($request->has('major'))
            $student->major = $request->major;
        if ($request->has('address'))
            $student->address = $request->address;
        if ($request->has('bio'))
            $student->bio = $request->bio;
        if ($request->has('academicYear'))
            $student->academic_year = $request->academicYear;

        $student->user->save();
        $student->save();

        return response()->json([
            'id' => $student->user_id,
            'name' => $student->user->name,
            'email' => $student->user->email,
            'nis' => $student->nis,
            'className' => $student->class_name,
            'major' => $student->major,
        ]);
    }

    public function destroy($id)
    {
        $student = Student::with('user')->where('user_id', $id)->firstOrFail();
        $student->delete();
        $student->user->delete();

        return response()->json(['message' => 'Student deleted successfully']);
    }

    public function resetPassword($id)
    {
        $student = Student::with('user')->where('user_id', $id)->firstOrFail();
        $defaultPassword = 'siswa123';

        $student->user->password_hash = Hash::make($defaultPassword);
        $student->user->is_default_password = true;
        $student->user->save();

        return response()->json(['message' => 'Password reset successfully']);
    }

    public function import(Request $request)
    {
        $request->validate([
            'students' => 'required|array',
            'students.*.name' => 'required|string',
            'students.*.email' => 'required|email',
            'students.*.nis' => 'required|string',
            'students.*.className' => 'required|string',
            'students.*.major' => 'required|string',
        ]);

        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($request->students as $data) {
            try {
                DB::transaction(function () use ($data) {
                    $userId = Str::uuid()->toString();
                    $defaultPassword = 'siswa123';

                    $user = new User();
                    $user->id = $userId;
                    $user->name = $data['name'];
                    $user->email = $data['email'];
                    $user->password_hash = Hash::make($defaultPassword);
                    $user->role = User::ROLE_STUDENT;
                    $user->phone = $data['phone'] ?? null;
                    $user->is_default_password = true;
                    $user->save();

                    $student = new Student();
                    $student->user_id = $userId;
                    $student->nis = $data['nis'];
                    $student->class_name = $data['className'];
                    $student->major = $data['major'];
                    $student->address = $data['address'] ?? null;
                    $student->academic_year = $data['academicYear'] ?? null;
                    $student->save();
                });
                $results['success']++;
            } catch (\Exception $e) {
                $results['failed']++;
                $results['errors'][] = [
                    'nis' => $data['nis'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json($results);
    }
}
