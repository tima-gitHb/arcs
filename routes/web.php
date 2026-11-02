<?php

use App\Http\Controllers\Checkin\CheckinController;
use App\Http\Controllers\Checkin\TravelDocumentController;
use App\Http\Controllers\Gate\GateController;
use App\Http\Controllers\Manager\AccountController;
use App\Http\Controllers\Manager\AircraftController;
use App\Http\Controllers\Manager\AuditController;
use App\Http\Controllers\Manager\DashboardController;
use App\Http\Controllers\Manager\FlightController;
use App\Http\Controllers\Manager\FlightListController;
use App\Http\Controllers\Manager\RuleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Reservation\ReservationController;
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
    Route::get('/', fn () => redirect()->route('reservation.create'))->name('home');
    Route::get('/create', [ReservationController::class, 'create'])->name('create');
    Route::get('/flights/{flight}/seats', [ReservationController::class, 'seats'])->name('seats');
    Route::post('/', [ReservationController::class, 'store'])->name('store');
    Route::get('/search', [ReservationController::class, 'search'])->name('search');
    Route::get('/confirmation/{reservation}', [ReservationController::class, 'confirmation'])->name('confirmation');
    Route::get('/passengers/{passenger}/edit', [ReservationController::class, 'edit'])->name('edit');
    Route::put('/passengers/{passenger}', [ReservationController::class, 'update'])->name('update');
    Route::post('/passengers/{passenger}/cancel', [ReservationController::class, 'cancel'])->name('cancel');
});

// Check-in staff
Route::middleware(['auth', 'role:checkin'])->prefix('checkin')->name('checkin.')->group(function () {
    Route::get('/', [CheckinController::class, 'home'])->name('home');
    Route::get('/passengers/{passenger}', [CheckinController::class, 'show'])->name('passengers.show');
    Route::post('/passengers/{passenger}/confirm', [CheckinController::class, 'confirm'])->name('passengers.confirm');
    Route::post('/passengers/{passenger}/documents', [TravelDocumentController::class, 'store'])->name('documents.store');
    Route::put('/documents/{document}', [TravelDocumentController::class, 'update'])->name('documents.update');
    Route::post('/documents/{document}/verify', [TravelDocumentController::class, 'verify'])->name('documents.verify');
});

// Gate staff
Route::middleware(['auth', 'role:gate'])->prefix('gate')->name('gate.')->group(function () {
    Route::get('/', [GateController::class, 'home'])->name('home');
});

// Manager
Route::middleware(['auth', 'role:manager'])->prefix('manager')->name('manager.')->group(function () {
    Route::get('/', [FlightListController::class, 'index'])->name('home');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/flights', [FlightController::class, 'index'])->name('flights');
    Route::post('/flights', [FlightController::class, 'store'])->name('flights.store');
    Route::patch('/flights/{flight}/status', [FlightController::class, 'updateStatus'])->name('flights.status');
    Route::patch('/flights/{flight}/gate', [FlightController::class, 'updateGate'])->name('flights.gate');
    Route::post('/aircraft', [AircraftController::class, 'store'])->name('aircraft.store');
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounts/{user}/active', [AccountController::class, 'setActive'])->name('accounts.active');
    Route::patch('/accounts/{user}/unlock', [AccountController::class, 'unlock'])->name('accounts.unlock');
    Route::post('/accounts/{user}/reset-link', [AccountController::class, 'sendResetLink'])->name('accounts.reset-link');
    Route::get('/rules', [RuleController::class, 'index'])->name('rules');
    Route::put('/rules', [RuleController::class, 'update'])->name('rules.update');
    Route::get('/audit', [AuditController::class, 'index'])->name('audit');
});

require __DIR__.'/auth.php';
