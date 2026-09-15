<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Temporary debug endpoint - only for local environment
if (app()->environment('local')) {
    Route::get('/debug-auth', function () {
        return response()->json([
            'default_guard' => config('auth.defaults.guard'),
            'default_user' => auth()->user()?->only(['id', 'email', 'tenant_id']),
            'default_check' => auth()->check(),
            'web_user' => auth('web')->user()?->only(['id', 'email', 'tenant_id']),
            'web_check' => auth('web')->check(),
            'api_user' => auth('api')->user()?->only(['id', 'email', 'tenant_id']),
            'api_check' => auth('api')->check(),
            'session_id' => session()->getId(),
            'session_keys' => array_keys(session()->all()),
            'filament_auth_check' => Filament\auth()->check(),
            'filament_auth_user' => Filament\auth()->user()?->only(['id', 'email', 'tenant_id']),
        ]);
    });
}
