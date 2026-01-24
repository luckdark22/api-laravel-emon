<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all();

        $mapped = $settings->mapWithKeys(function ($setting) {
            // Convert specific snake_case to camelCase for FE
            $key = $setting->key;
            if ($key === 'school_logo')
                $key = 'schoolLogo';
            if ($key === 'app_name')
                $key = 'appName';
            if ($key === 'institution_name')
                $key = 'institutionName';

            return [$key => $setting->value];
        });

        // Fallback for logo: if schoolLogo missing, check 'logo' (legacy)
        if (!isset($mapped['schoolLogo']) && isset($mapped['logo'])) {
            $mapped['schoolLogo'] = $mapped['logo'];
        }

        // Ensure logo is full URL if it exists
        if (isset($mapped['schoolLogo']) && !empty($mapped['schoolLogo'])) {
            $val = $mapped['schoolLogo'];
            if (!str_starts_with($val, 'http')) {
                // It's a relative path or filename
                $mapped['schoolLogo'] = asset('storage/' . ltrim($val, '/'));
            }
        }

        return response()->json($mapped);
    }

    public function update(Request $request)
    {
        foreach ($request->all() as $key => $value) {
            if (is_string($key)) {
                Setting::setValue($key, is_array($value) ? json_encode($value) : $value);
            }
        }

        return response()->json(['message' => 'Settings updated successfully']);
    }

    public function uploadLogo(Request $request)
    {
        $request->validate([
            'file' => 'required|image|max:2048', // Max 2MB
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $filename = time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('uploads/logos', $filename, 'public');

            // Generate URL (assuming storage:link is run or configured)
            $url = asset('storage/' . $path);

            // Save to settings
            Setting::setValue('school_logo', $url);

            return response()->json([
                'url' => $url,
                'path' => $path
            ]);
        }

        return response()->json(['message' => 'No file uploaded'], 400);
    }
}
