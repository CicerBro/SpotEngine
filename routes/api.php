<?php

declare(strict_types=1);

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

// Newznab-compatible API — authentication handled per-action via the 'api' guard
Route::get('/', [ApiController::class, 'handle'])->name('api');
