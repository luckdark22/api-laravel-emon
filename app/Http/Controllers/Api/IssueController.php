<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Placement;
use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IssueController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Issue::with([
            'placement.student.user',
            'placement.dudi',
            'reporter',
            'resolver',
            'academicYear'
        ]);

        // For student - only their issues
        if ($user->role === User::ROLE_STUDENT) {
            $query->whereHas('placement', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        }

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        return response()->json($this->transformIssues($query->orderBy('created_at', 'desc')->get()));
    }

    public function adminIndex(Request $request)
    {
        $query = Issue::with([
            'placement.student.user',
            'placement.dudi',
            'placement.teacher.user',
            'reporter',
            'resolver',
            'academicYear'
        ]);

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        if ($request->academicYearId && $request->academicYearId !== 'ALL') {
            $query->where('academic_year_id', $request->academicYearId);
        }

        return response()->json($this->transformIssues($query->orderBy('created_at', 'desc')->get()));
    }

    public function teacherIndex(Request $request)
    {
        $user = $request->user();
        $query = Issue::with([
            'placement.student.user',
            'placement.dudi',
            'reporter',
            'resolver',
            'academicYear'
        ])->whereHas('placement', function ($q) use ($user) {
            $q->where('teacher_id', $user->id);
        });

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        return response()->json($this->transformIssues($query->orderBy('created_at', 'desc')->get()));
    }

    private function transformIssues($issues)
    {
        return $issues->map(function ($i) {
            return [
                'id' => $i->id,
                'placementId' => $i->placement_id,
                'studentName' => $i->placement->student->user->name ?? '',
                'studentNis' => $i->placement->student->nis ?? '',
                'dudiName' => $i->placement->dudi->name ?? '',
                'teacherName' => $i->placement->teacher->user->name ?? '',
                'category' => $i->category,
                'severity' => $i->severity,
                'description' => $i->description,
                'status' => $i->status,
                'resolutionNotes' => $i->resolution_notes,
                'resolvedAt' => $i->resolved_at,
                'reporterId' => $i->reporter_id,
                'reporterName' => $i->reporter->name ?? '',
                'resolverId' => $i->resolver_id,
                'resolverName' => $i->resolver->name ?? '',
                'academicYearId' => $i->academic_year_id,
                'academicYearName' => $i->academicYear->name ?? '',
                'createdAt' => $i->created_at,
            ];
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            'placementId' => 'required|string',
            'description' => 'required|string',
        ]);

        $user = $request->user();
        $activeYear = AcademicYear::where('is_active', true)->first();

        $issue = new Issue();
        $issue->id = Str::uuid();
        $issue->placement_id = $request->placementId;
        $issue->reporter_id = $user->id;
        $issue->academic_year_id = $activeYear?->id;
        $issue->category = $request->category;
        $issue->severity = $request->severity;
        $issue->description = $request->description;
        $issue->status = Issue::STATUS_OPEN;
        $issue->created_at = now();
        $issue->save();

        return response()->json([
            'id' => $issue->id,
            'status' => $issue->status,
        ], 201);
    }

    public function resolve(Request $request, $id)
    {
        $request->validate([
            'resolutionNotes' => 'required|string',
        ]);

        $user = $request->user();
        $issue = Issue::findOrFail($id);

        $issue->status = Issue::STATUS_RESOLVED;
        $issue->resolution_notes = $request->resolutionNotes;
        $issue->resolver_id = $user->id;
        $issue->resolved_at = now();
        $issue->save();

        return response()->json([
            'id' => $issue->id,
            'status' => $issue->status,
        ]);
    }
}
