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
        }

        if ($request->dudiId && $request->dudiId !== 'ALL') {
            $query->where('dudi_id', $request->dudiId);
        }

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        $placements = $query->orderBy('created_at', 'desc')->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'studentId' => $p->student_id,
                'studentName' => $p->student->user->name ?? '',
                'studentNis' => $p->student->nis ?? '',
                'studentClassName' => $p->student->class_name ?? '',
                'studentMajor' => $p->student->major ?? '',
                'teacherId' => $p->teacher_id,
                'teacherName' => $p->teacher->user->name ?? '',
                'dudiId' => $p->dudi_id,
                'dudiName' => $p->dudi->name ?? '',
                'academicYearId' => $p->academic_year_id,
                'academicYearName' => $p->academicYear->name ?? '',
                'status' => $p->status,
                'createdAt' => $p->created_at,
            ];
        });

        return response()->json($placements);
    }

    public function store(Request $request)
    {
        $request->validate([
            'studentId' => 'required|string',
            'dudiId' => 'required|string',
        ]);

        $activeYear = AcademicYear::where('is_active', true)->first();

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
        $p = Placement::with(['student.user', 'teacher.user', 'dudi', 'academicYear'])
            ->findOrFail($id);

        return response()->json([
            'id' => $p->id,
            'studentId' => $p->student_id,
            'studentName' => $p->student->user->name ?? '',
            'studentNis' => $p->student->nis ?? '',
            'teacherId' => $p->teacher_id,
            'teacherName' => $p->teacher->user->name ?? '',
            'dudiId' => $p->dudi_id,
            'dudiName' => $p->dudi->name ?? '',
            'academicYearId' => $p->academic_year_id,
            'academicYearName' => $p->academicYear->name ?? '',
            'status' => $p->status,
            'createdAt' => $p->created_at,
        ]);
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

        $placement->save();

        return response()->json([
            'id' => $placement->id,
            'status' => $placement->status,
        ]);
    }

    public function destroy($id)
    {
        $placement = Placement::findOrFail($id);
        $placement->delete();

        return response()->json(['message' => 'Placement deleted successfully']);
    }
}
