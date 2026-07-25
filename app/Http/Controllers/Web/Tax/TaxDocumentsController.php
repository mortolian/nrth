<?php

namespace App\Http\Controllers\Web\Tax;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaxDocumentsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $this->authorizeTeam('tax.manage', $request);

        return redirect()->route('backups-exports.index', array_filter([
            'section' => 'takeout',
            'preset' => $request->string('preset')->toString() ?: null,
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ]));
    }
}
