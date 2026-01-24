<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\Mentor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($this->getProfileResponse($request->user()));
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if ($request->has('name'))
            $user->name = $request->name;
        if ($request->has('email'))
            $user->email = $request->email;
        if ($request->has('phone'))
            $user->phone = $request->phone;
        if ($request->has('avatarUrl'))
            $user->avatar_url = $request->avatarUrl;

        $user->save();

        // Update role-specific data
        if ($user->role === User::ROLE_STUDENT) {
            $student = Student::where('user_id', $user->id)->first();
            if ($student) {
                if ($request->has('address'))
                    $student->address = $request->address;
                if ($request->has('bio'))
                    $student->bio = $request->bio;
                $student->save();
            }
        } elseif ($user->role === User::ROLE_TEACHER) {
            $teacher = Teacher::where('user_id', $user->id)->first();
            if ($teacher) {
                if ($request->has('address'))
                    $teacher->address = $request->address;
                if ($request->has('specialty'))
                    $teacher->specialty = $request->specialty;
                $teacher->save();
            }
        } elseif ($user->role === User::ROLE_MENTOR) {
            $mentor = Mentor::where('user_id', $user->id)->first();
            if ($mentor) {
                if ($request->has('position'))
                    $mentor->position = $request->position;
                if ($request->has('address'))
                    $mentor->address = $request->address;
                $mentor->save();
            }
        }

        return response()->json($this->getProfileResponse($user));
    }

    private function getProfileResponse($user)
    {
        $profile = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'avatarUrl' => $user->avatar_url,
            'role' => $user->role,
            'isDefaultPassword' => $user->is_default_password,
        ];

        if ($user->role === User::ROLE_STUDENT) {
            $student = Student::where('user_id', $user->id)->first();
            if ($student) {
                $profile['nis'] = $student->nis;
                $profile['className'] = $student->class_name;
                $profile['major'] = $student->major;
                $profile['address'] = $student->address;
                $profile['bio'] = $student->bio;
            }
        } elseif ($user->role === User::ROLE_TEACHER) {
            $teacher = Teacher::where('user_id', $user->id)->first();
            if ($teacher) {
                $profile['nip'] = $teacher->nip;
                $profile['address'] = $teacher->address;
                $profile['specialty'] = $teacher->specialty;
            }
        } elseif ($user->role === User::ROLE_MENTOR) {
            $mentor = Mentor::with('dudi')->where('user_id', $user->id)->first();
            if ($mentor) {
                $profile['position'] = $mentor->position;
                $profile['address'] = $mentor->address;
                $profile['company'] = $mentor->dudi->name ?? '';
                $profile['companyAddress'] = $mentor->dudi->address ?? '';
                $profile['dudiName'] = $mentor->dudi->name ?? ''; // Keep for backward compat
            }
        }

        return $profile;
    }
}
