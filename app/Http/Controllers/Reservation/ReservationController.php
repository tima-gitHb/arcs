<?php

namespace App\Http\Controllers\Reservation;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ReservationController extends Controller
{
    public function create(): View
    {
        return view('reservation.create');
    }

    public function search(): View
    {
        return view('reservation.search');
    }
}
