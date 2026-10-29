<?php

namespace App\Http\Controllers\Checkin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class CheckinController extends Controller
{
    public function home(): View
    {
        return view('checkin.home');
    }
}
