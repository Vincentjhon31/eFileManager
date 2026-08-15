<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The door of the Municipal Hall.
 *
 * Clicking the hall in the drawn town used to go straight to the sign-in form,
 * which was right when there was nothing behind it but a sign-in form. There
 * now is: the compound is an office directory anybody may read, so the hall has
 * three doors rather than one, and this is where somebody chooses.
 *
 *   Visitor        — walk the compound, read the directory, open nothing
 *   Sign in        — the form, unchanged
 *   Request        — ask MIS for an account, naming the office you work in
 *
 * Signed in already, the same door offers where to go instead of who to be.
 *
 * Nothing here authenticates anything. "Visitor" sets a flag that decides what
 * the town says to somebody on their way back, and that is the whole of its
 * power — the compound is open to everybody with or without it.
 */
class GateController extends Controller
{
    public function show(): View
    {
        return view('public.enter');
    }

    /**
     * Choosing to look around.
     *
     * A session flag and nothing else: no user, no cookie of consequence, no
     * record that anybody was here. A member of the public reading a directory
     * of offices is not an event this system has any business logging.
     */
    public function visitor(Request $request): RedirectResponse
    {
        $request->session()->put('visitor', true);

        return redirect()->route('compound');
    }
}
