import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            /*
             * The staff interface has no use for a pixel palette or a canvas
             * renderer, and the two drawn screens have no use for Alpine — so
             * they are separate bundles rather than more lines in app.css.
             *
             * world.js and compound.js are two entries rather than one because
             * they are two projections of the same paint: whichever screen you
             * are on, the other one's sprites and geometry are dead weight. What
             * they genuinely share lives in resources/js/world/ and Vite hoists
             * it into a chunk both point at.
             */
            input: [
                'resources/css/app.css',
                'resources/js/app.js',

                /*
                 * pixel.css is the public side's design system; world.css is the
                 * drawn town, and imports pixel.css. So a page of notices loads
                 * pixel.css alone and the welcome page loads world.css, which
                 * brings both — neither ships the other's weight.
                 */
                'resources/css/pixel.css',
                'resources/css/skin.css',
                'resources/css/world.css',
                'resources/js/world.js',
                'resources/js/compound.js',
            ],
            refresh: true,
            /*
             * Self-hosted through Bunny rather than linked from Google, so a
             * gov.ph page makes no third-party request on behalf of somebody
             * who only came to read a notice.
             *
             * Space Grotesk and IBM Plex Mono are the welcome page's, and are
             * named in world.css alone: Space Grotesk for the chunky display
             * type the drawn town is titled and labelled in, the mono for the
             * badges and counters where digits have to line up. Instrument Sans
             * stays the interface font everywhere else.
             */
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                bunny('Space Grotesk', {
                    weights: [500, 700],
                }),
                bunny('IBM Plex Mono', {
                    weights: [400, 500],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
