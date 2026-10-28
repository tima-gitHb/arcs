<?php

use App\Enums\Role;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route(auth()->user()->role->homeRoute())
        : redirect()->route('login');
});

// Kept because Breeze's auth controllers redirect here; sends each user to their role's home (SR-13).
Route::get('/dashboard', function () {
    return redirect()->route(auth()->user()->role->homeRoute());
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Reservation staff
Route::middleware(['auth', 'role:reservation'])->prefix('reservation')->name('reservation.')->group(function () {
    Route::view('/', 'reservation.home', ['role' => Role::Reservation])->name('home');
});

// Check-in staff
Route::middleware(['auth', 'role:checkin'])->prefix('checkin')->name('checkin.')->group(function () {
    Route::view('/', 'checkin.home', ['role' => Role::Checkin])->name('home');
});

// Gate staff
Route::middleware(['auth', 'role:gate'])->prefix('gate')->name('gate.')->group(function () {
    Route::view('/', 'gate.home', ['role' => Role::Gate])->name('home');
});

// Manager
Route::middleware(['auth', 'role:manager'])->prefix('manager')->name('manager.')->group(function () {
    Route::view('/', 'manager.home', ['role' => Role::Manager])->name('home');
});

require __DIR__.'/auth.php';
