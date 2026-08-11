<?php

namespace App\Http\Controllers\Web\Settings;

use App\Http\Controllers\Controller;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class FeaturesSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorizeTeam('settings.business', $request);

        $team = $request->user()?->currentTeam;
        abort_unless($team !== null, 403);

        $modules = array_map(function (array $item) use ($team): array {
            return [
                ...$item,
                'enabled' => $team->moduleEnabled($item['name']),
            ];
        }, ModuleCatalog::forUi());

        return Inertia::render('Settings/Features', [
            'modules' => $modules,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeTeam('settings.business', $request);

        $team = $request->user()?->currentTeam;
        abort_unless($team !== null, 403);

        $validated = $request->validate([
            'modules' => ['required', 'array'],
            'modules.*.name' => ['required', 'string', Rule::in(ModuleCatalog::keys())],
            'modules.*.enabled' => ['required', 'boolean'],
        ]);

        foreach ($validated['modules'] as $row) {
            $team->setModuleEnabled($row['name'], (bool) $row['enabled']);
        }

        return back()->with('success', __('Optional features updated.'));
    }
}
