<?php

use App\Http\Controllers\Checkin\BagController;
use App\Http\Controllers\Checkin\BoardingPassController;
use App\Http\Controllers\Checkin\CabinUpgradeController;
use App\Http\Controllers\Checkin\CheckinController;
use App\Http\Controllers\Checkin\ExcessPaymentController;
use App\Http\Controllers\Checkin\GroupCheckInController;
use App\Http\Controllers\Checkin\SeatController;
use App\Http\Controllers\Checkin\SpecialServiceController;
use App\Http\Controllers\Checkin\TravelDocumentController;
use App\Http\Controllers\Gate\BoardingController;
use App\Http\Controllers\Gate\BoardingPassController as GateBoardingPassController;
use App\Http\Controllers\Gate\GateController;
use App\Http\Controllers\Gate\LateCheckInController;
use App\Http\Controllers\Gate\ManifestController;
use App\Http\Controllers\Gate\OffloadController;
use App\Http\Controllers\Manager\AccountController;
use App\Http\Controllers\Manager\AircraftController;
use App\Http\Controllers\Manager\AuditController;
use App\Http\Controllers\Manager\DashboardController;
use App\Http\Controllers\Manager\FareController;
use App\Http\Controllers\Manager\FlightController;
use App\Http\Controllers\Manager\FlightListController;
use App\Http\Controllers\Manager\RuleController;
use App\Http\Controllers\Manager\SalesController;
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
    Route::post('/passengers/{passenger}/seat', [SeatController::class, 'assign'])->name('seats.assign');
    Route::post('/passengers/{passenger}/seat/change', [SeatController::class, 'change'])->name('seats.change');
    Route::post('/passengers/{passenger}/bags', [BagController::class, 'store'])->name('bags.store');
    Route::delete('/bags/{bag}', [BagController::class, 'destroy'])->name('bags.destroy');
    Route::post('/passengers/{passenger}/excess-payment', [ExcessPaymentController::class, 'store'])->name('excess.pay');
    Route::get('/passengers/{passenger}/upgrade', [CabinUpgradeController::class, 'create'])->name('passengers.upgrade');
    Route::post('/passengers/{passenger}/upgrade', [CabinUpgradeController::class, 'store'])->name('passengers.upgrade.store');
    Route::post('/passengers/{passenger}/services', [SpecialServiceController::class, 'store'])->name('services.store');
    Route::delete('/services/{service}', [SpecialServiceController::class, 'destroy'])->name('services.destroy');
    Route::get('/passengers/{passenger}/boarding-pass', [BoardingPassController::class, 'show'])->name('boarding-pass.show');
    Route::post('/passengers/{passenger}/boarding-pass', [BoardingPassController::class, 'reprint'])->name('boarding-pass.reprint');

    // Group check-in for one PNR (C12 / SR-61).
    Route::get('/reservations/{reservation}/group', [GroupCheckInController::class, 'show'])->name('reservations.group');
    Route::post('/reservations/{reservation}/seats', [GroupCheckInController::class, 'assignSeats'])->name('reservations.seats');
    Route::post('/reservations/{reservation}/confirm-all', [GroupCheckInController::class, 'confirmAll'])->name('reservations.confirm-all');
    Route::get('/reservations/{reservation}/boarding-passes', [GroupCheckInController::class, 'boardingPasses'])->name('reservations.boarding-passes');
});

// Gate staff
Route::middleware(['auth', 'role:gate'])->prefix('gate')->name('gate.')->group(function () {
    Route::get('/', [GateController::class, 'home'])->name('home');
    Route::get('/flights/{flight}', [ManifestController::class, 'show'])->name('flights.show');
    Route::get('/flights/{flight}/counts', [ManifestController::class, 'counts'])->name('flights.counts');
    Route::post('/flights/{flight}/start-boarding', [BoardingController::class, 'start'])->name('flights.start-boarding');
    Route::post('/flights/{flight}/board', [BoardingController::class, 'board'])->name('flights.board');
    Route::post('/flights/{flight}/close', [BoardingController::class, 'close'])->name('flights.close');
    Route::post('/bags/{bag}/offload', [OffloadController::class, 'confirm'])->name('bags.offload');
    Route::get('/flights/{flight}/passengers/{passenger}/boarding-pass', [GateBoardingPassController::class, 'show'])->name('flights.boarding-pass');
    Route::get('/passengers/{passenger}/late-checkin', [LateCheckInController::class, 'create'])->name('passengers.late-checkin');
    // Throttled: this form checks a Manager's password, so guessing must stay slow (SR-29).
    Route::post('/passengers/{passenger}/late-checkin', [LateCheckInController::class, 'store'])
        ->middleware('throttle:5,1')->name('passengers.late-checkin.store');
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
    Route::get('/fares', [FareController::class, 'index'])->name('fares');
    Route::put('/fares', [FareController::class, 'update'])->name('fares.update');
    Route::get('/sales', [SalesController::class, 'index'])->name('sales');
    Route::get('/audit', [AuditController::class, 'index'])->name('audit');
});

require __DIR__.'/auth.php';
