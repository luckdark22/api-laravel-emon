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

        if ($request->academicYearName && $request->academicYearName !== 'ALL') {
            // Optional: Filter by year if needed, usually via visit_date range
            // For now loosely unimplemented or filter by year of visit_date
        }

        $visits = $query->orderBy('visit_date', 'desc')->get()->map(function ($v) {
            // Count active students for this DUDI + Teacher
            // Note: This is a rough estimate based on current active state, 
            // ideally snapshots would be better but this suffices for simple monitoring.
            $studentCount = \App\Models\Placement::where('dudi_id', $v->dudi_id)
                ->where('teacher_id', $v->teacher_id)
                ->where('status', 'ACTIVE') // Or include FINISHED if historical? 
                ->count();

            return [
                'id' => $v->id,
                'teacherId' => $v->teacher_id,
                'teacherName' => $v->teacher->user->name ?? '',
                'dudi' => [
                    'id' => $v->dudi_id,
                    'name' => $v->dudi->name ?? '',
                    'address' => $v->dudi->address ?? '',
                ],
                'visitDate' => $v->visit_date,
                'photoEvidenceUrl' => $v->photo_evidence_url,
                'notes' => $v->notes,
                'latitude' => $v->latitude,
                'longitude' => $v->longitude,
                'locationAddress' => $v->location_address,
                'studentsCount' => $studentCount,
                'createdAt' => $v->created_at,
            ];
        });

        return response()->json($visits);
    }

    public function locations(Request $request)
    {
        // Repurposed: Returns List of "Visitable Locations" (DUDIs) for the current teacher
        // matches Frontend: VisitLocation[] { id, name, address, students[] }

        $user = $request->user();

        // Find DUDIs where this teacher has active students
        $placements = \App\Models\Placement::with(['dudi', 'student.user'])
            ->where('teacher_id', $user->id)
            ->where('status', 'ACTIVE')
            ->get();

        // Group by DUDI
        $grouped = $placements->groupBy('dudi_id')->map(function ($group) {
            $dudi = $group->first()->dudi;
            return [
                'id' => $dudi->id,
                'name' => $dudi->name,
                'address' => $dudi->address ?? '',
                'students' => $group->map(function ($p) {
                    return $p->student->user->name ?? 'Unknown';
                })->values()->toArray(),
            ];
        })->values();

        return response()->json($grouped);
    }

    public function store(Request $request)
    {
        $request->validate([
            'dudiId' => 'required|string',
            'visitDate' => 'nullable|date',
            // Allow string (base64 or url)
            'photoEvidenceUrl' => 'nullable|string',
            // Fallback param name if FE sends 'photo'
            'photo' => 'nullable|string',
        ]);

        $user = $request->user();

        // Handle Image Upload (Base64 or URL)
        $photoUrl = $request->photoEvidenceUrl ?? $request->photo;

        if ($photoUrl && str_starts_with($photoUrl, 'data:image')) {
            // It's a Base64 string, need to save as file
            // Extract extension
            $pos = strpos($photoUrl, ';');
            $type = explode(':', substr($photoUrl, 0, $pos))[1];
            $ext = explode('/', $type)[1]; // jpeg, png, etc.

            // Decode
            $image = str_replace('data:image/' . $ext . ';base64,', '', $photoUrl);
            $image = str_replace(' ', '+', $image);
            $imageName = 'visit_' . time() . '_' . Str::random(10) . '.' . $ext;

            // Save to public storage
            \Illuminate\Support\Facades\Storage::disk('public')->put('uploads/visits/' . $imageName, base64_decode($image));

            // Generate accessible URL
            $photoUrl = asset('storage/uploads/visits/' . $imageName);
        }

        $visit = new Visit();
        $visit->id = Str::uuid();
        $visit->teacher_id = $user->id;
        $visit->dudi_id = $request->dudiId;
        $visit->visit_date = $request->visitDate ? \Carbon\Carbon::parse($request->visitDate) : now();
        $visit->photo_evidence_url = $photoUrl;
        $visit->notes = $request->notes;

        // Handle Coordinates (FE sends lat/lng, DB needs latitude/longitude)
        $visit->latitude = $request->latitude ?? $request->lat;
        $visit->longitude = $request->longitude ?? $request->lng;

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
