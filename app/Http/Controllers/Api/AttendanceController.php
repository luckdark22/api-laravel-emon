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
        $query = Attendance::with(['placement.student.user', 'placement.dudi'])
            ->orderBy('date', 'desc')
            ->orderBy('clock_in', 'desc');

        if ($request->date) {
            $query->whereDate('date', $request->date);
        }

        if ($request->dudiId && $request->dudiId !== 'ALL') {
            $query->whereHas('placement', function ($q) use ($request) {
                $q->where('dudi_id', $request->dudiId);
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
                'studentName' => $a->placement->student->user->name ?? '',
                'studentNis' => $a->placement->student->nis ?? '',
                'dudiName' => $a->placement->dudi->name ?? '',
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
        return $this->getTodayStatus($user->id);
    }

    public function today(Request $request)
    {
        $user = $request->user();
        return $this->getTodayStatus($user->id);
    }

    private function getTodayStatus($studentUserId)
    {
        $today = Carbon::today()->format('Y-m-d');

        $placement = Placement::with('dudi')
            ->where('student_id', $studentUserId)
            ->where('status', 'ACTIVE')
            ->whereHas('dudi', function ($q) use ($today) {
                $q->where(function ($q2) use ($today) {
                    $q2->whereNull('start_date')->orWhere('start_date', '<=', $today);
                })->where(function ($q2) use ($today) {
                    $q2->whereNull('end_date')->orWhere('end_date', '>=', $today);
                });
            })
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(['status' => 'NO_PLACEMENT']);
        }

        $record = Attendance::where('placement_id', $placement->id)
            ->whereDate('date', $today)
            ->first();

        if (!$record) {
            return response()->json([
                'status' => 'BELUM_ABSEN',
                'placement' => $this->transformPlacement($placement),
            ]);
        }

        if ($record->clock_out) {
            return response()->json([
                'status' => 'SUDAH_PULANG',
                'record' => $this->transformRecord($record),
                'placement' => $this->transformPlacement($placement),
            ]);
        }

        return response()->json([
            'status' => 'SUDAH_MASUK',
            'record' => $this->transformRecord($record),
            'placement' => $this->transformPlacement($placement),
        ]);
    }

    private function transformPlacement($p)
    {
        return [
            'id' => $p->id,
            'dudiId' => $p->dudi_id,
            'dudiName' => $p->dudi->name ?? '',
            'dudiAddress' => $p->dudi->address ?? '',
            'latitude' => $p->dudi->latitude,
            'longitude' => $p->dudi->longitude,
            'radiusMeters' => $p->dudi->radius_meters,
            'workStartTime' => $p->dudi->work_start_time,
            'workEndTime' => $p->dudi->work_end_time,
        ];
    }

    private function transformRecord($r)
    {
        return [
            'id' => $r->id,
            'date' => $r->date?->format('Y-m-d'),
            'clockIn' => $r->clock_in,
            'clockOut' => $r->clock_out,
            'status' => $r->status,
        ];
    }

    public function filters(Request $request)
    {
        $dudis = Dudi::select('id', 'name')->orderBy('name')->get();
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
        $attendedIds = Attendance::whereDate('date', $dateStr)
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
            ->whereDate('date', $today)
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

        $attendance = new Attendance();
        $attendance->id = Str::uuid();
        $attendance->placement_id = $placement->id;
        $attendance->date = $today;
        $attendance->clock_in = $timeString;
        $attendance->clock_in_photo = $request->photo;
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
            ->whereDate('date', $today)
            ->first();

        if (!$record) {
            return response()->json(['message' => 'Anda belum absen masuk hari ini.'], 400);
        }

        if ($record->clock_out) {
            return response()->json(['message' => 'Anda sudah melakukan absen pulang.'], 400);
        }

        $now = Carbon::now();
        $record->clock_out = $now->format('H:i');
        $record->clock_out_photo = $request->photo;
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
