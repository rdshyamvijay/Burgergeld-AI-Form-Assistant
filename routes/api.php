<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CitizenFormPdfController;

Route::post('/citizen-form-pdf', [CitizenFormPdfController::class, 'generate']);

Route::post('/ping', function () {
    return response()->json(['pong' => true]);
});