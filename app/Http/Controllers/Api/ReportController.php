<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinalReport;
use App\Models\Placement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = FinalReport::with(['placement.student.user', 'placement.dudi', 'placement.teacher.user']);

        // Role-based filtering
        if ($user->role === User::ROLE_STUDENT) {
            $query->whereHas('placement', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        } elseif ($user->role === User::ROLE_TEACHER) {
            $query->whereHas('placement', function ($q) use ($user) {
                $q->where('teacher_id', $user->id);
            });
        }

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        $reports = $query->orderBy('created_at', 'desc')->get()->map(function ($r) {
            return $this->transformReport($r);
        });

        return response()->json($reports);
    }

    private function transformReport($r)
    {
        return [
            'id' => $r->id,
            'placementId' => $r->placement_id,
            'studentName' => $r->placement->student->user->name ?? '',
            'studentNis' => $r->placement->student->nis ?? '',
            'dudiName' => $r->placement->dudi->name ?? '',
            'teacherName' => $r->placement->teacher->user->name ?? '',
            'fileUrl' => $r->file_url,
            'title' => $r->title,
            'status' => $r->status,
            'teacherNotes' => $r->teacher_notes,
            'finalGrade' => $r->final_grade,
            'createdAt' => $r->created_at,
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string',
        ]);

        $user = $request->user();

        $placement = Placement::where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(['message' => 'Tidak ada penempatan aktif'], 400);
        }

        // Check if report already exists
        $existing = FinalReport::where('placement_id', $placement->id)->first();
        if ($existing) {
            $existing->title = $request->title;
            $existing->file_url = $request->fileUrl ?? $existing->file_url;
            $existing->status = FinalReport::STATUS_SUBMITTED;
            $existing->save();

            return response()->json($this->transformReport($existing->load('placement.student.user', 'placement.dudi', 'placement.teacher.user')));
        }

        $report = new FinalReport();
        $report->id = Str::uuid();
        $report->placement_id = $placement->id;
        $report->title = $request->title;
        $report->file_url = $request->fileUrl;
        $report->status = FinalReport::STATUS_SUBMITTED;
        $report->created_at = now();
        $report->save();

        return response()->json($this->transformReport($report->load('placement.student.user', 'placement.dudi', 'placement.teacher.user')), 201);
    }

    public function show($id)
    {
        $report = FinalReport::with(['placement.student.user', 'placement.dudi', 'placement.teacher.user'])
            ->findOrFail($id);

        return response()->json($this->transformReport($report));
    }

    public function update(Request $request, $id)
    {
        $report = FinalReport::findOrFail($id);

        if ($request->has('title'))
            $report->title = $request->title;
        if ($request->has('fileUrl'))
            $report->file_url = $request->fileUrl;
        if ($request->has('status'))
            $report->status = $request->status;

        $report->save();

        return response()->json($this->transformReport($report->load('placement.student.user', 'placement.dudi', 'placement.teacher.user')));
    }

    public function review(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:REVISION,APPROVED',
        ]);

        $report = FinalReport::findOrFail($id);

        $report->status = $request->status;
        $report->teacher_notes = $request->teacherNotes ?? $request->notes;
        if ($request->has('finalGrade')) {
            $report->final_grade = $request->finalGrade;
        }

        $report->save();

        return response()->json($this->transformReport($report->load('placement.student.user', 'placement.dudi', 'placement.teacher.user')));
    }

    public function studentReports(Request $request, $id)
    {
        $reports = FinalReport::with(['placement.student.user', 'placement.dudi', 'placement.teacher.user'])
            ->whereHas('placement', function ($q) use ($id) {
                $q->where('student_id', $id);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($r) {
                return $this->transformReport($r);
            });

        return response()->json($reports);
    }
}
