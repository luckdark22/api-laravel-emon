<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dudi;
use App\Models\Mentor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DudiController extends Controller
{
    public function index()
    {
        $dudis = Dudi::with('mentors.user')->get()->map(function ($dudi) {
            return [
                'id' => $dudi->id,
                'name' => $dudi->name,
                'logo' => $dudi->logo,
                'address' => $dudi->address,
                'contact' => $dudi->contact,
                'latitude' => $dudi->latitude,
                'longitude' => $dudi->longitude,
                'radiusMeters' => $dudi->radius_meters,
                'startDate' => $dudi->start_date?->format('Y-m-d'),
                'endDate' => $dudi->end_date?->format('Y-m-d'),
                'workStartTime' => $dudi->work_start_time,
                'workEndTime' => $dudi->work_end_time,
                'mentors' => $dudi->mentors->map(function ($mentor) {
                    return [
                        'id' => $mentor->user_id,
                        'name' => $mentor->user->name ?? '',
                        'email' => $mentor->user->email ?? '',
                        'position' => $mentor->position,
                    ];
                }),
            ];
        });

        return response()->json($dudis);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'address' => 'required|string',
        ]);

        return DB::transaction(function () use ($request) {
            $dudi = new Dudi();
            $dudi->id = Str::uuid();
            $dudi->name = $request->name;
            $dudi->logo = $request->logo;
            $dudi->address = $request->address;
            $dudi->contact = $request->contact;
            $dudi->latitude = $request->latitude;
            $dudi->longitude = $request->longitude;
            $dudi->radius_meters = $request->radiusMeters ?? 100;
            $dudi->start_date = $request->startDate;
            $dudi->end_date = $request->endDate;
            $dudi->work_start_time = $request->workStartTime ?? '08:00:00';
            $dudi->work_end_time = $request->workEndTime ?? '17:00:00';
            $dudi->save();

            // Create mentor if provided
            if ($request->mentorName && $request->mentorEmail) {
                $userId = Str::uuid()->toString();
                $defaultPassword = 'mentor123';

                $user = new User();
                $user->id = $userId;
                $user->name = $request->mentorName;
                $user->email = $request->mentorEmail;
                $user->password_hash = Hash::make($defaultPassword);
                $user->role = User::ROLE_MENTOR;
                $user->is_default_password = true;
                $user->save();

                $mentor = new Mentor();
                $mentor->user_id = $userId;
                $mentor->dudi_id = $dudi->id;
                $mentor->position = $request->mentorPosition ?? 'Pembimbing';
                $mentor->save();
            }

            return response()->json([
                'id' => $dudi->id,
                'name' => $dudi->name,
                'address' => $dudi->address,
            ], 201);
        });
    }

    public function show($id)
    {
        $dudi = Dudi::with('mentors.user')->findOrFail($id);

        return response()->json([
            'id' => $dudi->id,
            'name' => $dudi->name,
            'logo' => $dudi->logo,
            'address' => $dudi->address,
            'contact' => $dudi->contact,
            'latitude' => $dudi->latitude,
            'longitude' => $dudi->longitude,
            'radiusMeters' => $dudi->radius_meters,
            'startDate' => $dudi->start_date?->format('Y-m-d'),
            'endDate' => $dudi->end_date?->format('Y-m-d'),
            'workStartTime' => $dudi->work_start_time,
            'workEndTime' => $dudi->work_end_time,
            'mentors' => $dudi->mentors->map(function ($mentor) {
                return [
                    'id' => $mentor->user_id,
                    'name' => $mentor->user->name ?? '',
                    'email' => $mentor->user->email ?? '',
                    'position' => $mentor->position,
                ];
            }),
        ]);
    }

    public function update(Request $request, $id)
    {
        $dudi = Dudi::findOrFail($id);

        if ($request->has('name'))
            $dudi->name = $request->name;
        if ($request->has('logo'))
            $dudi->logo = $request->logo;
        if ($request->has('address'))
            $dudi->address = $request->address;
        if ($request->has('contact'))
            $dudi->contact = $request->contact;
        if ($request->has('latitude'))
            $dudi->latitude = $request->latitude;
        if ($request->has('longitude'))
            $dudi->longitude = $request->longitude;
        if ($request->has('radiusMeters'))
            $dudi->radius_meters = $request->radiusMeters;
        if ($request->has('startDate'))
            $dudi->start_date = $request->startDate;
        if ($request->has('endDate'))
            $dudi->end_date = $request->endDate;
        if ($request->has('workStartTime'))
            $dudi->work_start_time = $request->workStartTime;
        if ($request->has('workEndTime'))
            $dudi->work_end_time = $request->workEndTime;

        $dudi->save();

        return response()->json([
            'id' => $dudi->id,
            'name' => $dudi->name,
            'address' => $dudi->address,
        ]);
    }

    public function destroy($id)
    {
        $dudi = Dudi::findOrFail($id);
        $dudi->delete();

        return response()->json(['message' => 'Dudi deleted successfully']);
    }

    public function resetPassword($id)
    {
        $mentor = Mentor::with('user')->where('dudi_id', $id)->first();

        if (!$mentor || !$mentor->user) {
            return response()->json(['message' => 'No mentor found for this DUDI'], 404);
        }

        $defaultPassword = 'mentor123';
        $mentor->user->password_hash = Hash::make($defaultPassword);
        $mentor->user->is_default_password = true;
        $mentor->user->save();

        return response()->json(['message' => 'Mentor password reset successfully']);
    }
}
