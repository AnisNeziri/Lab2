<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UserPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'preferences' => UserPreferences::normalize($user->preferences),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['sometimes', 'in:light,dark'],
            'language' => ['sometimes', 'in:en,sq'],
            'enable_3d_map' => ['sometimes', 'boolean'],
        ]);

        $user = Auth::user();
        $preferences = UserPreferences::normalize($user->preferences);
        $preferences = array_merge($preferences, $validated);
        $user->preferences = $preferences;
        $user->save();

        return response()->json([
            'preferences' => $preferences,
        ]);
    }
}
