<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Placement;
use App\Models\Mentor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Setting;

class AssessmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Assessment::with(['placement.student.user', 'placement.dudi']);

        $assessments = $query->orderBy('created_at', 'desc')->get()->map(function ($a) {
            return $this->transformAssessment($a);
        });

        return response()->json($assessments);
    }

    public function mentorIndex(Request $request)
    {
        $user = $request->user();
        $mentor = Mentor::where('user_id', $user->id)->first();

        if (!$mentor || !$mentor->dudi_id) {
            return response()->json([]);
        }

        $query = Assessment::with(['placement.student.user', 'placement.dudi'])
            ->whereHas('placement', function ($q) use ($mentor) {
                $q->where('dudi_id', $mentor->dudi_id);
            });

        $assessments = $query->orderBy('created_at', 'desc')->get()->map(function ($a) {
            return $this->transformAssessment($a);
        });

        return response()->json($assessments);
    }

    public function studentIndex(Request $request)
    {
        $user = $request->user();

        $query = Assessment::with(['placement.student.user', 'placement.dudi'])
            ->whereHas('placement', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });

        $assessments = $query->orderBy('created_at', 'desc')->get()->map(function ($a) {
            return $this->transformAssessment($a);
        });

        return response()->json($assessments);
    }

    public function studentShow(Request $request, $id)
    {
        $placement = Placement::with(['student.user', 'dudi', 'assessments', 'academicYear'])
            ->where('student_id', $id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(null, 404);
        }

        $schoolName = Setting::getValue('institutionName', 'SMK NEGERI 1 JAKARTA');

        return response()->json([
            'id' => $placement->student_id,
            'name' => $placement->student->user->name ?? '',
            'school' => $schoolName,
            'dudi' => $placement->dudi->name ?? '',
            'academicYear' => $placement->academicYear->name ?? '',
            'startDate' => $placement->dudi->start_date?->format('Y-m-d'),
            'endDate' => $placement->dudi->end_date?->format('Y-m-d'),
            'assessments' => $placement->assessments->map(function ($a) {
                return $this->transformAssessment($a);
            }),
        ]);
    }

    public function myStudents(Request $request)
    {
        $user = $request->user();

        // Get placements for this teacher/mentor
        $query = Placement::with(['student.user', 'dudi', 'assessments', 'academicYear', 'finalReport'])
            ->withCount([
                'journals' => function ($query) {
                    $query->where('status', 'PENDING');
                }
            ]);

        // ... (rest of filtering logic)
        $academicYearName = $request->academicYear;
        if (!$academicYearName) {
            $activeYear = \App\Models\AcademicYear::where('is_active', true)->first();
            $academicYearName = $activeYear?->name;
        }

        if ($user->role === User::ROLE_TEACHER) {
            $query->where('teacher_id', $user->id);
            if (!$request->status || $request->status === 'ACTIVE') {
                $query->where('status', 'ACTIVE');
            }
        } elseif ($user->role === User::ROLE_MENTOR) {
            $mentor = Mentor::where('user_id', $user->id)->first();
            if ($mentor && $mentor->dudi_id) {
                $query->where('dudi_id', $mentor->dudi_id);
                if (!$request->status || $request->status === 'ACTIVE') {
                    $query->where('status', 'ACTIVE');
                }
            } else {
                return response()->json([]);
            }
        }

        if ($academicYearName) {
            $query->whereHas('academicYear', function ($q) use ($academicYearName) {
                $q->where('name', $academicYearName);
            });
            $query->whereHas('student', function ($q) use ($academicYearName) {
                $q->where('academic_year', $academicYearName);
            });
        }

        $query->whereHas('student', function ($q) {
            $q->whereNull('deleted_at');
        });

        $schoolName = Setting::getValue('institutionName', 'SMK NEGERI 1 JAKARTA');

        $placements = $query->latest('created_at')->get()->map(function ($p) use ($schoolName) {
            return [
                'id' => $p->student_id,
                'placementId' => $p->id,
                'name' => $p->student->user->name ?? '',
                'school' => $schoolName,
                'dudi' => $p->dudi->name ?? '',
                'academicYear' => $p->academicYear->name ?? '',
                'startDate' => $p->dudi->start_date?->format('Y-m-d'),
                'endDate' => $p->dudi->end_date?->format('Y-m-d'),
                'reportStatus' => $p->finalReport->status ?? null,
                'reportGrade' => $p->finalReport->final_grade ?? 0,
                'assessments' => $p->assessments->map(function ($a) {
                    return $this->transformAssessment($a);
                }),
                'pendingJournalCount' => $p->journals_count ?? 0,
            ];
        })->unique('id')->values();

        return response()->json($placements);
    }

    private function transformAssessment($a)
    {
        return [
            'month' => (int) $a->month_period,
            'scores' => [
                'technical' => (int) $a->technical_score,
                'discipline' => (int) $a->discipline_score,
                'social' => (int) $a->social_score,
                'managerial' => (int) $a->managerial_score,
            ],
            'notes' => $a->notes ?? '',
            'isLocked' => (bool) $a->is_locked,
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'studentId' => 'required|string',
            'month' => 'required|integer',
            'scores' => 'required|array',
        ]);

        $placement = Placement::where('student_id', $request->studentId)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(['message' => 'Placement not found'], 400);
        }

        $scores = $request->scores;

        $assessment = Assessment::updateOrCreate(
            [
                'placement_id' => $placement->id,
                'month_period' => $request->month,
            ],
            [
                'technical_score' => $scores['technical'] ?? 0,
                'discipline_score' => $scores['discipline'] ?? 0,
                'social_score' => $scores['social'] ?? 0,
                'managerial_score' => $scores['managerial'] ?? 0,
                'notes' => $request->notes,
                'is_locked' => $request->isLocked ?? false,
                'created_at' => now(),
            ]
        );

        return response()->json($this->transformAssessment($assessment));
    }
}
