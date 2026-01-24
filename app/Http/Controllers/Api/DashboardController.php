<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Placement;
use App\Models\Attendance;
use App\Models\Journal;
use App\Models\Leave;
use App\Models\Issue;
use App\Models\Visit;
use App\Models\Dudi;
use App\Models\Student;
use App\Models\Holiday;
use App\Models\AcademicYear;
use App\Models\Mentor;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function admin(Request $request)
    {
        $academicYearId = $request->academicYearId ?? $request->academicYear;
        $dudiId = $request->dudiId;

        $placementQuery = Placement::where('status', 'ACTIVE');

        if ($academicYearId && $academicYearId !== 'ALL') {
            $placementQuery->where('academic_year_id', $academicYearId);
        }
        if ($dudiId && $dudiId !== 'ALL') {
            $placementQuery->where('dudi_id', $dudiId);
        }

        $activePlacements = $placementQuery->count();
        $totalStudents = Student::whereNull('deleted_at')->count();
        $totalDudi = Dudi::whereNull('deleted_at')->count();

        // Open issues
        $openIssues = Issue::where('status', 'OPEN')->count();

        // Calculate placement percentage
        $placementPercentage = $totalStudents > 0
            ? round(($activePlacements / $totalStudents) * 100, 1)
            : 0;

        // Attendance trend (last 7 days)
        $attendanceTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dayAttendances = Attendance::whereDate('date', $date)->get();
            $hadir = $dayAttendances->whereIn('status', ['ON_TIME', 'LATE'])->count();
            $izin = $dayAttendances->whereIn('status', ['PERMIT', 'SICK'])->count();
            $alpa = $dayAttendances->where('status', 'ABSENT')->count();

            $attendanceTrend[] = [
                'day' => $date->locale('id')->isoFormat('ddd'),
                'hadir' => $hadir,
                'izin' => $izin,
                'alpa' => $alpa,
            ];
        }

        // Major distribution
        $majorDistribution = Student::whereNull('deleted_at')
            ->select('major', DB::raw('count(*) as value'))
            ->groupBy('major')
            ->get()
            ->map(function ($item, $index) {
                $colors = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];
                return [
                    'name' => $item->major ?? 'Tidak Ada Jurusan',
                    'value' => $item->value,
                    'color' => $colors[$index % count($colors)],
                ];
            });

        // Recent activities
        $recentActivities = Attendance::with(['placement.student.user', 'placement.dudi'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($a) {
                return [
                    'id' => $a->id,
                    'user' => $a->placement->student->user->name ?? '',
                    'action' => $a->status === 'ON_TIME' ? 'Hadir tepat waktu' : ($a->status === 'LATE' ? 'Hadir terlambat' : $a->status),
                    'time' => $a->clock_in ?? '',
                    'location' => $a->placement->dudi->name ?? '',
                    'type' => 'attendance',
                    'avatarUrl' => $a->placement->student->user->avatar_url ?? null,
                ];
            });

        return response()->json([
            'stats' => [
                'totalStudents' => $totalStudents,
                'eligibleStudents' => $activePlacements,
                'totalDudi' => $totalDudi,
                'placementPercentage' => $placementPercentage,
                'issueCount' => $openIssues,
            ],
            'attendanceTrend' => $attendanceTrend,
            'majorDistribution' => $majorDistribution,
            'recentActivities' => $recentActivities,
        ]);
    }

    public function adminAnalysis(Request $request)
    {
        $academicYearId = $request->academicYearId;

        // Attendance trend (last 7 days)
        $attendanceTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $count = Attendance::whereDate('date', $date)->count();
            $attendanceTrend[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'count' => $count,
            ];
        }

        // Attendance by status
        $attendanceByStatus = Attendance::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        // Issues by category
        $issuesByCategory = Issue::select('category', DB::raw('count(*) as count'))
            ->groupBy('category')
            ->get();

        // Journal status distribution
        $journalByStatus = Journal::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        return response()->json([
            'attendanceTrend' => $attendanceTrend,
            'attendanceByStatus' => $attendanceByStatus,
            'issuesByCategory' => $issuesByCategory,
            'journalByStatus' => $journalByStatus,
        ]);
    }

    public function adminDudiStats(Request $request)
    {
        $academicYearId = $request->academicYearId ?? $request->academicYear;

        $activeYear = $academicYearId && $academicYearId !== 'ALL'
            ? AcademicYear::where('name', $academicYearId)->first() ?? AcademicYear::find($academicYearId)
            : AcademicYear::where('is_active', true)->first();

        $dudis = Dudi::whereNull('deleted_at')->get();

        $stats = $dudis->map(function ($dudi) use ($activeYear) {
            $placements = Placement::where('dudi_id', $dudi->id)
                ->where('status', 'ACTIVE')
                ->when($activeYear, function ($q) use ($activeYear) {
                    $q->where('academic_year_id', $activeYear->id);
                })
                ->pluck('id');

            $studentCount = $placements->count();

            if ($studentCount === 0) {
                return null;
            }

            // Calculate attendance stats
            $attendances = Attendance::whereIn('placement_id', $placements)->get();
            $onTime = $attendances->where('status', 'ON_TIME')->count();
            $late = $attendances->where('status', 'LATE')->count();
            $sick = $attendances->where('status', 'SICK')->count();
            $permit = $attendances->where('status', 'PERMIT')->count();

            // Calculate alpha (absent days)
            $totalWorkingDays = $this->countWorkingDays($activeYear);
            $totalAttendanceDays = $onTime + $late + $sick + $permit;
            $alpha = max(0, ($totalWorkingDays * $studentCount) - $totalAttendanceDays);

            return [
                'dudiId' => $dudi->id,
                'dudiName' => $dudi->name,
                'totalStudents' => $studentCount,
                'stats' => [
                    'present' => $onTime,
                    'late' => $late,
                    'sick' => $sick,
                    'permit' => $permit,
                    'alpha' => $alpha,
                ],
            ];
        })->filter()->values();

        return response()->json($stats);
    }

    private function countWorkingDays($activeYear)
    {
        if (!$activeYear || !$activeYear->start_date) {
            return 0;
        }

        $start = Carbon::parse($activeYear->start_date);
        $end = Carbon::parse($activeYear->end_date ?? now());

        if ($end->gt(now())) {
            $end = now();
        }

        $holidays = Holiday::whereBetween('date', [$start, $end])->pluck('date')->toArray();

        $workingDays = 0;
        $current = $start->copy();
        while ($current->lte($end)) {
            if (!$current->isWeekend() && !in_array($current->format('Y-m-d'), $holidays)) {
                $workingDays++;
            }
            $current->addDay();
        }

        return $workingDays;
    }

    public function dudiDetails(Request $request, $id)
    {
        $academicYearId = $request->academicYearId;

        $activeYear = $academicYearId && $academicYearId !== 'ALL'
            ? AcademicYear::find($academicYearId)
            : AcademicYear::where('is_active', true)->first();

        $placements = Placement::with(['student.user'])
            ->where('dudi_id', $id)
            ->where('status', 'ACTIVE')
            ->when($activeYear, function ($q) use ($activeYear) {
                $q->where('academic_year_id', $activeYear->id);
            })
            ->get();

        $students = $placements->map(function ($p) use ($activeYear) {
            $attendances = Attendance::where('placement_id', $p->id)->get();

            $onTime = $attendances->where('status', 'ON_TIME')->count();
            $late = $attendances->where('status', 'LATE')->count();
            $sick = $attendances->where('status', 'SICK')->count();
            $permit = $attendances->where('status', 'PERMIT')->count();

            $totalWorkingDays = $this->countWorkingDays($activeYear);
            $totalAttendanceDays = $onTime + $late + $sick + $permit;
            $alpha = max(0, $totalWorkingDays - $totalAttendanceDays);

            return [
                'studentId' => $p->student_id,
                'studentName' => $p->student->user->name ?? '',
                'studentNis' => $p->student->nis ?? '',
                'studentClassName' => $p->student->class_name ?? '',
                'studentPhone' => $p->student->user->phone ?? '',
                'studentEmail' => $p->student->user->email ?? '',
                'onTime' => $onTime,
                'late' => $late,
                'sick' => $sick,
                'permit' => $permit,
                'alpha' => $alpha,
            ];
        });

        return response()->json($students);
    }

    public function teacher(Request $request)
    {
        $user = $request->user();

        $placements = Placement::with(['student.user', 'dudi'])
            ->where('teacher_id', $user->id)
            ->where('status', 'ACTIVE')
            ->get();

        $studentCount = $placements->count();
        $dudiIds = $placements->pluck('dudi_id')->unique();
        $dudiCount = $dudiIds->count();

        // Today's attendance for my students
        $today = Carbon::today()->format('Y-m-d');
        $placementIds = $placements->pluck('id');
        $todayAttendance = Attendance::whereIn('placement_id', $placementIds)
            ->whereDate('date', $today)
            ->count();

        // Pending journals
        $pendingJournals = Journal::whereIn('placement_id', $placementIds)
            ->where('status', 'PENDING')
            ->count();

        // Open issues
        $openIssues = Issue::whereIn('placement_id', $placementIds)
            ->where('status', 'OPEN')
            ->count();

        // My visits this month
        $myVisits = Visit::where('teacher_id', $user->id)
            ->whereMonth('visit_date', now()->month)
            ->count();

        return response()->json([
            'stats' => [
                'studentCount' => $studentCount,
                'dudiCount' => $dudiCount,
                'todayAttendance' => $todayAttendance,
                'pendingJournals' => $pendingJournals,
                'openIssues' => $openIssues,
                'myVisits' => $myVisits,
            ],
            'students' => $placements->map(function ($p) {
                return [
                    'id' => $p->student_id,
                    'name' => $p->student->user->name ?? '',
                    'nis' => $p->student->nis ?? '',
                    'className' => $p->student->class_name ?? '',
                    'dudiName' => $p->dudi->name ?? '',
                ];
            }),
        ]);
    }

    public function teacherDudiStats(Request $request)
    {
        $user = $request->user();
        $academicYearId = $request->academicYearId;

        $activeYear = $academicYearId && $academicYearId !== 'ALL'
            ? AcademicYear::find($academicYearId)
            : AcademicYear::where('is_active', true)->first();

        // Get unique DUDIs for this teacher
        $dudiIds = Placement::where('teacher_id', $user->id)
            ->where('status', 'ACTIVE')
            ->when($activeYear, function ($q) use ($activeYear) {
                $q->where('academic_year_id', $activeYear->id);
            })
            ->pluck('dudi_id')
            ->unique();

        $dudis = Dudi::whereIn('id', $dudiIds)->get();

        $stats = $dudis->map(function ($dudi) use ($user, $activeYear) {
            $placements = Placement::where('dudi_id', $dudi->id)
                ->where('teacher_id', $user->id)
                ->where('status', 'ACTIVE')
                ->when($activeYear, function ($q) use ($activeYear) {
                    $q->where('academic_year_id', $activeYear->id);
                })
                ->pluck('id');

            $studentCount = $placements->count();

            $attendances = Attendance::whereIn('placement_id', $placements)->get();
            $onTime = $attendances->where('status', 'ON_TIME')->count();
            $late = $attendances->where('status', 'LATE')->count();
            $sick = $attendances->where('status', 'SICK')->count();
            $permit = $attendances->where('status', 'PERMIT')->count();

            $totalWorkingDays = $this->countWorkingDays($activeYear);
            $totalAttendanceDays = $onTime + $late + $sick + $permit;
            $alpha = max(0, ($totalWorkingDays * $studentCount) - $totalAttendanceDays);

            return [
                'dudiId' => $dudi->id,
                'dudiName' => $dudi->name,
                'studentCount' => $studentCount,
                'onTime' => $onTime,
                'late' => $late,
                'sick' => $sick,
                'permit' => $permit,
                'alpha' => $alpha,
            ];
        });

        return response()->json($stats);
    }

    public function teacherAnalysis(Request $request)
    {
        $user = $request->user();
        $academicYearId = $request->academicYearId;

        $placementIds = Placement::where('teacher_id', $user->id)
            ->where('status', 'ACTIVE')
            ->pluck('id');

        // Attendance trend (last 7 days)
        $attendanceTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $count = Attendance::whereIn('placement_id', $placementIds)
                ->whereDate('date', $date)
                ->count();
            $attendanceTrend[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'count' => $count,
            ];
        }

        return response()->json([
            'attendanceTrend' => $attendanceTrend,
        ]);
    }

    public function teacherDudiDetails(Request $request, $dudiId)
    {
        $user = $request->user();
        $academicYearId = $request->academicYearId;

        $activeYear = $academicYearId && $academicYearId !== 'ALL'
            ? AcademicYear::find($academicYearId)
            : AcademicYear::where('is_active', true)->first();

        $placements = Placement::with(['student.user'])
            ->where('dudi_id', $dudiId)
            ->where('teacher_id', $user->id)
            ->where('status', 'ACTIVE')
            ->when($activeYear, function ($q) use ($activeYear) {
                $q->where('academic_year_id', $activeYear->id);
            })
            ->get();

        $students = $placements->map(function ($p) use ($activeYear) {
            $attendances = Attendance::where('placement_id', $p->id)->get();

            $onTime = $attendances->where('status', 'ON_TIME')->count();
            $late = $attendances->where('status', 'LATE')->count();
            $sick = $attendances->where('status', 'SICK')->count();
            $permit = $attendances->where('status', 'PERMIT')->count();

            $totalWorkingDays = $this->countWorkingDays($activeYear);
            $totalAttendanceDays = $onTime + $late + $sick + $permit;
            $alpha = max(0, $totalWorkingDays - $totalAttendanceDays);

            return [
                'studentId' => $p->student_id,
                'studentName' => $p->student->user->name ?? '',
                'studentNis' => $p->student->nis ?? '',
                'studentClassName' => $p->student->class_name ?? '',
                'studentPhone' => $p->student->user->phone ?? '',
                'studentEmail' => $p->student->user->email ?? '',
                'onTime' => $onTime,
                'late' => $late,
                'sick' => $sick,
                'permit' => $permit,
                'alpha' => $alpha,
            ];
        });

        return response()->json($students);
    }

    public function student(Request $request)
    {
        $user = $request->user();

        $placement = Placement::with(['dudi', 'teacher.user'])
            ->where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json([
                'hasPlacement' => false,
                'stats' => null,
            ]);
        }

        // Attendance stats
        $attendances = Attendance::where('placement_id', $placement->id)->get();
        $onTime = $attendances->where('status', 'ON_TIME')->count();
        $late = $attendances->where('status', 'LATE')->count();
        $totalAttended = $onTime + $late;

        // Journal stats
        $journals = Journal::where('placement_id', $placement->id)->get();
        $approvedJournals = $journals->where('status', 'APPROVED')->count();
        $pendingJournals = $journals->where('status', 'PENDING')->count();

        return response()->json([
            'hasPlacement' => true,
            'placement' => [
                'id' => $placement->id,
                'dudiName' => $placement->dudi->name ?? '',
                'dudiAddress' => $placement->dudi->address ?? '',
                'teacherName' => $placement->teacher->user->name ?? '',
            ],
            'stats' => [
                'totalAttended' => $totalAttended,
                'onTime' => $onTime,
                'late' => $late,
                'approvedJournals' => $approvedJournals,
                'pendingJournals' => $pendingJournals,
                'totalJournals' => $journals->count(),
            ],
        ]);
    }

    public function mentor(Request $request)
    {
        $user = $request->user();

        $mentor = Mentor::with('dudi')->where('user_id', $user->id)->first();

        if (!$mentor || !$mentor->dudi_id) {
            return response()->json([
                'hasDudi' => false,
                'stats' => null,
            ]);
        }

        $placements = Placement::with(['student.user'])
            ->where('dudi_id', $mentor->dudi_id)
            ->where('status', 'ACTIVE')
            ->get();

        $studentCount = $placements->count();
        $placementIds = $placements->pluck('id');

        // Today's attendance
        $today = Carbon::today()->format('Y-m-d');
        $todayAttendance = Attendance::whereIn('placement_id', $placementIds)
            ->whereDate('date', $today)
            ->count();

        // Pending journals
        $pendingJournals = Journal::whereIn('placement_id', $placementIds)
            ->where('status', 'PENDING')
            ->count();

        return response()->json([
            'hasDudi' => true,
            'dudi' => [
                'id' => $mentor->dudi->id,
                'name' => $mentor->dudi->name,
                'address' => $mentor->dudi->address,
            ],
            'stats' => [
                'studentCount' => $studentCount,
                'todayAttendance' => $todayAttendance,
                'pendingJournals' => $pendingJournals,
            ],
            'students' => $placements->map(function ($p) {
                return [
                    'id' => $p->student_id,
                    'name' => $p->student->user->name ?? '',
                    'nis' => $p->student->nis ?? '',
                    'className' => $p->student->class_name ?? '',
                ];
            }),
        ]);
    }
}
