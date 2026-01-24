<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HolidayController extends Controller
{
    public function index()
    {
        $holidays = Holiday::orderBy('date', 'desc')->get()->map(function ($h) {
            return [
                'id' => $h->id,
                'date' => $h->date?->format('Y-m-d'),
                'description' => $h->description,
            ];
        });

        return response()->json($holidays);
    }

    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
        ]);

        $holiday = new Holiday();
        $holiday->id = Str::uuid();
        $holiday->date = $request->date;
        $holiday->description = $request->description;
        $holiday->save();

        return response()->json([
            'id' => $holiday->id,
            'date' => $holiday->date?->format('Y-m-d'),
            'description' => $holiday->description,
        ], 201);
    }

    public function show($id)
    {
        $holiday = Holiday::findOrFail($id);

        return response()->json([
            'id' => $holiday->id,
            'date' => $holiday->date?->format('Y-m-d'),
            'description' => $holiday->description,
        ]);
    }

    public function update(Request $request, $id)
    {
        $holiday = Holiday::findOrFail($id);

        if ($request->has('date'))
            $holiday->date = $request->date;
        if ($request->has('description'))
            $holiday->description = $request->description;

        $holiday->save();

        return response()->json([
            'id' => $holiday->id,
            'date' => $holiday->date?->format('Y-m-d'),
            'description' => $holiday->description,
        ]);
    }

    public function destroy($id)
    {
        $holiday = Holiday::findOrFail($id);
        $holiday->delete();

        return response()->json(['message' => 'Holiday deleted successfully']);
    }
}
