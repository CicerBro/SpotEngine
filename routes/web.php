<?php

declare(strict_types=1);

use App\Http\Controllers\AdminController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

// Requires login
Route::middleware('auth')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::post('/spots/mark-read', [HomeController::class, 'markAllSpotsRead'])->name('spots.mark-read');
    Route::get('/spots/{spot}', [HomeController::class, 'show'])->name('spots.show');
    Route::get('/spots/{spot}/image', [HomeController::class, 'downloadImage'])->name('spots.image');
    Route::get('/categories.json', [HomeController::class, 'categoriesJson'])->name('categories.json');
    Route::get('/spots/{spot}/nzb', [HomeController::class, 'downloadNzb'])->name('spots.nzb');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile');
    Route::post('/profile/api-key/regenerate', [ProfileController::class, 'regenerateApiKey'])->name('profile.api-key.regenerate');
});

// Admin
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/users', [AdminController::class, 'createUser'])->name('users.create');
    Route::delete('/users/{user}', [AdminController::class, 'deleteUser'])->name('users.delete');
    Route::post('/clean', [AdminController::class, 'cleanOldSpots'])->name('clean');
});
