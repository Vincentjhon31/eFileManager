<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Asking MIS for an account.
 *
 * The rule this system has always had is that nobody signs themselves in, and
 * that rule is intact. What arrives here is a **request**, not a registration:
 * the row it creates is inactive, has no role, and has a password nobody has
 * ever seen. EnsureUserIsActive and User::canSignIn() already refuse it, so
 * nothing new had to be written to keep the door shut — an account waiting for
 * MIS is refused by exactly the code that refuses a deactivated one.
 *
 * The office is asked for because it is the one thing MIS cannot look up: an
 * employee number and a name are on the plantilla, and which desk somebody
 * actually sits at is not. It is also what the compound needs in order to put a
 * marker over their building on the day they first sign in.
 *
 * The response never says whether an email is already known. A form that
 * answers "that address already has an account" on a government domain is a way
 * to find out who works there.
 */
class AccountRequestController extends Controller
{
    public function create(): View
    {
        return view('auth.request-account', [
            'offices' => Department::internal()->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'employee_no' => ['nullable', 'string', 'max:32'],
            'department_id' => ['required', Rule::exists('departments', 'id')->where('is_external', false)],
            'position' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ], attributes: ['department_id' => 'office']);

        // Taken already — by a real account, or by an earlier request from the
        // same person pressing the button twice. Either way the answer to the
        // person in front of the form is the same one everybody else gets.
        $taken = User::query()->where('email', $data['email'])->exists()
            || (filled($data['employee_no']) && User::query()->where('employee_no', $data['employee_no'])->exists());

        if ($taken) {
            $audit->log(
                event: 'account.request_duplicate',
                properties: ['email' => $data['email']],
                description: 'An account was requested for an address that already has one.',
            );

            return $this->done();
        }

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'employee_no' => $data['employee_no'] ?: null,
            'department_id' => $data['department_id'],
            'position' => $data['position'] ?: null,
            'phone' => $data['phone'] ?: null,

            // Long, random, and discarded. Nobody has this password, including
            // the person who asked for the account: MIS sets one when they
            // activate it. It exists because the column is not nullable and a
            // row with an empty hash is a row somebody could eventually guess.
            'password' => Str::password(40),

            // The whole point. EnsureUserIsActive refuses this account at every
            // door until somebody in MIS looks at it.
            'is_active' => false,
        ]);

        $audit->log(
            event: 'account.requested',
            subject: $user,
            properties: [
                'office' => $user->department?->code,
                'employee_no' => $user->employee_no,
            ],
            description: "{$user->name} asked for an account in {$user->department?->displayName()}.",
        );

        return $this->done();
    }

    /**
     * The same answer either way.
     *
     * Deliberately vague about what happened, and deliberately specific about
     * what happens next, which is the part the person actually needs.
     */
    private function done(): RedirectResponse
    {
        return redirect()
            ->route('public.enter')
            ->with('status', 'Thank you. MIS will check the request against the plantilla and '
                .'set the account up. You will be contacted at the address you gave.');
    }
}
