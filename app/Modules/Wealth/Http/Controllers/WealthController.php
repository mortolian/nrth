<?php

namespace App\Modules\Wealth\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WealthController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeTeam('wealth.view', $request);

        return Inertia::render('Wealth/Index');
    }
}
