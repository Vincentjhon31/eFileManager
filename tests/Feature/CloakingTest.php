<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * x-cloak must stop applying once Alpine has booted.
 *
 * This looks like a styling detail and is not. Livewire's morph patches an
 * element's attributes from the freshly parsed server HTML, skipping only
 * elements it can see are currently *shown*. Anything hidden by x-show is
 * patched — and the server HTML still carries x-cloak, so the attribute lands
 * back on it. Alpine strips x-cloak once, on its first walk, and never again.
 *
 * With the usual unscoped `[x-cloak] { display: none !important }`, the first
 * morph therefore kills every overlay that happened to be closed at that
 * moment: the drive's file preview, its context menu and selection bar, and
 * every dropdown menu in the application. They cannot be shown again — the
 * !important beats the inline style x-show sets — until a full page reload.
 *
 * That was a real bug, reported as "I open a folder and then no file will
 * open, only a hard refresh fixes it". These two assertions are what stop it
 * coming back the next time somebody tidies the stylesheet.
 */
class CloakingTest extends TestCase
{
    public function test_the_cloak_rule_is_scoped_to_before_alpine_boots(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString(
            'html:not([data-alpine-ready]) [x-cloak]',
            $css,
            'x-cloak must only hide things before Alpine has booted.',
        );

        // An unscoped rule anywhere would reinstate the bug, whatever else is
        // in the file. It is matched at the start of a line, where a top-level
        // rule would sit.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\[x-cloak\]\s*\{/m',
            $css,
            'An unscoped [x-cloak] rule would hide overlays permanently after the first Livewire morph.',
        );
    }

    public function test_something_actually_sets_the_ready_flag(): void
    {
        $js = file_get_contents(resource_path('js/app.js'));

        // The scoping above is only safe if the flag is really set — otherwise
        // x-cloak would never hide anything and modals would flash on load.
        $this->assertStringContainsString('alpine:initialized', $js);
        $this->assertStringContainsString('data-alpine-ready', $js);
    }
}
