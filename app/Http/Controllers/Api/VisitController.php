<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VisitController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Visit::with(['teacher.user', 'dudi']);

        // For teacher - only their visits
        if ($user->role === User::ROLE_TEACHER) {
            $query->where('teacher_id', $user->id);
        }

        if ($request->dudiId && $request->dudiId !== 'ALL') {
            $query->where('dudi_id', $request->dudiId);
        }

        $visits = $query->orderBy('visit_date', 'desc')->get()->map(function ($v) {
            return [
                'id' => $v->id,
                'teacherId' => $v->teacher_id,
                'teacherName' => $v->teacher->user->name ?? '',
                'dudiId' => $v->dudi_id,
                'dudiName' => $v->dudi->name ?? '',
                'visitDate' => $v->visit_date,
                'photoEvidenceUrl' => $v->photo_evidence_url,
                'notes' => $v->notes,
                'latitude' => $v->latitude,
                'longitude' => $v->longitude,
                'locationAddress' => $v->location_address,
                'createdAt' => $v->created_at,
            ];
        });

        return response()->json($visits);
    }

    public function store(Request $request)
    {
        $request->validate([
            'dudiId' => 'required|string',
            'visitDate' => 'required',
            'photoEvidenceUrl' => 'required|string',
        ]);

        $user = $request->user();

        $visit = new Visit();
        $visit->id = Str::uuid();
        $visit->teacher_id = $user->id;
        $visit->dudi_id = $request->dudiId;
        $visit->visit_date = $request->visitDate;
        $visit->photo_evidence_url = $request->photoEvidenceUrl;
        $visit->notes = $request->notes;
        $visit->latitude = $request->latitude;
        $visit->longitude = $request->longitude;
        $visit->location_address = $request->locationAddress;
        $visit->created_at = now();
        $visit->save();

        return response()->json([
            'id' => $visit->id,
            'visitDate' => $visit->visit_date,
        ], 201);
    }

    public function show($id)
    {
        $visit = Visit::with(['teacher.user', 'dudi'])->findOrFail($id);

        return response()->json([
            'id' => $visit->id,
            'teacherId' => $visit->teacher_id,
            'teacherName' => $visit->teacher->user->name ?? '',
            'dudiId' => $visit->dudi_id,
            'dudiName' => $visit->dudi->name ?? '',
            'visitDate' => $visit->visit_date,
            'photoEvidenceUrl' => $visit->photo_evidence_url,
            'notes' => $visit->notes,
            'latitude' => $visit->latitude,
            'longitude' => $visit->longitude,
            'locationAddress' => $visit->location_address,
        ]);
    }

    public function update(Request $request, $id)
    {
        $visit = Visit::findOrFail($id);

        if ($request->has('visitDate'))
            $visit->visit_date = $request->visitDate;
        if ($request->has('photoEvidenceUrl'))
            $visit->photo_evidence_url = $request->photoEvidenceUrl;
        if ($request->has('notes'))
            $visit->notes = $request->notes;
        if ($request->has('latitude'))
            $visit->latitude = $request->latitude;
        if ($request->has('longitude'))
            $visit->longitude = $request->longitude;
        if ($request->has('locationAddress'))
            $visit->location_address = $request->locationAddress;

        $visit->save();

        return response()->json([
            'id' => $visit->id,
            'visitDate' => $visit->visit_date,
        ]);
    }

    public function destroy($id)
    {
        $visit = Visit::findOrFail($id);
        $visit->delete();

        return response()->json(['message' => 'Visit deleted successfully']);
    }
}
