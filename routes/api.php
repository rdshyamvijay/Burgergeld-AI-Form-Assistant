<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CitizenFormPdfController;
use App\Http\Controllers\AiController;

Route::post('/citizen-form-pdf', [CitizenFormPdfController::class, 'generate']);

Route::post('/ping', function () {
    return response()->json(['pong' => true]);
});

Route::get('/ai/health', [AiController::class,'health']);
Route::post('/ai/annexes',   [AiController::class, 'annexes']);
Route::post('/form/save',    [AiController::class, 'saveDomain']);
Route::get('/wep/questions', [AiController::class, 'wepQuestions']);
Route::post('/ai/help',      [AiController::class, 'fieldHelp']);
Route::post('/ai/chat',      [AiController::class, 'chat']);
Route::get('/ha-schema', fn() => response()->json(config('ha')));
Route::get('/wep-schema', [AiController::class, 'getWepSchema']);
Route::post('/wep/save', [AiController::class, 'saveWep']);
Route::get('/domain', [AiController::class, 'getDomain']);
