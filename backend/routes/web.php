<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'data' => [
            'message' => 'ReviewEngine API. See /api/v1/health.',
        ],
    ]);
});
