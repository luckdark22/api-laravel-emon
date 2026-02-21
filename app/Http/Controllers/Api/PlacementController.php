<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Placement;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Models\Dudi;

class PlacementController extends Controller
{
    public function index(Request $request)
    {
        $query = Placement::with(['student.user', 'teacher.user', 'dudi', 'academicYear']);

        if ($request->academicYearId && $request->academicYearId !== 'ALL') {
            $query->where('academic_year_id', $request->academicYearId);
        } else {
            // Default to active year if not specified (Safety net)
            $activeYear = AcademicYear::where('is_active', true)->first();
            if ($activeYear) {
                $query->where('academic_year_id', $activeYear->id);
            }
        }

        if ($request->dudiId && $request->dudiId !== 'ALL') {
            $query->where('dudi_id', $request->dudiId);
        }

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        } else {
            // Default to ACTIVE to hide MOVED/DROPPED students from the main board
            $query->where('status', Placement::STATUS_ACTIVE);
        }

        // Get flat list first
        $placements = $query->orderBy('created_at', 'desc')->get();

        // Group by DUDI + Teacher (forming a "Placement Group")
        $grouped = $placements->groupBy(function ($item) {
            return $item->dudi_id . '_' . ($item->teacher_id ?? 'null');
        })->map(function ($items, $key) {
            $first = $items->first();
            $dudi = $first->dudi;

            return [
                'id' => $key, // Composite ID: dudiId_teacherId
                'companyId' => $first->dudi_id,
                'teacherId' => $first->teacher_id,
                'academicYearId' => $first->academic_year_id,
                'startDate' => $dudi->start_date?->format('Y-m-d'),
                'endDate' => $dudi->end_date?->format('Y-m-d'),
                'workStartTime' => $dudi->work_start_time,
                'workEndTime' => $dudi->work_end_time,
                'studentIds' => $items->filter(fn($p) => $p->student)->pluck('student_id')->unique()->values()->all(),
                'students' => $items->unique('student_id')->filter(fn($p) => $p->student)->map(function ($p) {
                    return [
                        'id' => $p->student_id,
                        'name' => $p->student->user->name ?? 'Unknown',
                        'class' => $p->student->class_name ?? '',
                    ];
                })->values(),
            ];
        })->values();

        return response()->json($grouped);
    }

    public function store(Request $request)
    {
        $request->validate([
            'studentId' => 'required|string',
            'dudiId' => 'required|string',
        ]);

        $activeYear = AcademicYear::where('is_active', true)->first();

        // UPDATE DUDI SCHEDULE if provided
        // Since Placement doesn't have its own schedule, we update the DUDI's schedule.
        if ($request->has('startDate') || $request->has('workStartTime')) {
            $dudi = \App\Models\Dudi::find($request->dudiId);
            if ($dudi) {
                if ($request->has('startDate'))
                    $dudi->start_date = $request->startDate;
                if ($request->has('endDate'))
                    $dudi->end_date = $request->endDate;
                if ($request->has('workStartTime'))
                    $dudi->work_start_time = $request->workStartTime;
                if ($request->has('workEndTime'))
                    $dudi->work_end_time = $request->workEndTime;
                $dudi->save();
            }
        }

        $placement = new Placement();
        $placement->id = Str::uuid();
        $placement->student_id = $request->studentId;
        $placement->teacher_id = $request->teacherId;
        $placement->dudi_id = $request->dudiId;
        $placement->academic_year_id = $request->academicYearId ?? $activeYear?->id;
        $placement->status = Placement::STATUS_ACTIVE;
        $placement->created_at = now();
        $placement->save();

        return response()->json([
            'id' => $placement->id,
            'studentId' => $placement->student_id,
            'dudiId' => $placement->dudi_id,
            'status' => $placement->status,
        ], 201);
    }

    public function show($id)
    {
        // ... (unchanged single show) ...
        $p = Placement::with(['student.user', 'teacher.user', 'dudi', 'academicYear'])
            ->findOrFail($id);

        return response()->json($p);
    }

    public function update(Request $request, $id)
    {
        $placement = Placement::findOrFail($id);

        if ($request->has('teacherId'))
            $placement->teacher_id = $request->teacherId;
        if ($request->has('dudiId'))
            $placement->dudi_id = $request->dudiId;
        if ($request->has('status'))
            $placement->status = $request->status;
        if ($request->has('academicYearId'))
            $placement->academic_year_id = $request->academicYearId;

        // UPDATE DUDI SCHEDULE (for Edit Group)
        $dudiIdToUpdate = $request->dudiId ?? $placement->dudi_id;
        if ($request->has('startDate') || $request->has('workStartTime')) {
            $dudi = \App\Models\Dudi::find($dudiIdToUpdate);
            if ($dudi) {
                if ($request->has('startDate'))
                    $dudi->start_date = $request->startDate;
                if ($request->has('endDate'))
                    $dudi->end_date = $request->endDate;
                if ($request->has('workStartTime'))
                    $dudi->work_start_time = $request->workStartTime;
                if ($request->has('workEndTime'))
                    $dudi->work_end_time = $request->workEndTime;
                $dudi->save();
            }
        }

        $placement->save();

        return response()->json([
            'id' => $placement->id,
            'status' => $placement->status,
        ]);
    }

    public function bulkUpdate(Request $request, $groupId)
    {
        // $groupId is expected to be "dudiId_teacherId" (or just dudiId if teacher is null)
        // BUT logic should rely more on the payload's target composition.

        $request->validate([
            'companyId' => 'required|string',
            'teacherId' => 'nullable|string',
            'studentIds' => 'present|array',
            'academicYearId' => 'required|string',
        ]);

        $newDudiId = $request->companyId;
        $newTeacherId = $request->teacherId;
        $newStudentIds = $request->studentIds;
        $academicYearId = $request->academicYearId;

        // 1. Identify valid placements currently active for this Group
        // We look for placements matching the DUDI (and optionally Teacher) for this academic year
        // We will scope it by the OLD group configuration if possible, OR we just trust the ID passed.
        // Actually, the frontend passes the 'id' of the group which is 'dudiId_teacherId'.

        // Let's rely on finding ALL active placements for the dudi+teacher combination 
        // OR better: find placements that WERE in this group.

        if (str_contains($groupId, '_')) {
            [$oldDudiId, $oldTeacherId] = explode('_', $groupId);
            if ($oldTeacherId === 'null')
                $oldTeacherId = null;
        } else {
            // Fallback
            $oldDudiId = $newDudiId;
            $oldTeacherId = $newTeacherId;
        }

        $activeYear = AcademicYear::find($academicYearId);
        if (!$activeYear) {
            return response()->json(['message' => 'Academic year not found'], 404);
        }

        // UPDATE DUDI SCHEDULE
        $dudi = \App\Models\Dudi::find($newDudiId);
        if ($dudi) {
            if ($request->has('startDate'))
                $dudi->start_date = $request->startDate;
            if ($request->has('endDate'))
                $dudi->end_date = $request->endDate;
            if ($request->has('workStartTime'))
                $dudi->work_start_time = $request->workStartTime;
            if ($request->has('workEndTime'))
                $dudi->work_end_time = $request->workEndTime;
            $dudi->save();
        }

        // Get existing active placements for this "Old Group"
        $query = Placement::where('dudi_id', $oldDudiId)
            ->where('academic_year_id', $academicYearId)
            ->where('status', Placement::STATUS_ACTIVE);

        if ($oldTeacherId) {
            $query->where('teacher_id', $oldTeacherId);
        } else {
            $query->whereNull('teacher_id');
        }

        $existingPlacements = $query->get();
        $existingStudentIds = $existingPlacements->pluck('student_id')->toArray();

        // Calculate differences
        $toKeepOrUpdateIDs = array_intersect($existingStudentIds, $newStudentIds);
        $toCreateIDs = array_diff($newStudentIds, $existingStudentIds);
        $toDropIDs = array_diff($existingStudentIds, $newStudentIds);

        DB::beginTransaction();
        try {
            // 1. UPDATE EXISTING (Keep them, but update Teacher if changed)
            if (!empty($toKeepOrUpdateIDs)) {
                \Log::info("UPDATING IDS: " . implode(',', $toKeepOrUpdateIDs) . " TO TEACHER: " . ($newTeacherId ?? 'NULL'));
                $updatedRows = Placement::whereIn('student_id', $toKeepOrUpdateIDs)
                    ->where('academic_year_id', $academicYearId)
                    ->where('status', Placement::STATUS_ACTIVE)
                    ->update([
                        'teacher_id' => $newTeacherId,
                        'dudi_id' => $newDudiId,
                    ]);
                \Log::info("UPDATED ROWS: $updatedRows");
            }

            // 2. CREATE NEW
            foreach ($toCreateIDs as $sId) {
                // Check if student already has an active placement elsewhere?
                $existingActive = Placement::where('student_id', $sId)
                    ->where('academic_year_id', $academicYearId)
                    ->where('status', Placement::STATUS_ACTIVE)
                    ->first();

                if ($existingActive) {
                    // Update the existing active placement to this new group instead of creating duplicate
                    $existingActive->dudi_id = $newDudiId;
                    $existingActive->teacher_id = $newTeacherId;
                    $existingActive->save();
                } else {
                    $p = new Placement();
                    $p->id = Str::uuid();
                    $p->student_id = $sId;
                    $p->teacher_id = $newTeacherId;
                    $p->dudi_id = $newDudiId;
                    $p->academic_year_id = $academicYearId;
                    $p->status = Placement::STATUS_ACTIVE;
                    $p->created_at = now();
                    $p->save();
                }
            }

            // 3. DROP REMOVED (Set status to DROPPED/MOVED instead of Delete)
            $rows = 0;
            if (!empty($toDropIDs)) {
                \Log::info('DROPPING COUNT: ' . count($toDropIDs));
                $rows = Placement::whereIn('student_id', $toDropIDs)
                    ->where('dudi_id', $oldDudiId) // Strict check
                    ->where('academic_year_id', $academicYearId)
                    ->update(['status' => Placement::STATUS_DROPPED]);
                \Log::info("DROPPED ROWS: $rows");
            }

            DB::commit();
            return response()->json(['message' => 'Placement group updated successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error updating placement: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        // Check if composite ID (dudiId_teacherId)
        if (str_contains($id, '_')) {
            [$dudiId, $teacherId] = explode('_', $id);

            $query = Placement::where('dudi_id', $dudiId);

            if ($teacherId === 'null') {
                $query->whereNull('teacher_id');
            } else {
                $query->where('teacher_id', $teacherId);
            }

            // SOFT DELETE (Change status to DROPPED) for "Destroy Group" action
            // Verify if this is what user wants for "Delete" button too?
            // User said "pastikan tidak ada tindakan yang dihapus".
            // Implementation: Update status to DROPPED
            $count = $query->update(['status' => Placement::STATUS_DROPPED]);

            return response()->json(['message' => "$count placements moved to DROPPED status"]);
        }

        // Single delete
        $placement = Placement::findOrFail($id);
        $placement->status = Placement::STATUS_DROPPED; // SOFT DELETE
        $placement->save();

        return response()->json(['message' => 'Placement status updated to DROPPED']);
    }
}
