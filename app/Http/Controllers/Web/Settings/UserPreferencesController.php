<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserPreferencesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'notify_invoice_overdue' => ['required', 'boolean'],
            'notify_vat_due' => ['required', 'boolean'],
            'notify_provisional_tax' => ['required', 'boolean'],
            'date_format' => ['required', 'string', Rule::in(['Y-m-d', 'd/m/Y', 'd M Y'])],
            'theme' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
        ]);

        $user->preferences = array_merge($user->mergedPreferences(), $validated);
        $user->save();

        return back(303)->with('success', 'Preferences saved.');
    }
}
