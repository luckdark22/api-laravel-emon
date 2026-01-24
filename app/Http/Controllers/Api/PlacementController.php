<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Placement;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

            $count = $query->delete();
            return response()->json(['message' => "$count placements deleted successfully"]);
        }

        $placement = Placement::findOrFail($id);
        $placement->delete();

        return response()->json(['message' => 'Placement deleted successfully']);
    }
}
