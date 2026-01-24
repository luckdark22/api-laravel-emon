<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
        ]);

        $file = $request->file('file');
        $folder = $request->input('folder', 'uploads');

        $filename = Str::uuid() . '.' . $file->getClientOriginalExtension();
        $path = $file->storeAs("public/{$folder}", $filename);

        $url = Storage::url($path);

        return response()->json([
            'url' => $url,
            'filename' => $filename,
            'originalName' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]);
    }
}
