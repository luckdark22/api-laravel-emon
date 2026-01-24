<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AcademicYearController extends Controller
{
    public function index()
    {
        // Removed orderBy created_at to avoid potential missing column error
        $years = AcademicYear::all()->map(function ($year) {
            return [
                'id' => $year->id,
                'name' => $year->name,
                'isActive' => $year->is_active,
                'startDate' => $year->start_date,
                'endDate' => $year->end_date,
            ];
        });
        return response()->json($years);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'startDate' => 'nullable|date',
            'endDate' => 'nullable|date',
        ]);

        $year = new AcademicYear();
        $year->id = Str::uuid();
        $year->name = $request->name;
        $year->start_date = $request->startDate;
        $year->end_date = $request->endDate;
        $year->is_active = false;
        $year->save();

        return response()->json([
            'id' => $year->id,
            'name' => $year->name,
            'isActive' => $year->is_active,
            'startDate' => $year->start_date,
            'endDate' => $year->end_date,
        ], 201);
    }

    public function show($id)
    {
        $year = AcademicYear::findOrFail($id);
        return response()->json([
            'id' => $year->id,
            'name' => $year->name,
            'isActive' => $year->is_active,
            'startDate' => $year->start_date,
            'endDate' => $year->end_date,
        ]);
    }

    public function update(Request $request, $id)
    {
        $year = AcademicYear::findOrFail($id);

        if ($request->has('name'))
            $year->name = $request->name;
        if ($request->has('startDate'))
            $year->start_date = $request->startDate;
        if ($request->has('endDate'))
            $year->end_date = $request->endDate;

        $year->save();

        return response()->json([
            'id' => $year->id,
            'name' => $year->name,
            'isActive' => $year->is_active,
            'startDate' => $year->start_date,
            'endDate' => $year->end_date,
        ]);
    }

    public function destroy($id)
    {
        $year = AcademicYear::findOrFail($id);
        $year->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }

    public function active()
    {
        $year = AcademicYear::where('is_active', true)->first();
        if (!$year)
            return response()->json(null);

        return response()->json([
            'id' => $year->id,
            'name' => $year->name,
            'isActive' => $year->is_active,
            'startDate' => $year->start_date,
            'endDate' => $year->end_date,
        ]);
    }

    public function activate($id)
    {
        // Deactivate all
        AcademicYear::where('is_active', true)->update(['is_active' => false]);

        // Activate selected
        $year = AcademicYear::findOrFail($id);
        $year->is_active = true;
        $year->save();

        return response()->json([
            'id' => $year->id,
            'name' => $year->name,
            'isActive' => $year->is_active,
            'startDate' => $year->start_date,
            'endDate' => $year->end_date,
        ]);
    }
}
