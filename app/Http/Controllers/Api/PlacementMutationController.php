<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlacementMutation;
use App\Models\Placement;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PlacementMutationController extends Controller
{
    public function index(Request $request)
    {
        $query = PlacementMutation::with([
            'student.user',
            'oldDudi',
            'newDudi',
            'requestedBy',
            'processedBy',
        ]);

        if ($request->academicYearId && $request->academicYearId !== 'ALL') {
            $query->where('academic_year_id', $request->academicYearId);
        }

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        $limit = $request->limit ?? 10;

        // Use paginate and transform items
        $mutations = $query->orderBy('created_at', 'desc')->paginate($limit);

        // Transform the collection to match adminService expectations
        $mutations->getCollection()->transform(function ($m) {
            return $this->transformMutation($m);
        });

        return response()->json([
            'data' => $mutations->items(),
            'meta' => [
                'current_page' => $mutations->currentPage(),
                'last_page' => $mutations->lastPage(),
                'per_page' => $mutations->perPage(),
                'total' => $mutations->total(),
                // Add camelCase alias for FE convenience if needed, though adminService might need snake_case from Laravel default? 
                // adminService uses "meta" but we don't see what properties it accesses in the snippet.
                // Assuming standard Laravel meta is fine or FE adapts. 
                // But mock service returned "lastPage" (camel).
                'lastPage' => $mutations->lastPage(),
            ]
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'studentId' => 'required|string',
            'newDudiId' => 'required|string',
            'reason' => 'required|string',
            'newTeacherId' => 'nullable|string',
        ]);

        $user = $request->user();
        $activeYear = AcademicYear::where('is_active', true)->first();

        // Get current placement
        $currentPlacement = Placement::where('student_id', $request->studentId)
            ->where('status', 'ACTIVE')
            ->first();

        $mutation = new PlacementMutation();
        $mutation->id = Str::uuid();
        $mutation->student_id = $request->studentId;
        $mutation->old_dudi_id = $currentPlacement?->dudi_id;
        $mutation->new_dudi_id = $request->newDudiId;
        $mutation->reason = $request->reason;
        $mutation->status = PlacementMutation::STATUS_PENDING;
        $mutation->requested_by = $user->id;
        $mutation->academic_year_id = $activeYear?->id;
        $mutation->new_teacher_id = $request->newTeacherId;
        $mutation->created_at = now();
        $mutation->save();

        return response()->json([
            'id' => $mutation->id,
            'status' => $mutation->status,
        ], 201);
    }

    public function teacherStore(Request $request)
    {
        // Same as store but for teacher-initiated mutations
        return $this->store($request);
    }

    public function teacherIndex(Request $request)
    {
        $user = $request->user();

        // Resolve academic year
        $academicYearId = $request->academicYearId ?? $request->academicYear;
        if (!$academicYearId) {
            $activeYear = AcademicYear::where('is_active', true)->first();
            $academicYearId = $activeYear?->id;
        }

        $query = PlacementMutation::with([
            'student.user',
            'oldDudi',
            'newDudi',
            'requestedBy',
            'processedBy',
        ]);

        // Filter by:
        // 1. Requested by this teacher
        // 2. OR Student currently supervised by this teacher (via active placement)
        $query->where(function ($q) use ($user) {
            $q->where('requested_by', $user->id)
                ->orWhereHas('student.placements', function ($pq) use ($user) {
                    $pq->where('teacher_id', $user->id)
                        ->where('status', 'ACTIVE');
                });
        });

        if ($academicYearId && $academicYearId !== 'ALL') {
            $query->where('academic_year_id', $academicYearId);
        }

        // Ensure student is not soft-deleted
        $query->whereHas('student', function ($q) {
            $q->whereNull('deleted_at');
        });

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        $limit = $request->limit ?? 10;
        $mutations = $query->orderBy('created_at', 'desc')->paginate($limit);

        // Transform (Flatten for FE)
        $mutations->getCollection()->transform(function ($m) {
            return $this->transformMutation($m);
        });

        return response()->json([
            'data' => $mutations->items(),
            'meta' => [
                'current_page' => $mutations->currentPage(),
                'last_page' => $mutations->lastPage(),
                'per_page' => $mutations->perPage(),
                'total' => $mutations->total(),
                'lastPage' => $mutations->lastPage(), // CamelCase alias
                'page' => $mutations->currentPage(), // Legacy alias
            ]
        ]);
    }

    private function transformMutation($m)
    {
        return [
            'id' => $m->id,
            'studentId' => $m->student_id,
            'studentName' => $m->student->user->name ?? '',
            'currentDudiId' => $m->old_dudi_id,
            'currentDudiName' => $m->oldDudi->name ?? 'None',
            'targetDudiId' => $m->new_dudi_id,
            'targetDudiName' => $m->newDudi->name ?? '',
            'reason' => $m->reason,
            'status' => $m->status,
            'rejectionReason' => $m->rejection_reason,
            'requestedBy' => $m->requestedBy?->name ?? 'Admin',
            'requestedByRole' => $m->requestedBy?->role ?? 'ADMIN',
            'processedBy' => $m->processedBy?->name ?? null,
            'approvedAt' => $m->approved_at,
            'requestDate' => $m->created_at->toDateTimeString(),
            'createdAt' => $m->created_at->toDateTimeString(),
            'newTeacherId' => $m->new_teacher_id,
        ];
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:APPROVED,REJECTED',
            'rejectionReason' => 'nullable|string|required_if:status,REJECTED',
        ]);

        $user = $request->user();
        $mutation = PlacementMutation::with(['student.user', 'oldDudi', 'newDudi', 'requestedBy'])->findOrFail($id);

        return DB::transaction(function () use ($request, $mutation, $user) {
            $mutation->status = $request->status;
            $mutation->processed_by = $user->id;

            if ($request->status === 'APPROVED') {
                $mutation->approved_at = now();

                // Update old placement
                Placement::where('student_id', $mutation->student_id)
                    ->where('status', 'ACTIVE')
                    ->update(['status' => 'MOVED']);

                // Create new placement
                $activeYear = AcademicYear::where('is_active', true)->first();
                $newPlacement = new Placement();
                $newPlacement->id = Str::uuid();
                $newPlacement->student_id = $mutation->student_id;
                $newPlacement->teacher_id = $mutation->new_teacher_id ?? $mutation->requested_by;
                $newPlacement->dudi_id = $mutation->new_dudi_id;
                $newPlacement->academic_year_id = $activeYear?->id ?? $mutation->academic_year_id;
                $newPlacement->status = Placement::STATUS_ACTIVE;
                $newPlacement->created_at = now();
                $newPlacement->save();
            } else {
                $mutation->rejection_reason = $request->rejectionReason;
            }

            $mutation->save();

            return response()->json($this->transformMutation($mutation));
        });
    }
}
