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
use App\Models\Setting;

class DashboardController extends Controller
{
    public function admin(Request $request)
    {
        $academicYearParam = $request->academicYearId ?? $request->academicYear;
        $dudiId = $request->dudiId;

        // Resolve academic year - could be ID or name
        $academicYear = null;
        $academicYearId = null;
        if ($academicYearParam && $academicYearParam !== 'ALL') {
            // Try to find by ID first, then by name
            $academicYear = AcademicYear::find($academicYearParam)
                ?? AcademicYear::where('name', $academicYearParam)->first();
        } else {
            // Default to active academic year
            $academicYear = AcademicYear::where('is_active', true)->first();
        }
        $academicYearId = $academicYear?->id;

        $placementQuery = Placement::where('status', 'ACTIVE');

        if ($dudiId && $dudiId !== 'ALL') {
            $placementQuery->where('dudi_id', $dudiId);
        }

        // Filter by the student's registered year name to match $totalStudents logic
        if ($academicYear) {
            $placementQuery->whereHas('student', function ($q) use ($academicYear) {
                $q->where('academic_year', $academicYear->name);
            });
        }

        // Filter UNIQUE students who have an active placement AND are registered for this year
        // AND ensure student is not soft-deleted
        $activePlacements = $placementQuery->whereHas('student', function ($q) {
            $q->whereNull('deleted_at');
        })->distinct('student_id')->count('student_id');

        // Filter total students by academic year if specified
        $studentQuery = Student::whereNull('deleted_at');
        if ($academicYear) {
            $studentQuery->where('academic_year', $academicYear->name);
        }
        $totalStudents = $studentQuery->count();

        $totalDudi = Dudi::whereNull('deleted_at')->count();

        // Open issues - exclude if student or dudi is deleted
        $openIssues = Issue::where('status', 'OPEN')
            ->whereHas('placement.student', function ($q) {
                $q->whereNull('deleted_at');
            })
            ->whereHas('placement.dudi', function ($q) {
                $q->whereNull('deleted_at');
            })
            ->count();

        // Calculate placement percentage
        $placementPercentage = $totalStudents > 0
            ? round(($activePlacements / $totalStudents) * 100, 1)
            : 0;

        // Attendance trend (last 7 days)
        $attendanceTrend = [];

        // Determine global PKL dates from Settings
        // Try camelCase first (as per screenshot), fallback to snake_case just in case
        $globalStartDateStr = Setting::getValue('pklStartDate') ?? Setting::getValue('pkl_start_date');
        $globalEndDateStr = Setting::getValue('pklEndDate') ?? Setting::getValue('pkl_end_date');

        $globalStartDate = $globalStartDateStr ? Carbon::parse($globalStartDateStr) : null;
        $globalEndDate = $globalEndDateStr ? Carbon::parse($globalEndDateStr) : null;

        // If specific DUDI selected, override with DUDI dates if available
        $effStartDate = $globalStartDate;
        $effEndDate = $globalEndDate;

        if ($dudiId && $dudiId !== 'ALL') {
            $dudiObj = Dudi::find($dudiId);
            if ($dudiObj) {
                if ($dudiObj->start_date)
                    $effStartDate = $dudiObj->start_date;
                if ($dudiObj->end_date)
                    $effEndDate = $dudiObj->end_date;
            }
        }

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);

            // Filter attendance query - exclude soft-deleted students
            $dayAttendancesQuery = Attendance::whereDate('date', $date)
                ->whereHas('placement', function ($q) use ($academicYearId, $dudiId) {
                    if ($academicYearId) {
                        $q->where('academic_year_id', $academicYearId);
                    }
                    if ($dudiId && $dudiId !== 'ALL') {
                        $q->where('dudi_id', $dudiId);
                    }
                })
                ->whereHas('placement.student', function ($q) {
                    $q->whereNull('deleted_at');
                });

            $dayAttendances = $dayAttendancesQuery->get();

            $hadir = $dayAttendances->whereIn('status', ['ON_TIME', 'LATE'])->count();
            $izin = $dayAttendances->where('status', 'PERMIT')->count();
            $sakit = $dayAttendances->where('status', 'SICK')->count();

            // Calculate Alpha Dynamically (Not stored in DB)
            $alpa = 0;
            $isWeekend = $date->isWeekend();
            $isHoliday = Holiday::whereDate('date', $date)->exists();

            if (!$isWeekend && !$isHoliday) {
                // Check if date is within valid PKL period
                $isValidPeriod = true;
                if ($effStartDate && $date->lt($effStartDate))
                    $isValidPeriod = false;
                if ($effEndDate && $date->gt($effEndDate))
                    $isValidPeriod = false;

                if ($isValidPeriod) {
                    // Get expected active students count - exclude soft-deleted
                    $eligiblePlacementsQuery = Placement::where('status', 'ACTIVE')
                        ->whereHas('student', function ($q) {
                            $q->whereNull('deleted_at');
                        });

                    if ($academicYearId) {
                        $eligiblePlacementsQuery->where('academic_year_id', $academicYearId);
                    }
                    if ($dudiId && $dudiId !== 'ALL') {
                        $eligiblePlacementsQuery->where('dudi_id', $dudiId);
                    }

                    $expectedAttendance = $eligiblePlacementsQuery->distinct('student_id')->count('student_id');
                    $actualAttendance = $hadir + $izin + $sakit;

                    // Any active student without attendance record is Alpha
                    $alpa = max(0, $expectedAttendance - $actualAttendance);
                } // End isValidPeriod
            }

            $attendanceTrend[] = [
                'day' => $date->locale('id')->isoFormat('ddd'),
                'hadir' => $hadir,
                'izin' => $izin,
                'sakit' => $sakit,
                'alfa' => $alpa, // Renaming alpa to alfa for consistency if preferred, but existing code used alpa generally. Plan said ensure alfa. Let's use alfa for output key.
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

        // Recent activities - exclude soft-deleted
        $recentActivities = Attendance::with(['placement.student.user', 'placement.dudi'])
            ->whereHas('placement.student', function ($q) {
                $q->whereNull('deleted_at');
            })
            ->whereHas('placement.dudi', function ($q) {
                $q->whereNull('deleted_at');
            })
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
                'mappedStudents' => $activePlacements,  // Renamed from eligibleStudents to match frontend
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
        $academicYearId = $request->academicYearId ?? $request->academicYear;

        // Attendance trend (last 7 days) - exclude soft-deleted
        $attendanceTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dayAttendances = Attendance::whereDate('date', $date)
                ->whereHas('placement.student', function ($q) {
                    $q->whereNull('deleted_at');
                })
                ->get();

            $attendanceTrend[] = [
                'date' => $date->format('Y-m-d'),
                'activeCount' => $dayAttendances->count(),
                'presentCount' => $dayAttendances->where('status', 'ON_TIME')->count(),
                'lateCount' => $dayAttendances->where('status', 'LATE')->count(),
                'absentCount' => $dayAttendances->whereIn('status', ['ABSENT', 'PERMIT', 'SICK'])->count(),
            ];
        }

        // Journal trend (last 7 days) - exclude soft-deleted
        $journalTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dayJournals = Journal::whereDate('date', $date)
                ->whereHas('placement.student', function ($q) {
                    $q->whereNull('deleted_at');
                })
                ->get();

            $journalTrend[] = [
                'date' => $date->format('Y-m-d'),
                'submittedCount' => $dayJournals->count(),
                'approvedCount' => $dayJournals->where('status', 'APPROVED')->count(),
            ];
        }

        // Visit stats - exclude soft-deleted teachers if needed, but primarily focusing on student/dudi mapping for now
        $totalVisits = Visit::whereHas('teacher', function ($q) {
            $q->whereNull('deleted_at');
        })->count();
        $thisMonthVisits = Visit::whereHas('teacher', function ($q) {
            $q->whereNull('deleted_at');
        })
            ->whereMonth('visit_date', now()->month)
            ->whereYear('visit_date', now()->year)
            ->count();

        // Placement stats - exclude soft-deleted students
        $activePlacements = Placement::where('status', 'ACTIVE')
            ->whereHas('student', function ($q) {
                $q->whereNull('deleted_at');
            })
            ->distinct('student_id')->count('student_id');

        $finishedPlacements = Placement::where('status', 'FINISHED')
            ->whereHas('student', function ($q) {
                $q->whereNull('deleted_at');
            })
            ->distinct('student_id')->count('student_id');

        $totalPlacements = Placement::whereHas('student', function ($q) {
            $q->whereNull('deleted_at');
        })
            ->distinct('student_id')->count('student_id');

        return response()->json([
            'attendanceTrend' => $attendanceTrend,
            'journalTrend' => $journalTrend,
            'visitStats' => [
                'totalVisits' => $totalVisits,
                'thisMonthVisits' => $thisMonthVisits,
            ],
            'placementStats' => [
                'active' => $activePlacements,
                'finished' => $finishedPlacements,
                'total' => $totalPlacements,
            ],
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
                ->whereHas('student', function ($sq) {
                    $sq->whereNull('deleted_at');
                })
                ->when($activeYear, function ($q) use ($activeYear) {
                    $q->where('academic_year_id', $activeYear->id);
                    $q->whereHas('student', function ($sq) use ($activeYear) {
                        $sq->where('academic_year', $activeYear->name);
                    });
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
            // Use DUDI start/end date if available, otherwise Academic Year
            $startDate = $dudi->start_date ? Carbon::parse($dudi->start_date)
                : ($activeYear ? Carbon::parse($activeYear->start_date) : now()->startOfYear());
            $endDate = $dudi->end_date ? Carbon::parse($dudi->end_date)
                : ($activeYear ? Carbon::parse($activeYear->end_date ?? now()) : now());

            // Clamp dates
            if ($startDate->isFuture()) {
                $totalWorkingDays = 0;
            } else {
                if ($endDate->isFuture())
                    $endDate = Carbon::today();

                // Fetch holidays in range
                $holidays = Holiday::whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                    ->pluck('date')
                    ->map(fn($d) => $d->format('Y-m-d'))
                    ->toArray();

                $totalWorkingDays = 0;
                $curr = $startDate->copy();
                while ($curr->lte($endDate)) {
                    if (!$curr->isWeekend() && !in_array($curr->format('Y-m-d'), $holidays)) {
                        $totalWorkingDays++;
                    }
                    $curr->addDay();
                }
            }

            $totalAttendanceDays = $onTime + $late + $sick + $permit;
            // logic: Expected Total Attendance = Working Days * Student Count
            // Alpha = Expected - Actual
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
        $academicYearId = $request->academicYearId ?? $request->academicYear;

        // Resolve academic year
        $academicYear = null;
        if ($academicYearId && $academicYearId !== 'ALL') {
            $academicYear = AcademicYear::find($academicYearId)
                ?? AcademicYear::where('name', $academicYearId)->first();
        } else {
            $academicYear = AcademicYear::where('is_active', true)->first();
        }

        // Get DUDI info
        $dudi = Dudi::find($id);
        if (!$dudi) {
            return response()->json(['message' => 'DUDI not found'], 404);
        }

        // Determine date range for calculation
        // Use DUDI start/end date if available, otherwise fallback to Academic Year
        $startDate = $dudi->start_date
            ? Carbon::parse($dudi->start_date)
            : ($academicYear ? Carbon::parse($academicYear->start_date ?? now()->startOfYear()) : now()->startOfMonth());

        $endDate = $dudi->end_date
            ? Carbon::parse($dudi->end_date)
            : ($academicYear ? Carbon::parse($academicYear->end_date ?? now()) : now());

        // Cap end date at today if the period extends into future
        if ($endDate->isFuture()) {
            $endDate = Carbon::today();
        }

        // If start date is in future, no alpha calculation possible yet
        if ($startDate->isFuture()) {
            $workingDays = [];
        } else {
            // Pre-calculate working days (excluding weekends and holidays)
            $workingDays = [];
            $currentDate = $startDate->copy();

            // Get all holidays in range
            $holidays = Holiday::whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->pluck('date')
                ->map(fn($d) => $d->format('Y-m-d'))
                ->toArray();

            while ($currentDate->lte($endDate)) {
                $dateStr = $currentDate->format('Y-m-d');
                $isWeekend = $currentDate->isWeekend();
                $isHoliday = in_array($dateStr, $holidays);

                if (!$isWeekend && !$isHoliday) {
                    $workingDays[] = $dateStr;
                }
                $currentDate->addDay();
            }
        }

        $placements = Placement::with(['student.user'])
            ->where('dudi_id', $id)
            ->where('status', 'ACTIVE')
            ->when($academicYear, function ($q) use ($academicYear) {
                $q->where('academic_year_id', $academicYear->id);
                $q->whereHas('student', function ($sq) use ($academicYear) {
                    $sq->where('academic_year', $academicYear->name);
                });
            })
            ->get();

        $students = $placements->map(function ($p) use ($workingDays) {
            // Get all attendance dates for this student
            $attendanceDates = Attendance::where('placement_id', $p->id)
                ->whereIn('status', ['ON_TIME', 'LATE', 'SICK', 'PERMIT']) // Only count valid attributes
                ->pluck('date')
                ->map(fn($d) => $d->format('Y-m-d'))
                ->toArray();

            // Calculate alpha dates: Working days where student has NO attendance
            $alphaDates = array_diff($workingDays, $attendanceDates);
            $alphaCount = count($alphaDates);

            // Re-fetch aggregate stats for display
            $attendances = Attendance::where('placement_id', $p->id)->get();
            $onTime = $attendances->where('status', 'ON_TIME')->count();
            $late = $attendances->where('status', 'LATE')->count();
            $sick = $attendances->where('status', 'SICK')->count();
            $permit = $attendances->where('status', 'PERMIT')->count();

            return [
                'studentId' => $p->student_id,
                'studentName' => $p->student->user->name ?? '',
                'studentNis' => $p->student->nis ?? '',
                'nis' => $p->student->nis ?? '', // Added for modal
                'className' => $p->student->class_name ?? '', // Fixed: studentClassName -> className
                'studentPhone' => $p->student->user->phone ?? '',
                'phone' => $p->student->user->phone ?? '', // Added for modal
                'studentEmail' => $p->student->user->email ?? '',
                'email' => $p->student->user->email ?? '', // Added for modal
                'avatarUrl' => $p->student->user->avatar_url ?? null, // Added avatarUrl
                'onTime' => $onTime,
                'late' => $late,
                'sick' => $sick,
                'permit' => $permit,
                'alphaCount' => $alphaCount, // Fixed: alpha -> alphaCount
                'alphaDates' => array_values($alphaDates), // Added alphaDates
            ];
        });

        // Structure expected by frontend: { dudi: ..., students: [...] }
        return response()->json([
            'dudi' => [
                'id' => $dudi->id,
                'name' => $dudi->name,
                'startDate' => $dudi->start_date ? $dudi->start_date->format('Y-m-d') : null,
                'endDate' => $dudi->end_date ? $dudi->end_date->format('Y-m-d') : null,
            ],
            'students' => $students
        ]);
    }

    public function teacher(Request $request)
    {
        $user = $request->user();

        // Resolve active year
        $activeYear = \App\Models\AcademicYear::where('is_active', true)->first();

        $placements = Placement::with(['student.user', 'dudi'])
            ->where('teacher_id', $user->id)
            ->where('status', 'ACTIVE')
            ->when($activeYear, function ($q) use ($activeYear) {
                $q->where('academic_year_id', $activeYear->id);
                $q->whereHas('student', function ($sq) use ($activeYear) {
                    $sq->where('academic_year', $activeYear->name);
                    $sq->whereNull('deleted_at');
                });
            })
            ->get();
        $placementIds = $placements->pluck('id');

        $totalStudents = $placements->unique('student_id')->count();
        $dudiIds = $placements->pluck('dudi_id')->unique();
        $dudiCount = $dudiIds->count();

        // Pending journals
        $pendingJournals = Journal::whereIn('placement_id', $placementIds)
            ->where('status', 'PENDING')
            ->count();

        // Calculate Average Attendance
        // Logic: (OnTime + Late) / Total Recorded * 100
        $allAttendance = Attendance::whereIn('placement_id', $placementIds)->get();
        $presentCount = $allAttendance->whereIn('status', ['ON_TIME', 'LATE'])->count();
        $totalRecorded = $allAttendance->count();
        $avgAttendance = $totalRecorded > 0 ? round(($presentCount / $totalRecorded) * 100, 1) : 0;

        // Open issues count
        $openIssuesCount = Issue::whereIn('placement_id', $placementIds)
            ->where('status', 'OPEN')
            ->count();

        // Problematic Students (Students with Open Issues)
        $problematicStudents = Issue::with(['placement.student.user'])
            ->whereIn('placement_id', $placementIds)
            ->where('status', 'OPEN')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($issue) {
                return [
                    'id' => $issue->placement->student_id,
                    'name' => $issue->placement->student->user->name ?? 'Unknown',
                    'class' => $issue->placement->student->class_name ?? '',
                    'status' => $issue->severity, // Use severity as status badge
                    'issue' => $issue->description,
                ];
            });

        // Recent Journals
        $recentJournals = Journal::with(['placement.student.user'])
            ->whereIn('placement_id', $placementIds)
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->map(function ($j) {
                return [
                    'id' => $j->id,
                    'title' => 'Jurnal Harian', // Or truncate description
                    'name' => $j->placement->student->user->name ?? 'Unknown',
                    'date' => $j->date->format('d M'),
                    'status' => $j->status === 'PENDING' ? 'Pending Review' : 'Approved',
                ];
            });

        return response()->json([
            'totalStudents' => $totalStudents,
            'pendingJournals' => $pendingJournals,
            'avgAttendance' => $avgAttendance,
            'problematicCount' => $openIssuesCount, // For the card
            'problematicStudents' => $problematicStudents,
            'recentJournals' => $recentJournals,
            // Extra stats if needed
            'dudiCount' => $dudiCount,
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
                ->whereHas('student', function ($sq) {
                    $sq->whereNull('deleted_at');
                })
                ->when($activeYear, function ($q) use ($activeYear) {
                    $q->where('academic_year_id', $activeYear->id);
                    $q->whereHas('student', function ($sq) use ($activeYear) {
                        $sq->where('academic_year', $activeYear->name);
                    });
                })
                ->pluck('id');

            $studentCount = $placements->count();

            $attendances = Attendance::whereIn('placement_id', $placements)->get();
            $onTime = $attendances->where('status', 'ON_TIME')->count();
            $late = $attendances->where('status', 'LATE')->count();
            $sick = $attendances->where('status', 'SICK')->count();
            $permit = $attendances->where('status', 'PERMIT')->count();

            // Calculate Working Days
            $startDate = $dudi->start_date
                ? Carbon::parse($dudi->start_date)
                : ($activeYear ? Carbon::parse($activeYear->start_date ?? now()->startOfYear()) : now()->startOfMonth());

            $endDate = $dudi->end_date
                ? Carbon::parse($dudi->end_date)
                : ($activeYear ? Carbon::parse($activeYear->end_date ?? now()) : now());

            if ($endDate->isFuture()) {
                $endDate = Carbon::today();
            }

            $totalWorkingDays = 0;
            if (!$startDate->isFuture()) {
                // Get holidays
                $holidays = Holiday::whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                    ->pluck('date')
                    ->map(fn($d) => $d->format('Y-m-d'))
                    ->toArray();

                $currentDate = $startDate->copy();
                while ($currentDate->lte($endDate)) {
                    $dateStr = $currentDate->format('Y-m-d');
                    if (!$currentDate->isWeekend() && !in_array($dateStr, $holidays)) {
                        $totalWorkingDays++;
                    }
                    $currentDate->addDay();
                }
            }

            $totalAttendanceDays = $onTime + $late + $sick + $permit;
            $alpha = max(0, ($totalWorkingDays * $studentCount) - $totalAttendanceDays);

            return [
                'dudiId' => $dudi->id,
                'dudiName' => $dudi->name,
                'totalStudents' => $studentCount, // Renamed from studentCount
                'stats' => [
                    'present' => $onTime, // Renamed from onTime
                    'late' => $late,
                    'sick' => $sick,
                    'permit' => $permit,
                    'alpha' => $alpha,
                ]
            ];
        });

        return response()->json($stats);
    }

    public function teacherAnalysis(Request $request)
    {
        $user = $request->user();
        $academicYearId = $request->academicYearId;

        $activeYear = \App\Models\AcademicYear::where('is_active', true)->first();

        $placements = Placement::where('teacher_id', $user->id)
            ->where('status', 'ACTIVE')
            ->when($activeYear, function ($q) use ($activeYear) {
                $q->where('academic_year_id', $activeYear->id);
                $q->whereHas('student', function ($sq) use ($activeYear) {
                    $sq->where('academic_year', $activeYear->name);
                    $sq->whereNull('deleted_at');
                });
            })
            ->get();
        $placementIds = $placements->pluck('id');

        // Attendance trend (last 7 days) with presentCount, lateCount, absentCount
        $attendanceTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dayAttendances = Attendance::whereIn('placement_id', $placementIds)
                ->whereDate('date', $date)
                ->get();
            $attendanceTrend[] = [
                'date' => $date->format('Y-m-d'),
                'activeCount' => $dayAttendances->count(),
                'hadir' => $dayAttendances->where('status', 'ON_TIME')->count(), // Renaming presentCount to hadir for consistency if frontend expects it, or keep frontend mapping
                'terlambat' => $dayAttendances->where('status', 'LATE')->count(),
                'izin' => $dayAttendances->where('status', 'PERMIT')->count(),
                'sakit' => $dayAttendances->where('status', 'SICK')->count(),
                'alfa' => $dayAttendances->where('status', 'ABSENT')->count(),
            ];
        }

        // Journal trend (last 7 days)
        $journalTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $dayJournals = Journal::whereIn('placement_id', $placementIds)
                ->whereDate('date', $date)
                ->get();
            $journalTrend[] = [
                'date' => $date->format('Y-m-d'),
                'submittedCount' => $dayJournals->count(),
                'approvedCount' => $dayJournals->where('status', 'APPROVED')->count(),
            ];
        }

        // Visit stats
        $totalVisits = Visit::where('teacher_id', $user->id)->count();
        $thisMonthVisits = Visit::where('teacher_id', $user->id)
            ->whereMonth('visit_date', now()->month)
            ->whereYear('visit_date', now()->year)
            ->count();

        // Placement stats
        $activePlacements = $placements->where('status', 'ACTIVE')->count();
        $finishedPlacements = Placement::where('teacher_id', $user->id)
            ->where('status', 'FINISHED')
            ->count();
        $totalPlacements = Placement::where('teacher_id', $user->id)->count();

        return response()->json([
            'attendanceTrend' => $attendanceTrend,
            'journalTrend' => $journalTrend,
            'visitStats' => [
                'totalVisits' => $totalVisits,
                'thisMonthVisits' => $thisMonthVisits,
            ],
            'placementStats' => [
                'active' => $activePlacements,
                'finished' => $finishedPlacements,
                'total' => $totalPlacements,
            ],
        ]);
    }

    public function teacherDudiDetails(Request $request, $dudiId)
    {
        $user = $request->user();
        $academicYearId = $request->academicYearId ?? $request->academicYear;

        $activeYear = $academicYearId && $academicYearId !== 'ALL'
            ? AcademicYear::find($academicYearId)
            : AcademicYear::where('is_active', true)->first();

        // Get DUDI info for date range
        $dudi = Dudi::find($dudiId);

        // Determine date range for calculation
        $startDate = $dudi && $dudi->start_date
            ? Carbon::parse($dudi->start_date)
            : ($activeYear ? Carbon::parse($activeYear->start_date ?? now()->startOfYear()) : now()->startOfMonth());

        $endDate = $dudi && $dudi->end_date
            ? Carbon::parse($dudi->end_date)
            : ($activeYear ? Carbon::parse($activeYear->end_date ?? now()) : now());

        if ($endDate->isFuture()) {
            $endDate = Carbon::today();
        }

        if ($startDate->isFuture()) {
            $workingDays = [];
        } else {
            $workingDays = [];
            $currentDate = $startDate->copy();

            $holidays = Holiday::whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
                ->pluck('date')
                ->map(fn($d) => $d->format('Y-m-d'))
                ->toArray();

            while ($currentDate->lte($endDate)) {
                $dateStr = $currentDate->format('Y-m-d');
                $isWeekend = $currentDate->isWeekend();
                $isHoliday = in_array($dateStr, $holidays);

                if (!$isWeekend && !$isHoliday) {
                    $workingDays[] = $dateStr;
                }
                $currentDate->addDay();
            }
        }

        $placements = Placement::with(['student.user'])
            ->where('dudi_id', $dudiId)
            ->where('teacher_id', $user->id)
            ->where('status', 'ACTIVE')
            ->when($activeYear, function ($q) use ($activeYear) {
                $q->where('academic_year_id', $activeYear->id);
            })
            ->get();

        $students = $placements->map(function ($p) use ($workingDays) {
            // Get attendance dates
            $attendanceDates = Attendance::where('placement_id', $p->id)
                ->whereIn('status', ['ON_TIME', 'LATE', 'SICK', 'PERMIT'])
                ->pluck('date')
                ->map(fn($d) => $d->format('Y-m-d'))
                ->toArray();

            // Calculate alpha
            $alphaDates = array_diff($workingDays, $attendanceDates);
            $alphaCount = count($alphaDates);

            $attendances = Attendance::where('placement_id', $p->id)->get();
            $onTime = $attendances->where('status', 'ON_TIME')->count();
            $late = $attendances->where('status', 'LATE')->count();
            $sick = $attendances->where('status', 'SICK')->count();
            $permit = $attendances->where('status', 'PERMIT')->count();

            return [
                'studentId' => $p->student_id,
                'studentName' => $p->student->user->name ?? '',
                'studentNis' => $p->student->nis ?? '',
                'nis' => $p->student->nis ?? '', // Added for modal
                'className' => $p->student->class_name ?? '',
                'studentPhone' => $p->student->user->phone ?? '',
                'phone' => $p->student->user->phone ?? '', // Added for modal
                'studentEmail' => $p->student->user->email ?? '',
                'email' => $p->student->user->email ?? '', // Added for modal
                'avatarUrl' => $p->student->user->avatar_url ?? null,
                'onTime' => $onTime,
                'late' => $late,
                'sick' => $sick,
                'permit' => $permit,
                'alphaCount' => $alphaCount,
                'alphaDates' => array_values($alphaDates),
            ];
        });

        return response()->json([
            'dudi' => [
                'id' => $dudi->id ?? $dudiId,
                'name' => $dudi->name ?? '',
                'startDate' => $dudi && $dudi->start_date ? $dudi->start_date->format('Y-m-d') : null,
                'endDate' => $dudi && $dudi->end_date ? $dudi->end_date->format('Y-m-d') : null,
            ],
            'students' => $students
        ]);
    }

    public function student(Request $request)
    {
        $user = $request->user();

        $placement = Placement::with(['dudi', 'teacher.user', 'academicYear'])
            ->where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json([
                'attendance' => ['totalPresent' => 0, 'totalPermit' => 0, 'totalAbsent' => 0],
                'journal' => ['total' => 0],
                'badges' => [],
                'schoolInfo' => ['name' => Setting::getValue('institutionName', ''), 'academicYear' => ''],
                'teacherInfo' => null
            ]);
        }

        $schoolName = Setting::getValue('institutionName', '');

        // Attendance stats
        $attendances = Attendance::where('placement_id', $placement->id)->get();
        $totalPresent = $attendances->whereIn('status', ['ON_TIME', 'LATE'])->count();
        $totalPermit = $attendances->whereIn('status', ['PERMIT', 'SICK'])->count();
        $totalAbsent = $attendances->where('status', 'ABSENT')->count();

        // Journal stats
        $journalTotal = Journal::where('placement_id', $placement->id)->count();

        return response()->json([
            'attendance' => [
                'totalPresent' => $totalPresent,
                'totalPermit' => $totalPermit,
                'totalAbsent' => $totalAbsent,
            ],
            'journal' => [
                'total' => $journalTotal,
            ],
            'badges' => [],
            'schoolInfo' => [
                'name' => $schoolName, // Dynamic from settings
                'academicYear' => $placement->academicYear->name ?? '',
            ],
            'teacherInfo' => $placement->teacher ? [
                'name' => $placement->teacher->user->name ?? '',
                'school' => $schoolName,
                'phone' => $placement->teacher->user->phone ?? '',
            ] : null,
        ]);
    }

    public function mentor(Request $request)
    {
        $user = $request->user();
        $mentor = Mentor::with('dudi')->where('user_id', $user->id)->first();

        if (!$mentor || !$mentor->dudi_id) {
            return response()->json([
                'dudiName' => 'Belum Terhubung',
                'totalStudents' => 0,
                'attendanceToday' => ['present' => 0, 'late' => 0, 'absent' => 0],
                'pendingJournals' => 0,
                'students' => [],
                'teachers' => [],
            ]);
        }

        // Active placements at this DUDI
        $placements = Placement::with(['student.user', 'teacher.user'])
            ->where('dudi_id', $mentor->dudi_id)
            ->where('status', 'ACTIVE')
            ->get();

        $placementIds = $placements->pluck('id');

        // Detailed Attendance Today
        $today = Carbon::today()->format('Y-m-d');
        $todayAttendances = Attendance::whereIn('placement_id', $placementIds)
            ->whereDate('date', $today)
            ->get();

        $attendanceStats = [
            'present' => $todayAttendances->where('status', 'ON_TIME')->count(),
            'late' => $todayAttendances->where('status', 'LATE')->count(),
            'absent' => $todayAttendances->where('status', 'ABSENT')->count(),
        ];

        // Pending Journals
        $pendingJournals = Journal::whereIn('placement_id', $placementIds)
            ->where('status', 'PENDING')
            ->count();

        // Unique Teachers
        $teachers = $placements->map(function ($p) {
            return $p->teacher ? [
                'name' => $p->teacher->user->name ?? '',
                'school' => Setting::getValue('institutionName', ''),
                'phone' => $p->teacher->user->phone ?? '',
            ] : null;
        })->filter()->unique('name')->values();

        return response()->json([
            'dudiName' => $mentor->dudi->name ?? '',
            'totalStudents' => $placements->count(),
            'attendanceToday' => $attendanceStats,
            'pendingJournals' => $pendingJournals,
            'students' => $placements->map(function ($p) {
                return [
                    'id' => $p->student_id,
                    'name' => $p->student->user->name ?? '',
                ];
            }),
            'teachers' => $teachers,
        ]);
    }
}
