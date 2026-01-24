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
            'academicYear'
        ]);

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        $mutations = $query->orderBy('created_at', 'desc')->get()->map(function ($m) {
            return [
                'id' => $m->id,
                'studentId' => $m->student_id,
                'studentName' => $m->student->user->name ?? '',
                'studentNis' => $m->student->nis ?? '',
                'oldDudiId' => $m->old_dudi_id,
                'oldDudiName' => $m->oldDudi->name ?? '',
                'newDudiId' => $m->new_dudi_id,
                'newDudiName' => $m->newDudi->name ?? '',
                'reason' => $m->reason,
                'status' => $m->status,
                'rejectionReason' => $m->rejection_reason,
                'requestedById' => $m->requested_by,
                'requestedByName' => $m->requestedBy->name ?? '',
                'processedById' => $m->processed_by,
                'processedByName' => $m->processedBy->name ?? '',
                'approvedAt' => $m->approved_at,
                'createdAt' => $m->created_at,
                'newTeacherId' => $m->new_teacher_id,
            ];
        });

        return response()->json($mutations);
    }

    public function store(Request $request)
    {
        $request->validate([
            'studentId' => 'required|string',
            'newDudiId' => 'required|string',
            'reason' => 'required|string',
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

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:APPROVED,REJECTED',
        ]);

        $user = $request->user();
        $mutation = PlacementMutation::findOrFail($id);

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
                $newPlacement->teacher_id = $mutation->new_teacher_id;
                $newPlacement->dudi_id = $mutation->new_dudi_id;
                $newPlacement->academic_year_id = $activeYear?->id ?? $mutation->academic_year_id;
                $newPlacement->status = Placement::STATUS_ACTIVE;
                $newPlacement->created_at = now();
                $newPlacement->save();
            } else {
                $mutation->rejection_reason = $request->rejectionReason;
            }

            $mutation->save();

            return response()->json([
                'id' => $mutation->id,
                'status' => $mutation->status,
            ]);
        });
    }
}
