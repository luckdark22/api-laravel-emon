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
                // Return nested objects to satisfy issueApi.ts logic (issue.placement.student...)
                'placement' => [
                    'student' => [
                        'user' => [
                            'id' => $i->placement->student->user->id ?? null,
                            'name' => $i->placement->student->user->name ?? 'Unknown',
                        ],
                        'nis' => $i->placement->student->nis ?? '',
                    ],
                    'dudi' => [
                        'name' => $i->placement->dudi->name ?? 'Unknown',
                    ],
                    'teacher' => [
                        'user' => [
                            'name' => $i->placement->teacher->user->name ?? '',
                        ]
                    ]
                ],
                'studentName' => $i->placement->student->user->name ?? '', // Fallback flat
                'dudiName' => $i->placement->dudi->name ?? '',
                'category' => $i->category,
                'severity' => $i->severity,
                'description' => $i->description,
                'status' => $i->status,
                'resolutionNotes' => $i->resolution_notes,
                'resolvedAt' => $i->resolved_at,
                'reporter' => [
                    'name' => $i->reporter->name ?? '',
                ],
                'resolver' => [
                    'name' => $i->resolver->name ?? '',
                ],
                'createdAt' => $i->created_at, // Used as 'date'
            ];
        });
    }

    public function store(Request $request)
    {
        $request->validate([
            // FE sends studentId, NOT placementId
            'studentId' => 'required|string',
            'description' => 'required|string',
            'category' => 'required|string', // FE sends category
            'severity' => 'required|string', // FE sends severity
            'date' => 'nullable|date',      // FE sends date
        ]);

        $user = $request->user();
        $activeYear = AcademicYear::where('is_active', true)->first();

        // Find Active Placement for Student
        $placement = Placement::where('student_id', $request->studentId)
            ->where('status', 'ACTIVE') // Only active placement
            // Optional: Filter by academic year? Usually active is unique enough.
            ->first();

        if (!$placement) {
            return response()->json(['message' => 'Student does not have an active placement.'], 422);
        }

        $issue = new Issue();
        $issue->id = Str::uuid();
        $issue->placement_id = $placement->id;
        $issue->reporter_id = $user->id;
        $issue->academic_year_id = $activeYear?->id;
        $issue->category = $request->category;
        $issue->severity = $request->severity;
        $issue->description = $request->description;
        $issue->status = Issue::STATUS_OPEN;
        // Use provided date or now
        $issue->created_at = $request->date ? \Carbon\Carbon::parse($request->date) : now();
        $issue->save();

        return response()->json([
            'id' => $issue->id,
            'status' => $issue->status,
        ], 201);
    }

    public function resolve(Request $request, $id)
    {
        $request->validate([
            // Accept resolution (FE) or resolutionNotes
            'resolution' => 'nullable|string',
            'resolutionNotes' => 'nullable|string',
        ]);

        $notes = $request->resolution ?? $request->resolutionNotes;
        if (!$notes) {
            return response()->json(['message' => 'Resolution notes are required.'], 422);
        }

        $user = $request->user();
        $issue = Issue::findOrFail($id);

        $issue->status = Issue::STATUS_RESOLVED;
        $issue->resolution_notes = $notes;
        $issue->resolver_id = $user->id;
        $issue->resolved_at = now();
        $issue->save();

        return response()->json([
            'id' => $issue->id,
            'status' => $issue->status,
        ]);
    }
}
