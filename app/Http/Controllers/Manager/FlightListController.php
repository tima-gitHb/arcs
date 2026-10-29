<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class FlightListController extends Controller
{
    public function index(): View
    {
        return view('manager.flight-lists');
    }
}
