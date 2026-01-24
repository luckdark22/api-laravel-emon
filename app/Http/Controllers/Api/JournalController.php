<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Journal;
use App\Models\Placement;
use App\Models\Mentor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class JournalController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Journal::with(['placement.student.user', 'placement.dudi', 'checkedByMentor.user']);

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

        $journals = $query->orderBy('date', 'desc')->get()->map(function ($j) {
            return [
                'id' => $j->id,
                'placementId' => $j->placement_id,
                'studentName' => $j->placement->student->user->name ?? '',
                'studentNis' => $j->placement->student->nis ?? '',
                'dudiName' => $j->placement->dudi->name ?? '',
                'date' => $j->date?->format('Y-m-d'),
                'activity' => $j->activity,
                'description' => $j->description,
                'attachmentUrl' => $j->attachment_url,
                'status' => $j->status,
                'mentorFeedback' => $j->mentor_feedback,
                'checkedBy' => $j->checked_by,
                'checkedByName' => $j->checkedByMentor->user->name ?? null,
                'createdAt' => $j->created_at,
            ];
        });

        return response()->json($journals);
    }

    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'activity' => 'required|string',
            'description' => 'required|string',
        ]);

        $user = $request->user();

        $placement = Placement::where('student_id', $user->id)
            ->where('status', 'ACTIVE')
            ->latest('created_at')
            ->first();

        if (!$placement) {
            return response()->json(['message' => 'Tidak ada penempatan aktif'], 400);
        }

        $journal = new Journal();
        $journal->id = Str::uuid();
        $journal->placement_id = $placement->id;
        $journal->date = $request->date;
        $journal->activity = $request->activity;
        $journal->description = $request->description;
        $journal->attachment_url = $request->attachmentUrl;
        $journal->status = Journal::STATUS_PENDING;
        $journal->created_at = now();
        $journal->save();

        return response()->json([
            'id' => $journal->id,
            'date' => $journal->date?->format('Y-m-d'),
            'activity' => $journal->activity,
            'status' => $journal->status,
        ], 201);
    }

    public function show($id)
    {
        $journal = Journal::with(['placement.student.user', 'placement.dudi'])->findOrFail($id);

        return response()->json([
            'id' => $journal->id,
            'placementId' => $journal->placement_id,
            'studentName' => $journal->placement->student->user->name ?? '',
            'date' => $journal->date?->format('Y-m-d'),
            'activity' => $journal->activity,
            'description' => $journal->description,
            'attachmentUrl' => $journal->attachment_url,
            'status' => $journal->status,
            'mentorFeedback' => $journal->mentor_feedback,
        ]);
    }

    public function update(Request $request, $id)
    {
        $journal = Journal::findOrFail($id);

        if ($request->has('activity'))
            $journal->activity = $request->activity;
        if ($request->has('description'))
            $journal->description = $request->description;
        if ($request->has('attachmentUrl'))
            $journal->attachment_url = $request->attachmentUrl;

        $journal->save();

        return response()->json([
            'id' => $journal->id,
            'activity' => $journal->activity,
            'status' => $journal->status,
        ]);
    }

    public function destroy($id)
    {
        $journal = Journal::findOrFail($id);
        $journal->delete();

        return response()->json(['message' => 'Journal deleted successfully']);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:APPROVED,REJECTED',
        ]);

        $user = $request->user();
        $journal = Journal::findOrFail($id);

        $journal->status = $request->status;
        $journal->checked_by = $user->id;
        $journal->mentor_feedback = $request->feedback ?? $request->mentorFeedback;
        $journal->save();

        return response()->json([
            'id' => $journal->id,
            'status' => $journal->status,
        ]);
    }
}
