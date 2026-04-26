<?php

namespace App\Http\Controllers\Web;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Response;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Http\Controllers\Inertia\UserProfileController as JetstreamUserProfileController;
use Laravel\Jetstream\Jetstream;

class UserProfileController extends JetstreamUserProfileController
{
    /**
     * Show the general profile settings screen.
     *
     * @return Response
     */
    public function show(Request $request)
    {
        $this->validateTwoFactorAuthenticationState($request);

        /** @var User $user */
        $user = $request->user();

        return Jetstream::inertia()->render($request, 'Settings/Profile', [
            'confirmsTwoFactorAuthentication' => Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm'),
            'sessions' => $this->sessions($request)->all(),
            'preferences' => $user->mergedPreferences(),
        ]);
    }
}
