<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AiController;
use App\Http\Controllers\CitizenFormPdfController;

Route::get('/', fn() => redirect('/apply'));
Route::get('/apply', fn() => view('apply'));
Route::get('/annexes', fn() => view('annexes'));
Route::get('/wep', fn() => view('wep'));

Route::get('/merged', [CitizenFormPdfController::class, 'generate']);
