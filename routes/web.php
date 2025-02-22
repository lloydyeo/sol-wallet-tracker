<?php

use App\Http\Controllers\SolanaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/snapshot', [SolanaController::class, 'snapshotTokenHolding'])->name('snapshot');
