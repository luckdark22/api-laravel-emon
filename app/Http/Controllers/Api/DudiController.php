<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dudi;
use App\Models\Mentor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DudiController extends Controller
{
    public function index()
    {
        $dudis = Dudi::with('mentors.user')->get()->map(function ($dudi) {
            return [
                'id' => $dudi->id,
                'name' => $dudi->name,
                'logo' => $dudi->logo ? (str_starts_with($dudi->logo, 'http') ? $dudi->logo : asset('storage/uploads/logos/' . $dudi->logo)) : null,
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
                        'user' => [
                            'name' => $mentor->user->name ?? '',
                            'email' => $mentor->user->email ?? '',
                            'phone' => $mentor->user->phone ?? '',
                        ],
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

            if ($request->hasFile('logo')) {
                \Illuminate\Support\Facades\Log::info('DUDI Store: File detected', ['name' => $request->file('logo')->getClientOriginalName()]);
                $file = $request->file('logo');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->storeAs('uploads/logos', $filename, 'public');
                $dudi->logo = asset('storage/' . $path);
            } elseif ($request->has('logo') && is_string($request->logo)) {
                \Illuminate\Support\Facades\Log::info('DUDI Store: String detected', ['logo' => $request->logo]);
                $dudi->logo = $request->logo;
            } else {
                \Illuminate\Support\Facades\Log::info('DUDI Store: No valid logo input found');
            }

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
                // Check if user exists (withTrashed logic)
                $existingUser = User::withTrashed()->where('email', $request->mentorEmail)->first();

                if ($existingUser) {
                    // Handle existing user logic if needed, or skip/error. 
                    // For now, let's assume unique email enforced or we use existing.
                    // But role might be different. 
                    // Simplest for MVP: Fail if exists or generate random email if test?
                    // Let's assume typical flow: new unique email. 
                    // If conflict, DB will throw error. 
                }

                $userId = Str::uuid()->toString();
                $defaultPassword = '123456';

                $user = new User();
                $user->id = $userId;
                $user->name = $request->mentorName;
                $user->email = $request->mentorEmail;
                $user->phone = $request->mentorPhone;
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

            // Return consistent structure with index/show
            // We need to reload mentors
            $dudi->load('mentors.user');

            // Re-map like index/show or just return the object if frontend handles both?
            // adminService addDudi reads response.data directly. 
            // Better to return the same mapped structure.

            return response()->json([
                'id' => $dudi->id,
                'name' => $dudi->name,
                'logo' => $dudi->logo ? (str_starts_with($dudi->logo, 'http') ? $dudi->logo : asset('storage/uploads/logos/' . $dudi->logo)) : null,
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
                        'user' => [
                            'name' => $mentor->user->name ?? '',
                            'email' => $mentor->user->email ?? '',
                            'phone' => $mentor->user->phone ?? '',
                        ],
                        'position' => $mentor->position,
                    ];
                }),
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
                    'user' => [
                        'name' => $mentor->user->name ?? '',
                        'email' => $mentor->user->email ?? '',
                        'phone' => $mentor->user->phone ?? '',
                    ],
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

        if ($request->hasFile('logo')) {
            \Illuminate\Support\Facades\Log::info('DUDI Update: File detected');
            $file = $request->file('logo');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/logos', $filename, 'public');

            // Delete old logo if it exists (try to extract path from URL)
            if ($dudi->logo && str_contains($dudi->logo, 'storage/')) {
                try {
                    $oldPath = explode('storage/', $dudi->logo)[1];
                    Storage::disk('public')->delete($oldPath);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to delete old logo: ' . $e->getMessage());
                }
            }

            $dudi->logo = asset('storage/' . $path);
        } elseif ($request->has('logo') && $request->logo === null) {
            // Explicit null means delete
            if ($dudi->logo && str_contains($dudi->logo, 'storage/')) {
                try {
                    $oldPath = explode('storage/', $dudi->logo)[1];
                    Storage::disk('public')->delete($oldPath);
                } catch (\Exception $e) {
                }
            }
            $dudi->logo = null;
        } elseif ($request->has('logo') && is_string($request->logo)) {
            // String URL or similar
            $dudi->logo = $request->logo;
        }

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
        if ($request->has('workEndTime'))
            $dudi->work_end_time = $request->workEndTime;

        $dudi->save();

        // Handle Mentor Update
        if ($request->mentorName || $request->mentorEmail) {
            $mentor = Mentor::where('dudi_id', $dudi->id)->first();

            if ($mentor) {
                // Update existing mentor
                $user = User::find($mentor->user_id);
                if ($user) {
                    if ($request->mentorName)
                        $user->name = $request->mentorName;
                    if ($request->mentorEmail)
                        $user->email = $request->mentorEmail;
                    if ($request->mentorPhone)
                        $user->phone = $request->mentorPhone;
                    $user->save();
                }
            } else {
                // Create new mentor if not exists (similar to store)
                // Need email to proceed
                if ($request->mentorEmail) {
                    // Check specific logic for existing user, same as store
                    // ideally extract this to service, but here we duplicate for now 
                    $userId = Str::uuid()->toString();
                    $defaultPassword = '123456';

                    $user = new User();
                    $user->id = $userId;
                    $user->name = $request->mentorName ?? 'Pembimbing';
                    $user->email = $request->mentorEmail;
                    $user->phone = $request->mentorPhone;
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
            }
        }

        // Return updated structure
        $dudi->load('mentors.user');

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
                    'user' => [
                        'name' => $mentor->user->name ?? '',
                        'email' => $mentor->user->email ?? '',
                        'phone' => $mentor->user->phone ?? '',
                    ],
                    'position' => $mentor->position,
                ];
            }),
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

        $defaultPassword = '123456';
        $mentor->user->password_hash = Hash::make($defaultPassword);
        $mentor->user->is_default_password = true;
        $mentor->user->save();

        return response()->json(['message' => 'Mentor password reset successfully. Default: 123456']);
    }
}
