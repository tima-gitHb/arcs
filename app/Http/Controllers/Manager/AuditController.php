<?php

namespace App\Http\Controllers\Manager;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(): View
    {
        return view('manager.audit');
    }
}
