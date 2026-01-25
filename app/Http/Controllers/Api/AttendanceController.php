<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Placement;
use App\Models\Mentor;
use App\Models\Holiday;
use App\Models\Dudi;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Attendance::with(['placement.student.user', 'placement.dudi'])
            ->orderBy('date', 'desc')
            ->orderBy('clock_in', 'desc');

        // Teacher Scope
        if ($user->role === User::ROLE_TEACHER) {
            $query->whereHas('placement', function ($q) use ($user) {
                $q->where('teacher_id', $user->id);
            });
        }
        // Mentor Scope
        if ($user->role === User::ROLE_MENTOR) {
            $mentor = Mentor::where('user_id', $user->id)->first();
            if ($mentor && $mentor->dudi_id) {
                $query->whereHas('placement', function ($q) use ($mentor) {
                    $q->where('dudi_id', $mentor->dudi_id);
                });
            } else {
                // No DUDI assigned, return empty
                return response()->json([]);
            }
        }

        // Student Scope
        if ($user->role === User::ROLE_STUDENT) {
            $query->whereHas('placement', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        }

        if ($request->date) {
            $query->whereDate('date', $request->date);
        }

        if ($request->dudiId && $request->dudiId !== 'ALL') {
            // For teachers, this just filters deeper. For admin, it's the main filter.
            $query->whereHas('placement', function ($q) use ($request) {
                $q->where('dudi_id', $request->dudiId);
            });
        }

        // Search by student name
        if ($request->search) {
            $query->whereHas('placement.student.user', function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->search . '%');
            });
        }

        if ($request->page) {
            $page = (int) $request->page;
            $limit = (int) ($request->limit ?? 10);
            $total = $query->count();
            $data = $query->skip(($page - 1) * $limit)->take($limit)->get();

            return response()->json([
                'data' => $this->transformAttendances($data),
                'meta' => [
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'last_page' => ceil($total / $limit),
                ],
            ]);
        }

        return response()->json($this->transformAttendances($query->get()));
    }

    private function transformAttendances($attendances)
    {
        return $attendances->map(function ($a) {
            return [
                'id' => $a->id,
                'placementId' => $a->placement_id,
                // Keep flat fields for backward compatibility or direct usage
                'studentName' => $a->placement->student->user->name ?? '',
                'studentNis' => $a->placement->student->nis ?? '',
                'dudiName' => $a->placement->dudi->name ?? '',

                // Nested structure for Frontend compatibility (PresensiPage.tsx)
                'placement' => [
                    'student' => [
                        'class' => $a->placement->student->class_name ?? '',
                        'user' => [
                            'name' => $a->placement->student->user->name ?? '',
                            'phone' => $a->placement->student->user->phone ?? '',
                            'avatar_url' => $a->placement->student->user->avatar_url ?? null,
                        ]
                    ],
                    'dudi' => [
                        'name' => $a->placement->dudi->name ?? '',
                        'latitude' => $a->placement->dudi->latitude ?? 0,
                        'longitude' => $a->placement->dudi->longitude ?? 0,
                        'radius_meters' => $a->placement->dudi->radius_meters ?? 0,
                    ]
                ],

                'date' => $a->date?->format('Y-m-d'),
                'clockIn' => $a->clock_in,
                'clockOut' => $a->clock_out,
                'clockInPhoto' => $a->clock_in_photo,
                'clockOutPhoto' => $a->clock_out_photo,
                'latitude' => $a->latitude,
                'longitude' => $a->longitude,
                'locationAddress' => $a->location_address,
                'status' => $a->status,
                'createdAt' => $a->created_at,
            ];
        });
    }

    public function status(Request $request)
    {
        $user = $request->user();
        $today = Carbon::today()->format('Y-m-d');

        $placement = Placement::with(['dudi', 'academicYear'])
            ->where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json([
                'status' => 'NO_PLACEMENT',
                'record' => null,
                'placement' => null,
            ]);
        }

        $attendance = Attendance::where('placement_id', $placement->id)
            ->where('date', $today)
            ->first();

        $status = 'BELUM_ABSEN';
        if ($attendance) {
            $status = $attendance->clock_out ? 'SUDAH_PULANG' : 'SUDAH_MASUK';
        }

        return response()->json([
            'status' => $status,
            'record' => $attendance ? [
                'id' => $attendance->id,
                'clockIn' => $attendance->clock_in,
                'clockOut' => $attendance->clock_out,
                'status' => $attendance->status,
            ] : null,
            'placement' => [
                'id' => $placement->id,
                'dudi' => [
                    'name' => $placement->dudi->name ?? '',
                    'latitude' => $placement->dudi->latitude ?? 0,
                    'longitude' => $placement->dudi->longitude ?? 0,
                    'radiusMeters' => $placement->dudi->radius_meters ?? 100,
                ],
                'workStartTime' => $placement->dudi->work_start_time ?? '08:00',
                'workEndTime' => $placement->dudi->work_end_time ?? '16:00',
            ],
        ]);
    }

    public function filters(Request $request)
    {
        $user = $request->user();

        if ($user->role === User::ROLE_TEACHER) {
            // Only DUDIs where teacher has active students
            // Use query builder on Placement to find unique DUDIs
            $dudiIds = Placement::where('teacher_id', $user->id)
                ->where('status', 'ACTIVE')
                ->pluck('dudi_id')
                ->unique();

            $dudis = Dudi::whereIn('id', $dudiIds)
                ->select('id', 'name')
                ->orderBy('name')
                ->get();
        } elseif ($user->role === User::ROLE_MENTOR) {
            $mentor = Mentor::where('user_id', $user->id)->first();
            if ($mentor && $mentor->dudi_id) {
                $dudis = Dudi::where('id', $mentor->dudi_id)->select('id', 'name')->get();
            } else {
                $dudis = [];
            }
        } else {
            // Admin sees all
            $dudis = Dudi::select('id', 'name')->orderBy('name')->get();
        }

        return response()->json(['dudis' => $dudis]);
    }

    public function missing(Request $request)
    {
        $user = $request->user();
        $date = $request->date ? Carbon::parse($request->date) : Carbon::today();
        $dateStr = $date->format('Y-m-d');

        // Check weekend
        if ($date->isWeekend()) {
            return response()->json([]);
        }

        // Check holiday
        if (Holiday::isHoliday($date)) {
            return response()->json([]);
        }

        $query = Placement::with(['student.user', 'dudi'])
            ->where('status', 'ACTIVE')
            ->whereHas('dudi', function ($q) use ($dateStr) {
                $q->where(function ($q2) use ($dateStr) {
                    $q2->whereNull('start_date')->orWhere('start_date', '<=', $dateStr);
                })->where(function ($q2) use ($dateStr) {
                    $q2->whereNull('end_date')->orWhere('end_date', '>=', $dateStr);
                });
            });

        // Role-based filtering
        if ($user->role === User::ROLE_TEACHER) {
            $query->where('teacher_id', $user->id);
        } elseif ($user->role === User::ROLE_MENTOR) {
            $mentor = Mentor::where('user_id', $user->id)->first();
            if ($mentor && $mentor->dudi_id) {
                $query->where('dudi_id', $mentor->dudi_id);
            } else {
                return response()->json([]);
            }
        }

        if ($request->dudiId && $request->dudiId !== 'ALL') {
            $query->where('dudi_id', $request->dudiId);
        }

        $placements = $query->get();

        // Deduplicate - keep latest per student
        $latestMap = [];
        foreach ($placements as $p) {
            if (
                !isset($latestMap[$p->student_id]) ||
                $p->created_at > $latestMap[$p->student_id]->created_at
            ) {
                $latestMap[$p->student_id] = $p;
            }
        }
        $finals = array_values($latestMap);

        if (empty($finals)) {
            return response()->json([]);
        }

        // Get who already attended
        $attendedIds = Attendance::where('date', $dateStr)
            ->whereIn('placement_id', array_map(fn($p) => $p->id, $finals))
            ->pluck('placement_id')
            ->toArray();

        $missing = collect($finals)->filter(function ($p) use ($attendedIds) {
            return !in_array($p->id, $attendedIds);
        })->map(function ($p) {
            return [
                'id' => $p->student->user->id ?? '',
                'name' => $p->student->user->name ?? '',
                'className' => $p->student->class_name ?? '',
                'dudi' => $p->dudi->name ?? '-',
                'phone' => $p->student->user->phone ?? '',
                'avatarUrl' => $p->student->user->avatar_url ?? '',
            ];
        })->values();

        return response()->json($missing);
    }

    public function clockIn(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photo' => 'nullable|image|max:5120',
        ]);

        $user = $request->user();
        $today = Carbon::today()->format('Y-m-d');

        $placement = Placement::with('dudi')
            ->where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(['message' => 'Siswa tidak memiliki penempatan aktif.'], 400);
        }

        $existing = Attendance::where('placement_id', $placement->id)
            ->where('date', $today)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Anda sudah melakukan absen masuk hari ini.'], 400);
        }

        $now = Carbon::now();
        $timeString = $now->format('H:i');
        $workStartTime = $placement->dudi->work_start_time ?? '08:00';

        $status = $timeString > substr($workStartTime, 0, 5)
            ? Attendance::STATUS_LATE
            : Attendance::STATUS_ON_TIME;

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = time() . '_in_' . $user->id . '.' . $file->getClientOriginalExtension();
            $photoPath = asset('storage/' . $file->storeAs('attendances', $filename, 'public'));
        }

        $attendance = new Attendance();
        $attendance->id = Str::uuid();
        $attendance->placement_id = $placement->id;
        $attendance->date = $today;
        $attendance->clock_in = $timeString;
        $attendance->clock_in_photo = $photoPath;
        $attendance->latitude = $request->latitude;
        $attendance->longitude = $request->longitude;
        $attendance->location_address = $request->address ?? '';
        $attendance->status = $status;
        $attendance->created_at = now();
        $attendance->save();

        return response()->json([
            'id' => $attendance->id,
            'clockIn' => $attendance->clock_in,
            'status' => $attendance->status,
        ], 201);
    }

    public function clockOut(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'photo' => 'nullable|image|max:5120',
        ]);

        $user = $request->user();
        $today = Carbon::today()->format('Y-m-d');

        $placement = Placement::where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(['message' => 'Siswa tidak memiliki penempatan aktif.'], 400);
        }

        $record = Attendance::where('placement_id', $placement->id)
            ->where('date', $today)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Anda belum absen masuk hari ini.'], 400);
        }

        if ($record->clock_out) {
            return response()->json(['message' => 'Anda sudah melakukan absen pulang.'], 400);
        }

        $photoPath = null;
        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $filename = time() . '_out_' . $user->id . '.' . $file->getClientOriginalExtension();
            $photoPath = asset('storage/' . $file->storeAs('attendances', $filename, 'public'));
        }

        $now = Carbon::now();
        $record->clock_out = $now->format('H:i');
        $record->clock_out_photo = $photoPath;
        $record->latitude = $request->latitude;
        $record->longitude = $request->longitude;
        $record->location_address = $request->address ?? $record->location_address;
        $record->save();

        return response()->json([
            'id' => $record->id,
            'clockOut' => $record->clock_out,
            'status' => $record->status,
        ]);
    }
}
