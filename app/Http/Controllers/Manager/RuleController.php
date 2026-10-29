<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class RuleController extends Controller
{
    public function index(): View
    {
        return view('manager.rules');
    }
}
