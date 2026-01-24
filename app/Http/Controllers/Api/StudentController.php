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
    /**
     * Extract major from class string like "10 RPL 1" → "RPL"
     */
    private function extractMajorFromClass(?string $classString): string
    {
        if (!$classString) {
            return 'RPL'; // Default
        }

        // Split by space: "10 RPL 1" → ["10", "RPL", "1"]
        $parts = explode(' ', trim($classString));

        // Major is typically the second part
        if (count($parts) >= 2) {
            return strtoupper($parts[1]);
        }

        return 'RPL'; // Default if can't parse
    }

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
                    'class' => $student->class_name,
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
            'class' => 'required|string',
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
            $student->class_name = $request->class;
            $student->major = $request->major;
            $student->address = $request->address;
            $student->bio = $request->bio;
            $student->academic_year = $request->academicYear;
            $student->save();

            return response()->json([
                'user_id' => $userId,
                'user' => [
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'nis' => $student->nis,
                'className' => $student->class_name,
                'major' => $student->major,
                'academicYear' => $student->academic_year,
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
            'class' => $student->class_name,
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
        if ($request->has('class'))
            $student->class_name = $request->class;
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
            'user_id' => $student->user_id,
            'user' => [
                'name' => $student->user->name,
                'email' => $student->user->email,
                'phone' => $student->user->phone,
            ],
            'nis' => $student->nis,
            'className' => $student->class_name,
            'major' => $student->major,
            'academicYear' => $student->academic_year,
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
        $defaultPassword = '123456';

        $student->user->password_hash = Hash::make($defaultPassword);
        $student->user->is_default_password = true;
        $student->user->save();

        // Return password string directly for frontend compatibility
        return response()->json($defaultPassword);
    }

    public function import(Request $request)
    {
        // Frontend sends array directly, not wrapped in 'students' key
        $students = $request->all();

        // Validate array structure
        if (empty($students) || !is_array($students)) {
            return response()->json(['message' => 'Invalid data format. Expected array of students.'], 422);
        }

        $results = ['success' => 0, 'failed' => 0, 'restored' => 0, 'errors' => []];

        foreach ($students as $data) {
            // Validate required fields
            if (empty($data['name']) || empty($data['email']) || empty($data['nis'])) {
                $results['failed']++;
                $results['errors'][] = [
                    'nis' => $data['nis'] ?? 'unknown',
                    'error' => 'Missing required fields (name, email, or nis)',
                ];
                continue;
            }

            try {
                DB::transaction(function () use ($data, &$results) {
                    $defaultPassword = 'siswa123';

                    // Check if email exists (including soft deleted)
                    $existingUser = User::withTrashed()->where('email', $data['email'])->first();

                    // Check if NIS exists (including soft deleted)
                    $existingStudent = Student::withTrashed()->where('nis', $data['nis'])->first();

                    if ($existingUser && !$existingUser->trashed()) {
                        // Email exists and NOT soft deleted - skip
                        throw new \Exception('Email already exists: ' . $data['email']);
                    }

                    if ($existingStudent && !$existingStudent->trashed()) {
                        // NIS exists and NOT soft deleted - skip
                        throw new \Exception('NIS already exists: ' . $data['nis']);
                    }

                    // Case 1: Email was soft deleted - restore and update
                    if ($existingUser && $existingUser->trashed()) {
                        $existingUser->restore();
                        $existingUser->name = $data['name'];
                        $existingUser->phone = $data['phone'] ?? null;
                        $existingUser->password_hash = Hash::make($defaultPassword);
                        $existingUser->is_default_password = true;
                        $existingUser->save();

                        // Check if associated student exists
                        $student = Student::withTrashed()->where('user_id', $existingUser->id)->first();
                        if ($student) {
                            $student->restore();
                            $student->nis = $data['nis'];
                            $student->class_name = $data['class'] ?? '10 RPL 1';
                            $student->major = $data['major'] ?? $this->extractMajorFromClass($data['class'] ?? null);
                            $student->address = $data['address'] ?? null;
                            $student->academic_year = $data['academicYear'] ?? null;
                            $student->save();
                        } else {
                            // Create new student record
                            $student = new Student();
                            $student->user_id = $existingUser->id;
                            $student->nis = $data['nis'];
                            $student->class_name = $data['class'] ?? '10 RPL 1';
                            $student->major = $data['major'] ?? $this->extractMajorFromClass($data['class'] ?? null);
                            $student->address = $data['address'] ?? null;
                            $student->academic_year = $data['academicYear'] ?? null;
                            $student->save();
                        }

                        $results['restored']++;
                        $results['success']--;  // Will be incremented outside, so net zero for separate count
                        return;
                    }

                    // Case 2: NIS was soft deleted but email is new - restore student with new user
                    if ($existingStudent && $existingStudent->trashed()) {
                        // Delete old user if exists
                        if ($existingStudent->user) {
                            $existingStudent->user->forceDelete();
                        }

                        // Create new user
                        $userId = Str::uuid()->toString();
                        $user = new User();
                        $user->id = $userId;
                        $user->name = $data['name'];
                        $user->email = $data['email'];
                        $user->password_hash = Hash::make($defaultPassword);
                        $user->role = User::ROLE_STUDENT;
                        $user->phone = $data['phone'] ?? null;
                        $user->is_default_password = true;
                        $user->save();

                        // Update and restore student
                        $existingStudent->user_id = $userId;
                        $existingStudent->class_name = $data['class'] ?? '10 RPL 1';
                        $existingStudent->major = $data['major'] ?? $this->extractMajorFromClass($data['class'] ?? null);
                        $existingStudent->address = $data['address'] ?? null;
                        $existingStudent->academic_year = $data['academicYear'] ?? null;
                        $existingStudent->restore();
                        $existingStudent->save();

                        $results['restored']++;
                        $results['success']--;
                        return;
                    }

                    // Case 3: Brand new - create both
                    $userId = Str::uuid()->toString();

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
                    $student->class_name = $data['class'] ?? '10 RPL 1';
                    $student->major = $data['major'] ?? $this->extractMajorFromClass($data['class'] ?? null);
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
