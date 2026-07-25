<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTeamAccess;

abstract class Controller
{
    use AuthorizesTeamAccess;
}
