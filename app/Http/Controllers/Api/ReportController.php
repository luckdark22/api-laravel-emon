<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FinalReport;
use App\Models\Placement;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\FcmService;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = FinalReport::with(['placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments'])
            ->whereHas('placement.student', function ($q) {
                $q->whereNull('deleted_at');
            });

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

    public function upload(Request $request, FcmService $fcmService)
    {
        $request->validate([
            'file' => 'required|mimes:pdf|max:10240', // 10MB PDF
        ]);

        $user = $request->user();
        $placement = Placement::where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(['message' => 'Tidak ada penempatan aktif'], 400);
        }

        // Handle File Upload
        $file = $request->file('file');
        $fileName = 'report_' . $placement->id . '_' . time() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs('reports', $fileName, 'public');
        $fileUrl = asset('storage/' . $path);

        // Create or Update Report
        $report = FinalReport::where('placement_id', $placement->id)->first();
        if (!$report) {
            $report = new FinalReport();
            $report->id = Str::uuid();
            $report->placement_id = $placement->id;
            $report->title = 'Laporan Akhir PKL';
            $report->created_at = now();
        }

        $report->file_url = $fileUrl;
        $report->status = FinalReport::STATUS_SUBMITTED;
        $report->save();

        // Notify Teacher
        try {
            $teacherUser = $placement->teacher->user ?? null;
            if ($teacherUser && $teacherUser->fcm_token) {
                $fcmService->sendNotification(
                    $teacherUser->fcm_token,
                    "Laporan Akhir Baru",
                    "{$user->name} telah mengunggah Laporan Akhir."
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send report upload notification: ' . $e->getMessage());
        }

        return response()->json($this->transformReport($report->load('placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments')));
    }

    private function transformReport($r)
    {
        return [
            'id' => $r->id,
            'placementId' => $r->placement_id,
            'studentName' => $r->placement->student->user->name ?? '',
            'studentNis' => $r->placement->student->nis ?? '',
            'dudiName' => $r->placement->dudi->name ?? '',
            'dudiLogo' => $r->placement->dudi->logo ? (str_starts_with($r->placement->dudi->logo, 'http') ? $r->placement->dudi->logo : asset('storage/uploads/logos/' . $r->placement->dudi->logo)) : null,
            'teacherName' => $r->placement->teacher->user->name ?? '',
            'fileUrl' => $r->file_url,
            'title' => $r->title,
            'status' => $r->status,
            'teacherNotes' => $r->teacher_notes,
            'finalGrade' => $r->final_grade,
            'createdAt' => $r->created_at,
            'assessments' => isset($r->placement->assessments) ? $r->placement->assessments->map(function ($a) {
                return [
                    'id' => $a->id,
                    'monthPeriod' => $a->month_period,
                    'technicalScore' => $a->technical_score,
                    'disciplineScore' => $a->discipline_score,
                    'socialScore' => $a->social_score,
                    'managerialScore' => $a->managerial_score,
                    'finalScore' => $a->final_score,
                    'notes' => $a->notes,
                ];
            }) : [],
        ];
    }

    public function store(Request $request, FcmService $fcmService)
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

            // Notify Teacher (Update)
            try {
                $teacherUser = $placement->teacher->user ?? null;
                if ($teacherUser && $teacherUser->fcm_token) {
                    $fcmService->sendNotification(
                        $teacherUser->fcm_token,
                        "Laporan Akhir Diperbarui",
                        "{$user->name} telah memperbarui Laporan Akhir."
                    );
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send report update notification: ' . $e->getMessage());
            }

            return response()->json($this->transformReport($existing->load('placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments')));
        }

        $report = new FinalReport();
        $report->id = Str::uuid();
        $report->placement_id = $placement->id;
        $report->title = $request->title;
        $report->file_url = $request->fileUrl;
        $report->status = FinalReport::STATUS_SUBMITTED;
        $report->created_at = now();
        $report->save();

        // Notify Teacher
        try {
            $teacherUser = $placement->teacher->user ?? null;
            if ($teacherUser && $teacherUser->fcm_token) {
                $fcmService->sendNotification(
                    $teacherUser->fcm_token,
                    "Laporan Akhir Baru",
                    "{$user->name} telah membuat Laporan Akhir."
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send report store notification: ' . $e->getMessage());
        }

        return response()->json($this->transformReport($report->load('placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments')), 201);
    }

    public function destroy($id)
    {
        $report = FinalReport::findOrFail($id);

        // Delete file if exists
        if ($report->file_url) {
            $path = str_replace(asset('storage/'), '', $report->file_url);
            \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
        }

        $report->delete();

        return response()->json(['message' => 'Laporan berhasil dihapus']);
    }

    public function show($id)
    {
        $report = FinalReport::with(['placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments'])
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

        return response()->json($this->transformReport($report->load('placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments')));
    }

    public function review(Request $request, $id, FcmService $fcmService)
    {
        $request->validate([
            'status' => 'required|in:REVISION,APPROVED',
        ]);

        $report = FinalReport::with(['placement.student.user'])->findOrFail($id);

        $report->status = $request->status;
        $report->teacher_notes = $request->teacherNotes ?? $request->notes;
        if ($request->has('finalGrade')) {
            $report->final_grade = $request->finalGrade;
        }

        $report->save();

        // Notify Student
        try {
            $studentUser = $report->placement->student->user ?? null;
            if ($studentUser && $studentUser->fcm_token) {
                $statusLabel = $request->status === 'APPROVED' ? 'Disetujui' : 'Direvisi';
                $title = "Update Laporan Akhir";
                $body = "Laporan Akhir Anda telah {$statusLabel} oleh guru pembimbing.";
                if ($report->teacher_notes) {
                    $body .= "\nCatatan: {$report->teacher_notes}";
                }

                $fcmService->sendNotification(
                    $studentUser->fcm_token,
                    $title,
                    $body
                );
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send report review notification: ' . $e->getMessage());
        }

        return response()->json($this->transformReport($report->load('placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments')));
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $report = FinalReport::with(['placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments'])
            ->whereHas('placement', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            })
            ->latest('created_at')
            ->first();

        if (!$report) {
            return response()->json(null);
        }

        return response()->json($this->transformReport($report));
    }

    public function studentReports(Request $request, $id)
    {
        $report = FinalReport::with(['placement.student.user', 'placement.dudi', 'placement.teacher.user', 'placement.assessments'])
            ->whereHas('placement', function ($q) use ($id) {
                $q->where('student_id', $id);
            })
            ->latest('created_at')
            ->first();

        if (!$report) {
            return response()->json(null);
        }

        return response()->json($this->transformReport($report));
    }
}
