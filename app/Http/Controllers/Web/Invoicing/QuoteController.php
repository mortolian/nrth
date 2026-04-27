<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class QuoteController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Invoicing/Quotes/Index');
    }
}

