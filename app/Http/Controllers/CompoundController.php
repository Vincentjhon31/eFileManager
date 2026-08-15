<?php

namespace App\Http\Controllers;

use App\Support\Compound;
use Illuminate\View\View;

/**
 * The compound: every office of the municipality, drawn as a building.
 *
 * A plain controller and Blade rather than Livewire, because there is nothing
 * on this page for the server to do after it has been drawn. Arranging it is
 * the one exception, and that is a separate route with its own permission.
 *
 * **Open to anybody.** It used to be behind `auth`, when the buildings were the
 * signed-in employee's own screens and there was nothing to show somebody
 * without an account. Now they are the offices themselves — what each one does,
 * who heads it, what it has posted — which is a directory, and a directory that
 * turns strangers away is a locked noticeboard.
 *
 * Nothing behind a door has changed. Compound::places() offers links only into
 * the signed-in user's own office, and only the ones Navigation would already
 * have shown them; every destination still has its own middleware and its own
 * policy at the far side.
 */
class CompoundController extends Controller
{
    public function __invoke(): View
    {
        $payload = Compound::payload();

        return view('compound', [
            'compound' => $payload,

            /*
             * Only the count. There used to be a whole second copy of the
             * compound under it as a list of cards, which meant this screen was
             * two screens tall and the map — the entire point of it — was
             * something you scrolled away from. Searching replaced it: the
             * reason anybody read that list was to find one office in it.
             *
             * Derived from the payload rather than counted again, so the number
             * in the masthead and the buildings on the map cannot disagree.
             */
            'officeCount' => collect($payload['places'])->where('kind', 'office')->count(),
        ]);
    }
}
