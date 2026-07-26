<?php

namespace App\Http\Responses;

use App\Support\AcceptTeamInvitations;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class LoginResponse implements LoginResponseContract
{
    /**
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function toResponse($request)
    {
        $user = $request->user();
        if ($user !== null) {
            $joined = AcceptTeamInvitations::settleMembership($user);
            if ($joined !== null) {
                $request->session()->forget('invitation_join');
                // Prefer the business dashboard over a stale signed join URL.
                $request->session()->forget('url.intended');

                return redirect()->to(Fortify::redirects('login'))->with(
                    'success',
                    __('Welcome to :team.', ['team' => $joined->name]),
                );
            }
        }

        return $request->wantsJson()
            ? response()->json(['two_factor' => false])
            : redirect()->intended(Fortify::redirects('login'));
    }
}
