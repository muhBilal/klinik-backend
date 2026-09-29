<?php

use Illuminate\Support\Facades\Route;

// Backend hanya menyajikan REST API; antarmuka ada di project frontend terpisah.
Route::get('/', fn () => response()->json([
    'app' => config('app.name'),
    'api' => url('/api'),
    'frontend' => env('FRONTEND_URL'),
]));
