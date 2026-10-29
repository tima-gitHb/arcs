<?php

namespace App\Http\Controllers\Gate;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class GateController extends Controller
{
    public function home(): View
    {
        return view('gate.home');
    }
}
