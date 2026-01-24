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
        $years = AcademicYear::orderBy('created_at', 'desc')->get();
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

        return response()->json($year, 201);
    }

    public function show($id)
    {
        $year = AcademicYear::findOrFail($id);
        return response()->json($year);
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

        return response()->json($year);
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
        return response()->json($year);
    }

    public function activate($id)
    {
        // Deactivate all
        AcademicYear::where('is_active', true)->update(['is_active' => false]);

        // Activate selected
        $year = AcademicYear::findOrFail($id);
        $year->is_active = true;
        $year->save();

        return response()->json($year);
    }
}
