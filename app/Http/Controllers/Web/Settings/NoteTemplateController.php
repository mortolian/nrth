<?php

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Invoicing\Models\NoteTemplate;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NoteTemplateController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeTeam('settings.business', $request);
        $teamId = (int) $request->user()->current_team_id;

        $templates = NoteTemplate::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (NoteTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'body' => $t->body,
                'target' => $t->target,
                'is_active' => (bool) $t->is_active,
                'sort_order' => (int) $t->sort_order,
            ]);

        return Inertia::render('Settings/NoteTemplates/Index', [
            'templates' => $templates,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTeam('settings.business', $request);
        $teamId = (int) $request->user()->current_team_id;
        $payload = $this->validateTemplate($request);

        NoteTemplate::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            ...$payload,
        ]);

        return back()->with('success', __('Note template created.'));
    }

    public function update(Request $request, NoteTemplate $noteTemplate): RedirectResponse
    {
        $this->authorizeTeam('settings.business', $request);
        abort_unless($noteTemplate->team_id === $request->user()->current_team_id, 403);
        $noteTemplate->update($this->validateTemplate($request));

        return back()->with('success', __('Note template updated.'));
    }

    public function destroy(Request $request, NoteTemplate $noteTemplate): RedirectResponse
    {
        $this->authorizeTeam('settings.business', $request);
        abort_unless($noteTemplate->team_id === $request->user()->current_team_id, 403);
        $noteTemplate->delete();

        return back()->with('success', __('Note template deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        // Note templates are notes-only; footers stay freeform per document.
        $validated['target'] = 'notes';

        return $validated;
    }
}
