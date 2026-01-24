<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->mapWithKeys(function ($setting) {
            return [$setting->key => $setting->value];
        });

        return response()->json($settings);
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
}
