<?php

namespace App\Http\Controllers;

use App\Support\Compound;
use Illuminate\View\View;

/**
 * The compound: every screen this employee can open, drawn as a building.
 *
 * A plain controller and Blade rather than Livewire, because there is nothing on
 * this page for the server to do after it has been drawn. It has no state, no
 * form and no action — every building is a link, and following one leaves.
 *
 * No permission middleware, deliberately. The list is
 * Navigation::forCurrentUser(), which is already filtered to what this account
 * may open, so the page is exactly as authorised as the sidebar it mirrors:
 * being signed in is the whole requirement, and each door is still guarded at
 * the far side of it.
 */
class CompoundController extends Controller
{
    public function __invoke(): View
    {
        $payload = Compound::payload();

        return view('compound', [
            'compound' => $payload,

            /*
             * The same destinations as a flat list for the strip below the
             * drawing. Derived from the payload rather than asked for again, so
             * the list and the buildings can never disagree about what is there.
             */
            'links' => collect($payload['places'])
                ->where('kind', 'link')
                ->map(fn (array $place) => [
                    'name' => $place['name'],
                    'blurb' => $place['blurb'],
                    'url' => $place['url'],
                ])
                ->values()
                ->all(),
        ]);
    }
}
