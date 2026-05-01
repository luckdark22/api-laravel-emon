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
use App\Models\Assessment;

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
            $dayAttendancesQuery = Attendance::where('date', $date->toDateString())
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
            $isHoliday = Holiday::where('date', $date->toDateString())->exists();

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

        $pklRange = Dudi::whereHas('placements', function ($q) use ($academicYear) {
                if ($academicYear) $q->where('academic_year_id', $academicYear->id);
            })
            ->selectRaw('MIN(start_date) as start, MAX(end_date) as end')
            ->first();

        return response()->json([
            'stats' => [
                'totalStudents' => $totalStudents,
                'mappedStudents' => $activePlacements,
                'totalDudi' => $totalDudi,
                'placementPercentage' => $placementPercentage,
                'issueCount' => $openIssues,
                'pklRange' => [
                    'start' => $pklRange->start ?? null,
                    'end' => $pklRange->end ?? null,
                ],
            ],
            'attendanceTrend' => $attendanceTrend,
            'majorDistribution' => $majorDistribution,
            'recentActivities' => $recentActivities,
        ]);
    }

    public function adminAnalysis(Request $request)
    {
        $dudiId = $request->dudiId;
        $academicYearId = $request->academicYearId ?? $request->academicYear;
        $activeYear = $academicYearId && $academicYearId !== 'ALL'
            ? AcademicYear::where('name', $academicYearId)->first() ?? AcademicYear::find($academicYearId)
            : AcademicYear::where('is_active', true)->first();

        // Attendance trend (Range) - exclude soft-deleted
        $attendanceTrend = [];
        
        $pklRange = Dudi::whereHas('placements', function ($q) use ($activeYear, $dudiId) {
                if ($activeYear) $q->where('academic_year_id', $activeYear->id);
                if ($dudiId && $dudiId !== 'ALL') $q->where('dudi_id', $dudiId);
            })
            ->selectRaw('MIN(start_date) as start, MAX(end_date) as end')
            ->first();

        if ($request->period === 'pkl' && $pklRange->start && $pklRange->end) {
            $startDate = Carbon::parse($pklRange->start);
            $endDate = Carbon::parse($pklRange->end);
        } else {
            $startDate = $request->startDate ? Carbon::parse($request->startDate) : Carbon::today()->subDays(6);
            $endDate = $request->endDate ? Carbon::parse($request->endDate) : Carbon::today();

            // Limit range to 31 days for performance if not full PKL period
            if ($startDate->diffInDays($endDate) > 31) {
                $startDate = $endDate->copy()->subDays(31);
            }
        }

        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $date = $currentDate->copy();
            $dayAttendances = Attendance::where('date', $date->toDateString())
                ->whereHas('placement.student', function ($q) {
                    $q->whereNull('deleted_at');
                })
                ->get();

            // Calculate Alpha for this date
            $isWeekend = $date->isWeekend();
            $isHoliday = Holiday::where('date', $date->toDateString())->exists();
            $alpha = 0;
            if (!$isWeekend && !$isHoliday) {
                // Better approach: Check each student's individual DUDI period
                $placements = Placement::with('dudi')
                    ->where('status', 'ACTIVE')
                    ->whereHas('student', function ($q) use ($activeYear) {
                        $q->whereNull('deleted_at');
                        if ($activeYear) $q->where('academic_year', $activeYear->name);
                    })
                    ->get();

                $activeOnThisDayIds = $placements->filter(function ($p) use ($date) {
                    $startStr = $p->dudi->start_date ? Carbon::parse($p->dudi->start_date)->toDateString() : null;
                    $endStr = $p->dudi->end_date ? Carbon::parse($p->dudi->end_date)->toDateString() : null;
                    return (!$startStr || $date->gte($startStr)) && (!$endStr || $date->lte($endStr));
                })->pluck('id')->toArray();

                $expectedOnThisDay = count($activeOnThisDayIds);

                // Fetch APPROVED leaves for this day
                $dayLeaves = Leave::where('status', 'APPROVED')
                    ->where('start_date', '<=', $date->toDateString())
                    ->where('end_date', '>=', $date->toDateString())
                    ->whereIn('placement_id', $activeOnThisDayIds)
                    ->get();

                $presentStudentIds = $dayAttendances->whereIn('status', ['ON_TIME', 'LATE', 'PERMIT', 'SICK'])->pluck('placement_id')->toArray();
                $leaveStudentIds = $dayLeaves->pluck('placement_id')->toArray();
                $notAlphaCount = count(array_unique(array_merge($presentStudentIds, $leaveStudentIds)));

                $alpha = max(0, $expectedOnThisDay - $notAlphaCount);
            } else {
                $alpha = 0;
                $expectedOnThisDay = Student::whereNull('deleted_at')
                    ->where('academic_year', $activeYear ? $activeYear->name : '')
                    ->count();
                $dayLeaves = collect();
            }

            $attendanceTrend[] = [
                'date' => $date->format('Y-m-d'),
                'activeCount' => $expectedOnThisDay,
                'hadir' => $dayAttendances->where('status', 'ON_TIME')->count(),
                'terlambat' => $dayAttendances->where('status', 'LATE')->count(),
                'izin' => $dayAttendances->where('status', 'PERMIT')->count() + $dayLeaves->where('type', 'PERMIT')->count(),
                'sakit' => $dayAttendances->where('status', 'SICK')->count() + $dayLeaves->where('type', 'SICK')->count(),
                'alfa' => $alpha,
            ];
            $currentDate->addDay();
        }

        // Journal trend (Range) - exclude soft-deleted
        $journalTrend = [];
        $currentDateJ = $startDate->copy();
        while ($currentDateJ->lte($endDate)) {
            $date = $currentDateJ->copy();
            $dayJournals = Journal::where('date', $date->toDateString())
                ->whereHas('placement', function ($q) use ($activeYear, $dudiId) {
                    if ($activeYear) {
                        $q->where('academic_year_id', $activeYear->id);
                    }
                    if ($dudiId && $dudiId !== 'ALL') {
                        $q->where('dudi_id', $dudiId);
                    }
                })
                ->whereHas('placement.student', function ($q) {
                    $q->whereNull('deleted_at');
                })
                ->get();

            $journalTrend[] = [
                'date' => $date->format('Y-m-d'),
                'submittedCount' => $dayJournals->count(),
                'approvedCount' => $dayJournals->where('status', 'APPROVED')->count(),
            ];
            $currentDateJ->addDay();
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

        $finishedPlacements = Placement::where(function ($q) {
            $q->where('status', 'FINISHED')
                ->orWhereHas('finalReport', function ($q) {
                    $q->where('status', 'APPROVED')->orWhere('final_grade', '>', 0);
                });
        })
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
                'pklRange' => [
                    'start' => $pklRange->start ?? null,
                    'end' => $pklRange->end ?? null,
                ],
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

        // Pre-fetch all holidays to avoid DB queries inside loops
        $holidayDates = Holiday::when($activeYear, function($q) use ($activeYear) {
            $q->whereBetween('date', [$activeYear->start_date ?? now()->startOfYear(), $activeYear->end_date ?? now()->addYear()]);
        })->pluck('date')->map(fn($d) => $d->format('Y-m-d'))->toArray();

        $stats = $dudis->map(function ($dudi) use ($activeYear, $holidayDates) {
            $placements = Placement::with('student')->where('dudi_id', $dudi->id)
                ->where('status', 'ACTIVE')
                ->whereHas('student', function ($sq) {
                    $sq->whereNull('deleted_at');
                })
                ->when($activeYear, function ($q) use ($activeYear) {
                    $q->where('academic_year_id', $activeYear->id);
                })
                ->get();

            if ($placements->isEmpty()) {
                return null;
            }

            $totalDudiAlpha = 0;
            $totalDudiOnTime = 0;
            $totalDudiLate = 0;
            $totalDudiSick = 0;
            $totalDudiPermit = 0;

            foreach ($placements as $p) {
                // Individual student stats
                $studentAttendances = Attendance::where('placement_id', $p->id)->get();
                $sOnTime = $studentAttendances->where('status', 'ON_TIME')->count();
                $sLate = $studentAttendances->where('status', 'LATE')->count();
                $sSick = $studentAttendances->where('status', 'SICK')->count();
                $sPermit = $studentAttendances->where('status', 'PERMIT')->count();

                // Calculate working days for THIS student
                // Base start date: Dudi start date or Academic Year start date
                $baseStart = $dudi->start_date ? Carbon::parse($dudi->start_date)
                    : ($activeYear ? Carbon::parse($activeYear->start_date) : now()->startOfYear());
                
                // Effective start: max of baseStart and when the student was placed
                $effectiveStart = $p->created_at->gt($baseStart) ? $p->created_at->startOfDay() : $baseStart->startOfDay();
                
                $baseEnd = $dudi->end_date ? Carbon::parse($dudi->end_date)
                    : ($activeYear ? Carbon::parse($activeYear->end_date ?? now()) : now());
                
                $effectiveEnd = $baseEnd->isFuture() ? Carbon::today() : $baseEnd->startOfDay();

                $studentWorkingDays = 0;
                if (!$effectiveStart->isFuture()) {
                    $curr = $effectiveStart->copy();
                    while ($curr->lte($effectiveEnd)) {
                        if (!$curr->isWeekend() && !in_array($curr->format('Y-m-d'), $holidayDates)) {
                            $studentWorkingDays++;
                        }
                        $curr->addDay();
                    }
                }

                $studentActual = $sOnTime + $sLate + $sSick + $sPermit;
                $studentAlpha = max(0, $studentWorkingDays - $studentActual);

                // Add to DUDI totals
                $totalDudiOnTime += $sOnTime;
                $totalDudiLate += $sLate;
                $totalDudiSick += $sSick;
                $totalDudiPermit += $sPermit;
                $totalDudiAlpha += $studentAlpha;
            }

            return [
                'dudiId' => $dudi->id,
                'dudiName' => $dudi->name,
                'totalStudents' => $placements->count(),
                'stats' => [
                    'present' => $totalDudiOnTime,
                    'late' => $totalDudiLate,
                    'sick' => $totalDudiSick,
                    'permit' => $totalDudiPermit,
                    'alpha' => $totalDudiAlpha,
                ],
            ];
        })->filter()->values();

        return response()->json($stats);
    }

    private function countWorkingDays($activeYear)
    {
        // Fallback to global settings if academic year dates are empty
        $rawStart = ($activeYear && $activeYear->start_date) 
            ? $activeYear->start_date 
            : Setting::getValue('pklStartDate');
            
        $rawEnd = ($activeYear && $activeYear->end_date) 
            ? $activeYear->end_date 
            : Setting::getValue('pklEndDate');

        if (!$rawStart) {
            return 0;
        }

        $start = Carbon::parse($rawStart)->startOfDay();
        $end = ($rawEnd ? Carbon::parse($rawEnd) : Carbon::today())->startOfDay();

        // Jika periode masih berjalan (end date di masa depan), batasi sampai hari ini
        if ($end->isFuture()) {
            $end = Carbon::today();
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
                'startDate' => $dudi->start_date ? Carbon::parse($dudi->start_date)->toDateString() : null,
                'endDate' => $dudi->end_date ? Carbon::parse($dudi->end_date)->toDateString() : null,
            ],
            'students' => $students
        ]);
    }

    public function teacher(Request $request)
    {
        $user = $request->user();

        // Resolve active year
        $activeYear = AcademicYear::where('is_active', true)->first();

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

        // Calculate Average Attendance (Including Alpha) - Accurate Per Student/DUDI
        $totalPossibleAttendance = 0;
        
        // Pre-fetch all holidays for the range
        $holidayDates = Holiday::pluck('date')->map(fn($d) => $d->format('Y-m-d'))->toArray();

        foreach ($placements as $placement) {
            $dudi = $placement->dudi;
            
            // Base start date: Dudi start date or Academic Year start date
            $baseStart = ($dudi && $dudi->start_date) ? Carbon::parse($dudi->start_date) 
                : (($activeYear && $activeYear->start_date) ? Carbon::parse($activeYear->start_date) : now()->startOfYear());
            
            // Effective start: max of baseStart and when the student was placed
            $start = $placement->created_at->gt($baseStart) ? $placement->created_at->startOfDay() : $baseStart->startOfDay();
            
            $baseEnd = ($dudi && $dudi->end_date) ? Carbon::parse($dudi->end_date) 
                : (($activeYear && $activeYear->end_date) ? Carbon::parse($activeYear->end_date) : now());
            
            $end = $baseEnd->isFuture() ? Carbon::today() : $baseEnd->startOfDay();
            
            if (!$start->isFuture()) {
                $curr = $start->copy();
                while ($curr->lte($end)) {
                    if (!$curr->isWeekend() && !in_array($curr->format('Y-m-d'), $holidayDates)) {
                        $totalPossibleAttendance++;
                    }
                    $curr->addDay();
                }
            }
        }
        
        $actualAttendanceCount = Attendance::whereIn('placement_id', $placementIds)
            ->whereIn('status', ['ON_TIME', 'LATE']) // Changed: only actual presence counts for %
            ->count();

        $avgAttendance = $totalPossibleAttendance > 0 
            ? min(100, round(($actualAttendanceCount / $totalPossibleAttendance) * 100, 1)) 
            : 0;

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
                    'studentId' => $j->placement->student_id,
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

        // Pre-fetch all holidays
        $holidayDates = Holiday::pluck('date')->map(fn($d) => $d->format('Y-m-d'))->toArray();

        $stats = $dudis->map(function ($dudi) use ($user, $activeYear, $holidayDates) {
            $placements = Placement::with('student')->where('dudi_id', $dudi->id)
                ->where('teacher_id', $user->id)
                ->where('status', 'ACTIVE')
                ->whereHas('student', function ($sq) {
                    $sq->whereNull('deleted_at');
                })
                ->when($activeYear, function ($q) use ($activeYear) {
                    $q->where('academic_year_id', $activeYear->id);
                })
                ->get();

            $studentCount = $placements->count();
            
            $totalDudiAlpha = 0;
            $totalDudiOnTime = 0;
            $totalDudiLate = 0;
            $totalDudiSick = 0;
            $totalDudiPermit = 0;

            foreach ($placements as $p) {
                $studentAttendances = Attendance::where('placement_id', $p->id)->get();
                $sOnTime = $studentAttendances->where('status', 'ON_TIME')->count();
                $sLate = $studentAttendances->where('status', 'LATE')->count();
                $sSick = $studentAttendances->where('status', 'SICK')->count();
                $sPermit = $studentAttendances->where('status', 'PERMIT')->count();

                // Calculate working days for THIS student
                $baseStart = $dudi->start_date ? Carbon::parse($dudi->start_date)
                    : ($activeYear ? Carbon::parse($activeYear->start_date) : now()->startOfYear());
                $effectiveStart = $p->created_at->gt($baseStart) ? $p->created_at->startOfDay() : $baseStart->startOfDay();
                
                $baseEnd = $dudi->end_date ? Carbon::parse($dudi->end_date)
                    : ($activeYear ? Carbon::parse($activeYear->end_date ?? now()) : now());
                $effectiveEnd = $baseEnd->isFuture() ? Carbon::today() : $baseEnd->startOfDay();

                $studentWorkingDays = 0;
                if (!$effectiveStart->isFuture()) {
                    $curr = $effectiveStart->copy();
                    while ($curr->lte($effectiveEnd)) {
                        if (!$curr->isWeekend() && !in_array($curr->format('Y-m-d'), $holidayDates)) {
                            $studentWorkingDays++;
                        }
                        $curr->addDay();
                    }
                }

                $studentActual = $sOnTime + $sLate + $sSick + $sPermit;
                $studentAlpha = max(0, $studentWorkingDays - $studentActual);

                $totalDudiOnTime += $sOnTime;
                $totalDudiLate += $sLate;
                $totalDudiSick += $sSick;
                $totalDudiPermit += $sPermit;
                $totalDudiAlpha += $studentAlpha;
            }

            return [
                'dudiId' => $dudi->id,
                'dudiName' => $dudi->name,
                'totalStudents' => $studentCount,
                'stats' => [
                    'present' => $totalDudiOnTime,
                    'late' => $totalDudiLate,
                    'sick' => $totalDudiSick,
                    'permit' => $totalDudiPermit,
                    'alpha' => $totalDudiAlpha,
                ],
            ];
        })->filter()->values();

        return response()->json($stats);
    }

    public function teacherAnalysis(Request $request)
    {
        $user = $request->user();
        $academicYearId = $request->academicYearId ?? $request->academicYear;

        $activeYear = $academicYearId && $academicYearId !== 'ALL'
            ? AcademicYear::where('name', $academicYearId)->first() ?? AcademicYear::find($academicYearId)
            : AcademicYear::where('is_active', true)->first();

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
        $totalStudents = $placements->count();

        // For charts, we want to include students who might have finished but have data in the range
        $allPlacements = Placement::where('teacher_id', $user->id)
            ->when($activeYear, function ($q) use ($activeYear) {
                $q->where('academic_year_id', $activeYear->id);
            })->get();
        $allPlacementIds = $allPlacements->pluck('id');

        $pklRange = Dudi::whereHas('placements', function ($q) use ($user, $activeYear) {
                $q->where('teacher_id', $user->id);
                if ($activeYear) $q->where('academic_year_id', $activeYear->id);
            })
            ->selectRaw('MIN(start_date) as start, MAX(end_date) as end')
            ->first();

        if ($request->period === 'pkl' && $pklRange->start && $pklRange->end) {
            $startDate = Carbon::parse($pklRange->start);
            $endDate = Carbon::parse($pklRange->end);
        } else {
            $startDate = $request->startDate ? Carbon::parse($request->startDate) : Carbon::today()->subDays(6);
            $endDate = $request->endDate ? Carbon::parse($request->endDate) : Carbon::today();

            // Limit range to 31 days for performance if not pkl period
            if ($startDate->diffInDays($endDate) > 31) {
                $startDate = $endDate->copy()->subDays(31);
            }
        }

        $attendanceTrend = [];

        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $date = $currentDate->copy();
            $dayAttendances = Attendance::whereIn('placement_id', $allPlacementIds)
                ->where('date', $date->toDateString())
                ->get();
            
            $dayLeaves = Leave::where('status', 'APPROVED')
                ->where('start_date', '<=', $date->toDateString())
                ->where('end_date', '>=', $date->toDateString())
                ->whereIn('placement_id', $allPlacementIds)
                ->get();

            // Calculate Alpha for this date
            $isWeekend = $date->isWeekend();
            $isHoliday = Holiday::where('date', $date->toDateString())->exists();

            if (!$isWeekend && !$isHoliday) {
                // Filter placements active on this specific date
                $activeOnThisDayIds = $placements->filter(function ($p) use ($date) {
                    $startStr = $p->dudi->start_date ? Carbon::parse($p->dudi->start_date)->toDateString() : null;
                    $endStr = $p->dudi->end_date ? Carbon::parse($p->dudi->end_date)->toDateString() : null;
                    return (!$startStr || $date->gte($startStr)) && (!$endStr || $date->lte($endStr));
                })->pluck('id')->toArray();

                $activeOnThisDayCount = count($activeOnThisDayIds);

                $presentStudentIds = $dayAttendances->whereIn('status', ['ON_TIME', 'LATE', 'PERMIT', 'SICK'])->pluck('placement_id')->toArray();
                $leaveStudentIds = $dayLeaves->whereIn('placement_id', $activeOnThisDayIds)->pluck('placement_id')->toArray();
                $notAlphaCount = count(array_unique(array_merge($presentStudentIds, $leaveStudentIds)));

                $alpha = max(0, $activeOnThisDayCount - $notAlphaCount);
                $currentActiveCount = $activeOnThisDayCount;
            } else {
                $alpha = 0;
                $currentActiveCount = $totalStudents;
            }

            $attendanceTrend[] = [
                'date' => $date->format('Y-m-d'),
                'activeCount' => $currentActiveCount,
                'hadir' => $dayAttendances->where('status', 'ON_TIME')->count(),
                'terlambat' => $dayAttendances->where('status', 'LATE')->count(),
                'izin' => $dayAttendances->where('status', 'PERMIT')->count() + $dayLeaves->where('type', 'PERMIT')->count(),
                'sakit' => $dayAttendances->where('status', 'SICK')->count() + $dayLeaves->where('type', 'SICK')->count(),
                'alfa' => $alpha,
            ];
            $currentDate->addDay();
        }

        // Journal trend (Range)
        $journalTrend = [];
        $currentDateJ = $startDate->copy();
        while ($currentDateJ->lte($endDate)) {
            $date = $currentDateJ->copy();
            $dayJournals = Journal::whereIn('placement_id', $allPlacementIds)
                ->where('date', $date->toDateString())
                ->get();
            $journalTrend[] = [
                'date' => $date->format('Y-m-d'),
                'submittedCount' => $dayJournals->count(),
                'approvedCount' => $dayJournals->where('status', 'APPROVED')->count(),
            ];
            $currentDateJ->addDay();
        }

        // Visit stats
        $totalVisits = Visit::where('teacher_id', $user->id)->count();
        $thisMonthVisits = Visit::where('teacher_id', $user->id)
            ->whereMonth('visit_date', now()->month)
            ->whereYear('visit_date', now()->year)
            ->count();

        // Placement stats
        $activePlacementsCount = Placement::where('teacher_id', $user->id)->where('status', 'ACTIVE')->count();
        $finishedPlacementsCount = Placement::where('teacher_id', $user->id)
            ->where(function ($q) {
                $q->where('status', 'FINISHED')
                    ->orWhereHas('finalReport', function ($sq) {
                        $sq->where('status', 'APPROVED')->orWhere('final_grade', '>', 0);
                    });
            })
            ->count();
        $totalPlacementsCount = Placement::where('teacher_id', $user->id)->count();

        return response()->json([
            'attendanceTrend' => $attendanceTrend,
            'journalTrend' => $journalTrend,
            'visitStats' => [
                'totalVisits' => $totalVisits,
                'thisMonthVisits' => $thisMonthVisits,
            ],
            'placementStats' => [
                'active' => $activePlacementsCount,
                'finished' => $finishedPlacementsCount,
                'total' => $totalPlacementsCount,
                'pklRange' => [
                    'start' => $pklRange->start ?? null,
                    'end' => $pklRange->end ?? null,
                ],
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
                'startDate' => $dudi && $dudi->start_date ? Carbon::parse($dudi->start_date)->toDateString() : null,
                'endDate' => $dudi && $dudi->end_date ? Carbon::parse($dudi->end_date)->toDateString() : null,
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

        // Calculate working days for THIS student specifically
        $dudi = $placement->dudi;
        $activeYear = $placement->academicYear;
        $holidayDates = Holiday::pluck('date')->map(fn($d) => $d->format('Y-m-d'))->toArray();

        $baseStart = ($dudi && $dudi->start_date) ? Carbon::parse($dudi->start_date) 
            : (($activeYear && $activeYear->start_date) ? Carbon::parse($activeYear->start_date) : now()->startOfYear());
        $effectiveStart = $placement->created_at->gt($baseStart) ? $placement->created_at->startOfDay() : $baseStart->startOfDay();
        
        $baseEnd = ($dudi && $dudi->end_date) ? Carbon::parse($dudi->end_date) 
            : (($activeYear && $activeYear->end_date) ? Carbon::parse($activeYear->end_date) : now());
        $effectiveEnd = $baseEnd->isFuture() ? Carbon::today() : $baseEnd->startOfDay();

        $workingDaysCount = 0;
        if (!$effectiveStart->isFuture()) {
            $curr = $effectiveStart->copy();
            while ($curr->lte($effectiveEnd)) {
                if (!$curr->isWeekend() && !in_array($curr->format('Y-m-d'), $holidayDates)) {
                    $workingDaysCount++;
                }
                $curr->addDay();
            }
        }

        // Attendance stats
        $attendances = Attendance::where('placement_id', $placement->id)->get();
        $totalPresent = $attendances->whereIn('status', ['ON_TIME', 'LATE'])->count();
        $totalPermit = $attendances->whereIn('status', ['PERMIT', 'SICK'])->count();
        $totalAbsent = $attendances->where('status', 'ABSENT')->count();

        // Journal stats
        $journalTotal = Journal::where('placement_id', $placement->id)->count();

        // --- Badge Logic ---
        $earnedBadges = [];
        
        // 1. Rajin Absen (7 days on_time in a row)
        $latestRecord = Attendance::where('placement_id', $placement->id)
            ->orderBy('date', 'desc')
            ->limit(7)
            ->get();
        if ($latestRecord->count() >= 7 && $latestRecord->where('status', 'ON_TIME')->count() == 7) {
            $earnedBadges[] = 'RAJI_ABSEN';
        }

        // 2. Jurnal Master (>= 10 journals)
        if ($journalTotal >= 10) {
            $earnedBadges[] = 'JURNAL_MASTER';
        }

        // 3. Fast Learner (Average score >= 85)
        $hasHighGrade = Assessment::where('placement_id', $placement->id)
            ->where('final_score', '>=', 85)
            ->exists();
        if ($hasHighGrade) {
            $earnedBadges[] = 'FAST_LEARNER';
        }

        return response()->json([
            'attendance' => [
                'totalPresent' => $totalPresent,
                'totalPermit' => $totalPermit,
                'totalAbsent' => $totalAbsent,
                'workingDays' => $workingDaysCount,
            ],
            'journal' => [
                'total' => $journalTotal,
            ],
            'badges' => $earnedBadges,
            'schoolInfo' => [
                'name' => $schoolName,
                'academicYear' => $placement->academicYear->name ?? '',
            ],
            'placement' => [
                'dudiName' => $dudi->name ?? '',
                'startDate' => $effectiveStart->toDateString(),
                'endDate' => $baseEnd->toDateString(),
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
            ->where('date', $today)
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
