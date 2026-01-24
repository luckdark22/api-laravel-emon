<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Placement;
use App\Models\Mentor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        $query = Assessment::with(['placement.student.user', 'placement.dudi'])
            ->whereHas('placement', function ($q) use ($id) {
                $q->where('student_id', $id);
            });

        $assessments = $query->orderBy('created_at', 'desc')->get()->map(function ($a) {
            return $this->transformAssessment($a);
        });

        return response()->json($assessments);
    }

    public function myStudents(Request $request)
    {
        $user = $request->user();

        // Get placements for this teacher/mentor
        $query = Placement::with(['student.user', 'dudi', 'assessments']);

        if ($user->role === User::ROLE_TEACHER) {
            $query->where('teacher_id', $user->id)->where('status', 'ACTIVE');
        } elseif ($user->role === User::ROLE_MENTOR) {
            $mentor = Mentor::where('user_id', $user->id)->first();
            if ($mentor && $mentor->dudi_id) {
                $query->where('dudi_id', $mentor->dudi_id)->where('status', 'ACTIVE');
            } else {
                return response()->json([]);
            }
        }

        $placements = $query->get()->map(function ($p) {
            return [
                'placementId' => $p->id,
                'studentId' => $p->student_id,
                'studentName' => $p->student->user->name ?? '',
                'studentNis' => $p->student->nis ?? '',
                'studentClassName' => $p->student->class_name ?? '',
                'dudiName' => $p->dudi->name ?? '',
                'assessments' => $p->assessments->map(function ($a) {
                    return $this->transformAssessment($a);
                }),
            ];
        });

        return response()->json($placements);
    }

    private function transformAssessment($a)
    {
        return [
            'id' => $a->id,
            'placementId' => $a->placement_id,
            'studentName' => $a->placement->student->user->name ?? '',
            'studentNis' => $a->placement->student->nis ?? '',
            'dudiName' => $a->placement->dudi->name ?? '',
            'monthPeriod' => $a->month_period,
            'technicalScore' => $a->technical_score,
            'disciplineScore' => $a->discipline_score,
            'socialScore' => $a->social_score,
            'managerialScore' => $a->managerial_score,
            'finalScore' => $a->final_score,
            'notes' => $a->notes,
            'isLocked' => $a->is_locked,
            'createdAt' => $a->created_at,
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'placementId' => 'required|string',
            'monthPeriod' => 'required|string',
        ]);

        // Check if assessment already exists
        $existing = Assessment::where('placement_id', $request->placementId)
            ->where('month_period', $request->monthPeriod)
            ->first();

        if ($existing) {
            // Update existing
            $existing->technical_score = $request->technicalScore ?? $existing->technical_score;
            $existing->discipline_score = $request->disciplineScore ?? $existing->discipline_score;
            $existing->social_score = $request->socialScore ?? $existing->social_score;
            $existing->managerial_score = $request->managerialScore ?? $existing->managerial_score;
            $existing->notes = $request->notes ?? $existing->notes;
            $existing->save();

            return response()->json($this->transformAssessment($existing->load('placement.student.user', 'placement.dudi')));
        }

        $assessment = new Assessment();
        $assessment->id = Str::uuid();
        $assessment->placement_id = $request->placementId;
        $assessment->month_period = $request->monthPeriod;
        $assessment->technical_score = $request->technicalScore ?? 0;
        $assessment->discipline_score = $request->disciplineScore ?? 0;
        $assessment->social_score = $request->socialScore ?? 0;
        $assessment->managerial_score = $request->managerialScore ?? 0;
        $assessment->notes = $request->notes;
        $assessment->is_locked = false;
        $assessment->created_at = now();
        $assessment->save();

        return response()->json($this->transformAssessment($assessment->load('placement.student.user', 'placement.dudi')), 201);
    }
}
