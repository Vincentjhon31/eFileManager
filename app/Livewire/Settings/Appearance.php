<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * How the system looks — light or dark, tight or roomy, small type or large.
 *
 * None of these touch a Blade template. Saving writes three attributes onto
 * <html> on the next render, and the stylesheet reads them: dark mode
 * redefines the colour variables Tailwind's utilities already point at, density
 * moves the single spacing unit every padding is derived from, and text size
 * moves the root font size the whole type scale is measured in.
 *
 * That is why this screen could be added without editing thirty views, and why
 * a view added tomorrow will follow it without being told to.
 */
class Appearance extends Component
{
    public string $theme = '';

    public string $density = '';

    public string $text_size = '';

    public function mount(): void
    {
        $preferences = $this->user()->preferences();

        $this->theme = $preferences->theme();
        $this->density = $preferences->density();
        $this->text_size = $preferences->textSize();
    }

    public function rules(): array
    {
        return [
            'theme' => ['required', Rule::in(array_keys(UserPreferences::THEMES))],
            'density' => ['required', Rule::in(array_keys(UserPreferences::DENSITIES))],
            'text_size' => ['required', Rule::in(array_keys(UserPreferences::TEXT_SIZES))],
        ];
    }

    /**
     * Show the choice the moment it is made.
     *
     * These three settings live as attributes on <html>, and a Livewire action
     * re-renders only the component that handled it — never the layout the
     * <html> element belongs to. Without saying so out loud, picking "Dark"
     * and pressing Save would change the database and nothing on screen until
     * the next full page load, which reads as the setting being broken.
     *
     * So the server announces the choice and the page applies it. The listener
     * is in the layout's head script, next to the resolver that already owns
     * this element.
     */
    public function updated(string $property, mixed $value): void
    {
        if (in_array($property, ['theme', 'density', 'text_size'], true)) {
            $this->announce();
        }
    }

    public function save(): void
    {
        $data = $this->validate();
        $user = $this->user();

        // Merged over what is stored: the preferences and notifications screens
        // write into the same bag and must not be reset by a save here.
        $user->update([
            'preferences' => UserPreferences::clean(array_merge($user->preferences ?? [], $data)),
        ]);

        // Again on save, so the page is right even for somebody who submitted
        // without the live preview having run — an unchanged form, or a browser
        // that posted before the last change was acknowledged.
        $this->announce();

        session()->flash('status', 'Appearance saved.');
    }

    /**
     * Tell the page what to wear.
     *
     * Passed through UserPreferences first so only a known value can ever
     * reach a DOM attribute — the same checking the database write gets, for
     * the same reason.
     */
    private function announce(): void
    {
        $chosen = UserPreferences::fromArray([
            'theme' => $this->theme,
            'density' => $this->density,
            'text_size' => $this->text_size,
        ]);

        $this->dispatch(
            'appearance-changed',
            theme: $chosen->theme(),
            density: $chosen->density(),
            text: $chosen->textSize(),
        );
    }

    private function user(): User
    {
        return Auth::user();
    }

    public function render()
    {
        return view('livewire.settings.appearance', [
            'themes' => UserPreferences::THEMES,
            'densities' => UserPreferences::DENSITIES,
            'textSizes' => UserPreferences::TEXT_SIZES,
        ])->layout('components.layouts.app', ['title' => 'Appearance']);
    }
}
