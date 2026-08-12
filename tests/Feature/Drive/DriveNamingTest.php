<?php

namespace Tests\Feature\Drive;

use App\Livewire\Drive\Browser;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The drive's Alpine object must not shadow its Livewire methods.
 *
 * Livewire resolves a wire:click expression against the surrounding Alpine
 * scope before it reaches the component's own methods. The drive's selection
 * layer is the root x-data of the whole page, so any method it defines with the
 * same name as a public Livewire method silently swallows every wire:click that
 * calls it — the buttons simply stop working, with nothing in the console and
 * nothing in the log to say why.
 *
 * This has already happened once, to open(): naming the "open this item" helper
 * open() killed New folder, Rename, Sharing and New version across the page.
 * The dropdown component carries the same warning in a comment; this is the
 * version that fails a build.
 */
class DriveNamingTest extends TestCase
{
    public function test_the_selection_layer_shadows_no_livewire_method(): void
    {
        $shadowed = array_intersect(
            $this->alpineMethodNames(),
            $this->livewireMethodNames(),
        );

        $this->assertSame([], array_values($shadowed), sprintf(
            'driveBrowser() in resources/js/app.js defines %s, which also exists on %s. '
            .'Livewire resolves wire:click through Alpine scope first, so every '
            .'wire:click="%s(...)" on the drive page would silently do nothing. Rename the Alpine method.',
            implode(', ', $shadowed),
            Browser::class,
            reset($shadowed) ?: 'x',
        ));
    }

    /**
     * Method names defined on the Alpine object returned by driveBrowser().
     *
     * Read straight out of the source rather than executed: this only has to
     * spot `name(args) {` at the object's own indent level, which is all a
     * collision needs to happen.
     *
     * @return array<int, string>
     */
    private function alpineMethodNames(): array
    {
        $source = file_get_contents(base_path('resources/js/app.js'));

        $this->assertIsString($source);

        $start = strpos($source, "Alpine.data('driveBrowser'");
        $this->assertNotFalse($start, 'driveBrowser() is no longer registered in resources/js/app.js.');

        $end = strpos($source, "Alpine.store('tour'", $start);
        $body = substr($source, $start, ($end ?: strlen($source)) - $start);

        preg_match_all('/^        ([A-Za-z_$][\w$]*)\s*\([^)]*\)\s*\{/m', $body, $matches);

        return array_unique($matches[1]);
    }

    /** @return array<int, string> */
    private function livewireMethodNames(): array
    {
        return array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            (new ReflectionClass(Browser::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
    }

    /** The guard is worthless if it cannot see the methods it is guarding. */
    public function test_the_guard_actually_reads_both_sides(): void
    {
        $alpine = $this->alpineMethodNames();
        $livewire = $this->livewireMethodNames();

        $this->assertContains('openItem', $alpine);
        $this->assertContains('startMarquee', $alpine);
        $this->assertContains('open', $livewire);
        $this->assertContains('openFolder', $livewire);
    }
}
