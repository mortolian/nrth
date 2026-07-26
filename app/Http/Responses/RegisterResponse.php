<?php

namespace App\Http\Responses;

use App\Support\AcceptTeamInvitations;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;

class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
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
