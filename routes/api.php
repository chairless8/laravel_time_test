<?php

use App\Http\Controllers\BatchController;
use Illuminate\Support\Facades\Route;

Route::post('/batches', [BatchController::class, 'store']);
Route::get('/batches', [BatchController::class, 'index']);
Route::get('/batches/{batch:uuid}', [BatchController::class, 'show']);
