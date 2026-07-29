<?php

namespace App\Http\Responses;

use App\Support\AcceptTeamInvitations;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $user = $request->user();
        if ($user !== null) {
            AcceptTeamInvitations::settleMembership($user);
            $request->session()->forget('invitation_join');
        }

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended(Fortify::redirects('register'));
    }
}
