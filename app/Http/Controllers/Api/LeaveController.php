<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use App\Models\Placement;
use App\Models\User;
use App\Models\Mentor;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeaveController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Leave::with(['placement.student.user', 'placement.dudi']);

        // Role-based filtering
        if ($user->role === User::ROLE_STUDENT) {
            $query->whereHas('placement', function ($q) use ($user) {
                $q->where('student_id', $user->id);
            });
        } elseif ($user->role === User::ROLE_MENTOR) {
            $mentor = Mentor::where('user_id', $user->id)->first();
            if ($mentor && $mentor->dudi_id) {
                $query->whereHas('placement', function ($q) use ($mentor) {
                    $q->where('dudi_id', $mentor->dudi_id);
                });
            }
        } elseif ($user->role === User::ROLE_TEACHER) {
            $query->whereHas('placement', function ($q) use ($user) {
                $q->where('teacher_id', $user->id);
            });
        }

        if ($request->status && $request->status !== 'ALL') {
            $query->where('status', $request->status);
        }

        $leaves = $query->orderBy('created_at', 'desc')->get()->map(function ($l) {
            return [
                'id' => $l->id,
                'placementId' => $l->placement_id,
                'studentName' => $l->placement->student->user->name ?? '',
                'studentNis' => $l->placement->student->nis ?? '',
                'dudiName' => $l->placement->dudi->name ?? '',
                'type' => $l->type,
                'startDate' => $l->start_date?->format('Y-m-d'),
                'endDate' => $l->end_date?->format('Y-m-d'),
                'reason' => $l->reason,
                'attachmentUrl' => $l->attachment_url,
                'status' => $l->status,
                'rejectionReason' => $l->rejection_reason,
                'createdAt' => $l->created_at,
            ];
        });

        return response()->json($leaves);
    }

    public function store(Request $request)
    {
        $request->validate([
            'type' => 'required|in:SICK,PERMIT,OTHER',
            'startDate' => 'required|date',
            'endDate' => 'required|date',
            'reason' => 'required|string',
            'attachment' => 'nullable|file|image|max:2048', // 2MB Max
        ]);

        $user = $request->user();

        $placement = Placement::where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(['message' => 'Tidak ada penempatan aktif'], 400);
        }

        $leave = new Leave();
        $leave->id = Str::uuid();
        $leave->placement_id = $placement->id;
        $leave->type = $request->type;
        $leave->start_date = $request->startDate;
        $leave->end_date = $request->endDate;
        $leave->reason = $request->reason;
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store('leaves', 'public');
            // Assuming default storage links to /storage
            $leave->attachment_url = asset('storage/' . $path);
        }
        $leave->status = Leave::STATUS_PENDING;
        $leave->save();

        // Notification Logic
        try {
            // 1. Notify Teacher
            $teacherUser = $placement->teacher?->user;
            if ($teacherUser && $teacherUser->fcm_token) {
                $fcmService = app(\App\Services\FcmService::class);
                $fcmService->sendNotification(
                    $teacherUser->fcm_token,
                    'Pengajuan Izin Baru',
                    "Siswa {$user->name} mengajukan izin: {$request->type}"
                );
            }

            // 2. Notify Mentors of the DUDI
            if ($placement->dudi_id) {
                $mentors = Mentor::where('dudi_id', $placement->dudi_id)->with('user')->get();
                $fcmService = $fcmService ?? app(\App\Services\FcmService::class);

                foreach ($mentors as $mentor) {
                    if ($mentor->user && $mentor->user->fcm_token) {
                        $fcmService->sendNotification(
                            $mentor->user->fcm_token,
                            'Pengajuan Izin Baru',
                            "Siswa {$user->name} mengajukan izin: {$request->type}"
                        );
                    }
                }
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to send leave notification: " . $e->getMessage());
        }

        return response()->json([
            'id' => $leave->id,
            'type' => $leave->type,
            'status' => $leave->status,
        ], 201);
    }

    public function show($id)
    {
        $leave = Leave::with(['placement.student.user', 'placement.dudi'])->findOrFail($id);

        return response()->json([
            'id' => $leave->id,
            'placementId' => $leave->placement_id,
            'studentName' => $leave->placement->student->user->name ?? '',
            'type' => $leave->type,
            'startDate' => $leave->start_date?->format('Y-m-d'),
            'endDate' => $leave->end_date?->format('Y-m-d'),
            'reason' => $leave->reason,
            'attachmentUrl' => $leave->attachment_url,
            'status' => $leave->status,
            'rejectionReason' => $leave->rejection_reason,
        ]);
    }

    public function update(Request $request, $id)
    {
        $leave = Leave::findOrFail($id);

        if ($request->has('type'))
            $leave->type = $request->type;
        if ($request->has('startDate'))
            $leave->start_date = $request->startDate;
        if ($request->has('endDate'))
            $leave->end_date = $request->endDate;
        if ($request->has('reason'))
            $leave->reason = $request->reason;
        if ($request->has('attachmentUrl'))
            $leave->attachment_url = $request->attachmentUrl;

        $leave->save();

        return response()->json([
            'id' => $leave->id,
            'status' => $leave->status,
        ]);
    }

    public function destroy($id)
    {
        $leave = Leave::findOrFail($id);
        $leave->delete();

        return response()->json(['message' => 'Leave deleted successfully']);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:APPROVED,REJECTED',
        ]);

        $leave = Leave::findOrFail($id);

        $leave->status = $request->status;
        if ($request->status === 'REJECTED') {
            $leave->rejection_reason = $request->rejectionReason;
        }
        $leave->save();

        return response()->json([
            'id' => $leave->id,
            'status' => $leave->status,
        ]);
    }
}
