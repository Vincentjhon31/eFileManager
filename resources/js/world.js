/*
|------------------------------------------------------------------------------
| The town, drawn
|------------------------------------------------------------------------------
|
| The other half of App\Support\World. That class decides what the landmarks
| are, what they say and where they go; this file decides what they look like.
| Nothing here knows a URL or a route name — it is handed a list and draws it in
| the order it arrives, so reordering the town is a PHP change alone.
|
| Everything is drawn at a low logical resolution and scaled up by an integer
| factor with image-rendering:pixelated. That is the whole technique: a 4px-wide
| rectangle is a 4px-wide rectangle, and at 3x it is a crisp 12px block rather
| than a blurry 12px smear. It also means the art is resolution-independent for
| free — the same sprites are sharp on a phone at 2x and a monitor at 5x.
|
| Only fillRect. No gradients, no anti-aliased curves, no images to load. Circles
| are drawn as stacked runs of pixels, because an arc() on a pixel canvas gives
| you grey edge pixels that read as dirt at 4x. The one exception is the sea's
| stroke-free wave rows, which are also rectangles.
|
| Sections below:
|   1. Palette and helpers
|   2. Sprites — one function per landmark
|   3. Layout — walking the place list into x positions
|   4. Background — sky, mountains, ground, parallax
|   5. The frame loop
|   6. Hit testing, hover, panning
|   7. Labels and the keyboard route
|   8. The guide
|   9. Splash, cloud wipe, motion preference
|  10. Boot
|
*/

const root = document.documentElement;
const stage = document.getElementById('worldStage');

/* ---------------------------------------------------------------- 1. palette */

const C = {
    ink: '#1b1f2a',
    cream: '#f4ecda',
    amber: '#f2a93b',
    rust: '#c1462f',
    navy: '#1b3a6b',
    teal: '#2e7d7b',

    sky: '#8fc7d6',
    skyHigh: '#7fbccd',
    haze: '#cfe4e2',
    cloud: 'rgba(255,255,255,.62)',

    grass: '#6fa84f',
    grassAlt: '#659c48',
    grassDark: '#54823c',
    plaza: '#c9bca2',
    plazaAlt: '#c0b295',
    road: '#b6a98f',
    sand: '#dfc79b',
    sandAlt: '#d6bc8e',
    sea: '#3f86ac',
    seaDeep: '#2f6e92',
    foam: '#dff0f4',

    wall: '#ede3d2',
    wallShade: '#dccfb4',
    roofTeal: '#2e7d7b',
    roofRust: '#c1462f',
    roofBrown: '#8e7048',
    roofNipa: '#a5763c',
    nipaDark: '#835c2d',
    wood: '#8e6a46',
    woodDark: '#6b4a2e',
    stone: '#a9a293',
    stoneDark: '#847e70',
    glass: '#2c4a5e',
    glassLit: '#3f6b84',

    leaf1: '#3e7a33',
    leaf2: '#4e9440',
    leaf3: '#5fa84c',

    rock: '#7d8a93',
    rockDark: '#5d6a73',
    rockLight: '#98a5ad',
    snow: '#eef4f6',

    skin: '#c98d5e',
    skinDark: '#a97147',
    barong: '#f2eddf',
    hair: '#2b2118',
    slacks: '#2f3542',
};

/* Every sprite draws through this. Rounding here rather than in forty call
   sites is what keeps the grid honest — a rect at x=10.5 is what puts a soft
   grey column down the middle of a wall. */
function r(c, x, y, w, h, fill) {
    c.fillStyle = fill;
    c.fillRect(Math.round(x), Math.round(y), Math.round(w), Math.round(h));
}

/* A filled circle in whole pixels: for each row, work out the half-width of the
   chord and draw it as one rectangle. Cheap, and every edge lands on the grid. */
function disc(c, cx, cy, rad, fill) {
    c.fillStyle = fill;
    for (let dy = -rad; dy <= rad; dy++) {
        const half = Math.floor(Math.sqrt(rad * rad - dy * dy));
        c.fillRect(Math.round(cx - half), Math.round(cy + dy), half * 2 + 1, 1);
    }
}

function shade(hex, amt) {
    const n = parseInt(hex.slice(1), 16);
    const clamp = (v) => Math.max(0, Math.min(255, Math.round(v)));
    return (
        '#' +
        [16, 8, 0]
            .map((s) => clamp(((n >> s) & 255) + amt).toString(16).padStart(2, '0'))
            .join('')
    );
}

/* A deterministic pseudo-random in [0,1) from an integer. Used for scattering
   grass tufts and stars so the same seed always gives the same town — the world
   must look identical after a resize, and Math.random() would reshuffle every
   tuft on every reflow. */
function noise(i) {
    const x = Math.sin(i * 12.9898) * 43758.5453;
    return x - Math.floor(x);
}

/* ---------------------------------------------------------------- 2. sprites */

/*
 * One entry per sprite name used by App\Support\World.
 *
 * Each declares how much horizontal room it needs and draws itself into a box
 * whose left edge is x and whose ground line is gy. Nothing reaches outside
 * that box horizontally, so the layout can pack them without knowing anything
 * about what they are. `t` is milliseconds since the world started, frozen at 0
 * when motion is off — so every animation is a function of t and stillness
 * costs no special case.
 *
 * `anchor` is where the label and the hover tag are pinned, measured up from the
 * ground line. Declared rather than derived because the tallest pixel of a
 * sprite is rarely where its name belongs — the hall's is its flagpole.
 */
const SPRITES = {
    /* -------------------------------------------------- the treehouse ------ */
    treehouse: {
        w: 112,
        anchor: 132,
        draw(c, x, gy, t) {
            const sway = Math.sin(t / 900) * 1.2;
            const cx = x + 56;

            /* Trunk, widening into roots so it does not read as a pole. */
            r(c, cx - 6, gy - 62, 12, 62, C.woodDark);
            r(c, cx - 4, gy - 62, 4, 62, C.wood);
            r(c, cx - 11, gy - 5, 6, 5, C.woodDark);
            r(c, cx + 5, gy - 5, 6, 5, C.woodDark);
            r(c, cx - 14, gy - 2, 28, 2, shade(C.woodDark, -14));

            /* Ladder up the near side. */
            for (let i = 0; i < 6; i++) {
                r(c, cx + 6, gy - 14 - i * 8, 12, 2, C.wood);
            }
            r(c, cx + 6, gy - 58, 2, 46, C.woodDark);
            r(c, cx + 16, gy - 58, 2, 46, C.woodDark);

            /* The house itself, a platform with a nipa roof. */
            r(c, cx - 24, gy - 68, 48, 3, C.wood);
            r(c, cx - 21, gy - 86, 42, 18, C.wallShade);
            r(c, cx - 21, gy - 86, 42, 4, shade(C.wallShade, -22));
            r(c, cx - 15, gy - 81, 10, 9, C.glass);
            r(c, cx - 13, gy - 79, 4, 5, C.glassLit);
            r(c, cx + 4, gy - 81, 11, 13, C.woodDark);

            /* Roof: two stepped courses, so the slope is pixels and not a
               diagonal the browser has to guess at. */
            r(c, cx - 26, gy - 90, 52, 4, C.nipaDark);
            r(c, cx - 22, gy - 94, 44, 4, C.roofNipa);
            r(c, cx - 17, gy - 97, 34, 3, C.roofNipa);
            r(c, cx - 11, gy - 99, 22, 2, shade(C.roofNipa, 18));

            /* Canopy above and behind, leaning with the breeze. Its lowest course
               overlaps the roof rather than floating clear of it — a gap of sky
               between the two makes the tree look like a separate object parked
               behind a hut instead of the thing the hut is built in. */
            const lx = cx + sway;
            r(c, lx - 34, gy - 106, 68, 10, C.leaf1);
            r(c, lx - 28, gy - 115, 56, 9, C.leaf2);
            r(c, lx - 19, gy - 122, 38, 7, C.leaf3);
            r(c, lx - 9, gy - 126, 18, 4, shade(C.leaf3, 14));
            /* Two lower bunches, on either side, to break the dome. */
            r(c, lx - 44, gy - 100, 16, 8, C.leaf1);
            r(c, lx + 28, gy - 102, 16, 8, C.leaf2);

            /* A rope swing, because a treehouse without one is just a shed. */
            r(c, cx - 30, gy - 109, 1, 30, C.woodDark);
            r(c, cx - 22, gy - 109, 1, 30, C.woodDark);
            r(c, cx - 31, gy - 79, 11, 2, C.wood);
        },
    },

    /* -------------------------------------------------- sari-sari store ---- */
    store: {
        w: 92,
        anchor: 70,
        draw(c, x, gy, t) {
            const flick = 0.7 + 0.3 * Math.sin(t / 300);

            r(c, x + 8, gy - 3, 76, 3, 'rgba(20,20,10,.16)');

            /* Body and the corrugated roof it wears like a cap. */
            r(c, x + 12, gy - 44, 68, 44, C.wallShade);
            r(c, x + 12, gy - 44, 68, 5, shade(C.wallShade, -26));
            r(c, x + 7, gy - 50, 78, 6, C.stoneDark);
            for (let i = 0; i < 13; i++) {
                r(c, x + 8 + i * 6, gy - 50, 3, 6, C.stone);
            }

            /* The counter window, with the grille every sari-sari store has and
               the hanging sachets behind it. */
            r(c, x + 20, gy - 36, 40, 22, C.ink);
            r(c, x + 22, gy - 34, 36, 18, shade(C.wood, -30));
            for (let i = 0; i < 6; i++) {
                r(c, x + 24 + i * 6, gy - 34, 1, 18, C.stoneDark);
            }
            const sachet = [C.amber, C.rust, C.teal, C.cream, C.amber, C.rust];
            sachet.forEach((col, i) => {
                r(c, x + 25 + i * 6, gy - 33, 3, 5, col);
            });

            /* Counter shelf and a stack of soft-drink crates beside the door. */
            r(c, x + 18, gy - 14, 44, 3, C.wood);
            r(c, x + 64, gy - 12, 12, 6, C.rust);
            r(c, x + 64, gy - 6, 12, 6, C.teal);

            /* Signboard. Lettering at this scale is a lie, so it is blocks —
               they read as writing without pretending to be legible. */
            r(c, x + 14, gy - 62, 64, 12, C.navy);
            r(c, x + 14, gy - 62, 64, 2, shade(C.navy, 22));
            for (let i = 0; i < 7; i++) {
                r(c, x + 18 + i * 8, gy - 58, 5, 4, i % 3 === 0 ? C.amber : C.cream);
            }

            /* A bare bulb over the counter, flickering. */
            r(c, x + 40, gy - 50, 1, 5, C.stoneDark);
            disc(c, x + 40, gy - 43, 2, `rgba(252,217,138,${flick})`);
        },
    },

    /* -------------------------------------------------- municipal hall ----- */
    hall: {
        w: 208,
        anchor: 136,
        draw(c, x, gy, t) {
            const wave = Math.sin(t / 260) * 3;
            const flick = 0.85 + 0.15 * Math.sin(t / 380);
            const lamp = (a) => `rgba(252,217,138,${a})`;

            r(c, x + 10, gy - 3, 188, 3, 'rgba(20,20,10,.18)');

            /* Right wing first, so the taller main block overlaps it. Two storeys
               of ordinary windows rather than the full-height mullions this had
               to begin with — those read as an abstract letterform stuck to the
               side of the building rather than as an annexe with rooms in it. */
            r(c, x + 150, gy - 62, 46, 62, C.wallShade);
            r(c, x + 148, gy - 66, 50, 5, C.roofBrown);
            r(c, x + 148, gy - 66, 50, 2, shade(C.roofBrown, 30));
            r(c, x + 152, gy - 60, 42, 60, C.wall);

            [0, 1].forEach((storey) => {
                const wy = gy - 54 + storey * 26;
                [156, 176].forEach((wx) => {
                    r(c, x + wx, wy, 14, 17, C.glass);
                    r(c, x + wx, wy, 14, 6, C.glassLit);
                    r(c, x + wx + 6, wy, 2, 17, shade(C.glass, -24));
                    r(c, x + wx, wy + 8, 14, 1, shade(C.glass, -24));
                    /* Sill. */
                    r(c, x + wx - 1, wy + 17, 16, 2, shade(C.wall, -22));
                });
            });

            /* String course between the storeys, tying the wing to the main
               block's signage band. */
            r(c, x + 152, gy - 30, 42, 2, C.navy);

            /* Main block. */
            r(c, x + 26, gy - 72, 126, 72, C.wall);
            r(c, x + 26, gy - 72, 126, 3, shade(C.wall, -18));

            /* Roof overhang, stepped. */
            r(c, x + 20, gy - 94, 138, 7, C.roofBrown);
            r(c, x + 20, gy - 94, 138, 4, shade(C.roofBrown, 34));
            r(c, x + 30, gy - 99, 118, 5, shade(C.roofBrown, -18));

            /* Signage band with the municipal seal at its centre. */
            r(c, x + 26, gy - 87, 126, 15, C.navy);
            r(c, x + 26, gy - 87, 126, 2, shade(C.navy, 26));
            const sx = x + 89;
            disc(c, sx, gy - 79, 6, C.amber);
            disc(c, sx, gy - 79, 4, C.navy);
            disc(c, sx, gy - 79, 2, C.cream);
            /* Block "lettering" either side of the seal. */
            [-1, 1].forEach((dir) => {
                for (let i = 0; i < 5; i++) {
                    r(c, sx + dir * (13 + i * 8) - 3, gy - 82, 5, 5, C.cream);
                }
            });

            /* Columns, each with a lamp top and bottom. */
            [32, 68, 104, 140].forEach((cxo) => {
                r(c, x + cxo, gy - 72, 8, 72, C.navy);
                r(c, x + cxo + 2, gy - 72, 3, 72, C.amber);
                r(c, x + cxo + 2, gy - 65, 4, 4, lamp(flick));
                r(c, x + cxo + 2, gy - 14, 4, 4, lamp(flick));
            });

            /* Two window bays between the columns, mullioned. */
            [
                [42, 66],
                [78, 102],
            ].forEach(([a, b]) => {
                r(c, x + a, gy - 62, b - a, 38, C.glass);
                r(c, x + a, gy - 62, b - a, 12, C.glassLit);
                for (let yy = gy - 62; yy < gy - 24; yy += 7) {
                    r(c, x + a, yy, b - a, 1, shade(C.glass, -22));
                }
                for (let xx = a; xx < b; xx += 8) {
                    r(c, x + xx, gy - 62, 1, 38, shade(C.glass, -22));
                }
                /* Planter box under the sill. */
                r(c, x + a - 2, gy - 12, b - a + 4, 9, C.grassDark);
                r(c, x + a - 2, gy - 12, b - a + 4, 4, C.grass);
            });

            /* The front door, up three steps, with a small awning. */
            r(c, x + 112, gy - 40, 26, 40, shade(C.wood, -26));
            r(c, x + 115, gy - 37, 20, 37, C.wood);
            r(c, x + 124, gy - 26, 2, 2, C.amber);
            r(c, x + 110, gy - 44, 30, 4, C.roofTeal);
            for (let i = 0; i < 3; i++) {
                r(c, x + 108 - i * 3, gy - 3 - i * 3, 34 + i * 6, 3, C.stone);
            }

            /* Flagpole on the roof, flag rippling. Drawn as three stacked bands
               rather than a polygon so the edge stays square. */
            r(c, x + 89, gy - 130, 2, 31, C.stoneDark);
            r(c, x + 91, gy - 129, 15, 4, C.navy);
            r(c, x + 91, gy - 125 + Math.round(wave * 0.3), 15, 4, C.rust);
            r(c, x + 91, gy - 121 + Math.round(wave * 0.5), 15, 4, C.cream);
            r(c, x + 91, gy - 129, 5, 3, C.amber);
        },
    },

    /* -------------------------------------------------- plaza fountain ----- */
    fountain: {
        w: 124,
        anchor: 84,
        draw(c, x, gy, t) {
            const cx = x + 62;

            /* Basin: three stepped ellipses, widest at the bottom. Ellipses are
               drawn as stacks of centred rectangles so the rim stays square. */
            const oval = (cyy, rw, rh, fill) => {
                c.fillStyle = fill;
                for (let dy = -rh; dy <= rh; dy++) {
                    const half = Math.floor(rw * Math.sqrt(Math.max(0, 1 - (dy * dy) / (rh * rh))));
                    c.fillRect(Math.round(cx - half), Math.round(cyy + dy), half * 2 + 1, 1);
                }
            };

            oval(gy - 6, 52, 9, C.stoneDark);
            oval(gy - 9, 50, 8, C.stone);
            oval(gy - 11, 42, 6, C.seaDeep);
            oval(gy - 12, 40, 5, C.sea);

            /* Water surface, lit in bands that crawl outwards. */
            for (let i = 0; i < 3; i++) {
                const p = ((t / 1100 + i / 3) % 1);
                const rw = Math.round(8 + p * 30);
                c.fillStyle = `rgba(223,240,244,${(1 - p) * 0.55})`;
                c.fillRect(Math.round(cx - rw), Math.round(gy - 13), rw * 2, 1);
            }

            /* Pedestal and upper dish. */
            r(c, cx - 5, gy - 30, 10, 19, C.stone);
            r(c, cx - 3, gy - 30, 3, 19, C.rockLight);
            oval(gy - 32, 16, 3, C.stoneDark);
            oval(gy - 33, 15, 2, C.stone);
            r(c, cx - 2, gy - 44, 4, 12, C.stone);

            /* The jet, and the falling water either side of it. Sine on t means
               it breathes instead of standing rigid. */
            const jet = 8 + Math.sin(t / 420) * 2;
            r(c, cx - 1, gy - 44 - jet, 2, jet, C.foam);
            disc(c, cx, gy - 45 - jet, 2, C.foam);
            [-1, 1].forEach((dir) => {
                for (let i = 0; i < 5; i++) {
                    const p = (t / 260 + i / 5) % 1;
                    const px = cx + dir * Math.round(3 + p * 11);
                    const py = gy - 42 + Math.round(p * p * 28);
                    r(c, px, py, 1, 2, i % 2 ? C.foam : '#bfe0e8');
                }
            });
            /* Spill from the upper dish into the basin. */
            [-13, 13].forEach((dx) => {
                r(c, cx + dx, gy - 31, 1, 18, 'rgba(223,240,244,.6)');
            });

            /* The disclosure board standing beside the fountain — the reason
               this landmark is a link and not only scenery. */
            r(c, x + 4, gy - 46, 4, 46, C.woodDark);
            r(c, x + 26, gy - 46, 4, 46, C.woodDark);
            r(c, x, gy - 74, 34, 30, C.wood);
            r(c, x + 3, gy - 71, 28, 24, C.cream);
            for (let i = 0; i < 5; i++) {
                r(c, x + 5, gy - 68 + i * 5, 20 - (i % 2) * 6, 2, '#9dafc6');
            }
            r(c, x - 2, gy - 78, 38, 5, C.roofTeal);
            r(c, x - 2, gy - 78, 38, 2, shade(C.roofTeal, 26));
        },
    },

    /* -------------------------------------------------- notice board ------- */
    bulletin: {
        w: 104,
        anchor: 82,
        draw(c, x, gy, t) {
            const flutter = Math.sin(t / 340);

            r(c, x + 12, gy - 2, 80, 2, 'rgba(20,20,10,.16)');

            /* Two posts and the board they carry. */
            r(c, x + 18, gy - 52, 5, 52, C.woodDark);
            r(c, x + 78, gy - 52, 5, 52, C.woodDark);
            r(c, x + 14, gy - 62, 74, 4, C.wood);

            r(c, x + 12, gy - 60, 78, 42, shade(C.wood, -30));
            r(c, x + 15, gy - 57, 72, 36, C.cream);

            /* Papers pinned to it, one corner of one lifting in the breeze. */
            const notes = [
                [4, 3, 20, 15, C.amber],
                [27, 3, 18, 14, '#e8e2d2'],
                [48, 4, 20, 13, '#e8e2d2'],
                [5, 21, 19, 12, '#e8e2d2'],
                [28, 20, 22, 14, C.rust],
                [53, 20, 15, 13, '#e8e2d2'],
            ];
            notes.forEach(([nx, ny, nw, nh, col], i) => {
                const lift = i === 2 ? Math.round(flutter) : 0;
                r(c, x + 16 + nx, gy - 56 + ny + lift, nw, nh, col);
                /* Two ruled lines, so it reads as a printed notice. */
                r(c, x + 18 + nx, gy - 53 + ny + lift, nw - 5, 1, 'rgba(27,31,42,.35)');
                r(c, x + 18 + nx, gy - 50 + ny + lift, nw - 9, 1, 'rgba(27,31,42,.25)');
                /* The pin. */
                r(c, x + 16 + nx + Math.floor(nw / 2), gy - 56 + ny + lift, 2, 2, C.rust);
            });

            /* Nipa roof over the board, to keep the rain off the paper. */
            r(c, x + 6, gy - 68, 90, 5, C.nipaDark);
            r(c, x + 11, gy - 73, 80, 5, C.roofNipa);
            r(c, x + 20, gy - 76, 62, 3, shade(C.roofNipa, 16));
        },
    },

    /* -------------------------------------------------- basketball court --- */
    /*
     * The covered court.
     *
     * Roofed, which is both what a barangay court actually is and what makes it
     * legible. The first attempt was an open court: two backboards on poles
     * above a raised slab. In side elevation that reads as nothing — a pair of
     * signs floating over a bench — because a flat rectangle of ground drawn
     * side-on has no shape at all. A roof on posts has a silhouette, and a
     * silhouette is what tells you at a glance what a thing is.
     *
     * The plaza the court stands on is already the playing surface, so there is
     * no slab: just paint on the ground.
     */
    court: {
        w: 134,
        anchor: 104,
        draw(c, x, gy, t) {
            /* Painted markings. Flattened, because paint on the ground is seen
               at a glancing angle from here — a true circle would read as a
               ball lying on the court. */
            const paint = 'rgba(244,236,218,.5)';
            r(c, x + 10, gy - 2, 114, 2, paint);
            r(c, x + 24, gy - 24, 88, 1, 'rgba(244,236,218,.3)');
            r(c, x + 67, gy - 24, 1, 22, 'rgba(244,236,218,.3)');
            [-5, 5].forEach((dy) => {
                r(c, x + 53, gy - 13 + dy, 28, 1, 'rgba(244,236,218,.34)');
            });

            /* Posts. The middle pair is drawn darker and shorter to sit behind
               the outer pair, which is the only depth this needs. */
            [42, 88].forEach((px) => {
                r(c, x + px, gy - 62, 3, 62, shade(C.stoneDark, -18));
            });
            [12, 118].forEach((px) => {
                r(c, x + px, gy - 68, 5, 68, C.stoneDark);
                r(c, x + px + 1, gy - 68, 2, 68, C.stone);
            });

            /* Roof: two shallow corrugated courses and a ridge cap. */
            const corrugate = (rx, ry, rw, rh, tone) => {
                r(c, rx, ry, rw, rh, shade(tone, -22));
                for (let i = 0; i + 6 <= rw; i += 6) r(c, rx + i, ry, 3, rh, tone);
            };
            /* Painted roof rather than bare galvanised iron. Grey posts under a
               grey roof read as scaffolding; a colour makes it a building. Teal
               because nothing else in town has it — the hall's roof is brown and
               the kiosk's is rust, so this is the one that tells them apart at a
               distance. */
            corrugate(x + 4, gy - 74, 126, 6, C.roofTeal);
            corrugate(x + 20, gy - 80, 94, 6, shade(C.roofTeal, 14));
            r(c, x + 52, gy - 84, 30, 4, shade(C.roofTeal, -20));
            r(c, x + 52, gy - 84, 30, 2, shade(C.roofTeal, 26));

            /* Backboards, hung from the roof rather than stuck on poles. */
            [28, 102].forEach((hx) => {
                r(c, x + hx + 3, gy - 74, 2, 8, C.stoneDark);
                r(c, x + hx - 6, gy - 66, 19, 13, C.cream);
                r(c, x + hx - 6, gy - 66, 19, 2, C.rust);
                r(c, x + hx - 1, gy - 58, 9, 5, C.stoneDark);
                r(c, x + hx - 3, gy - 54, 13, 2, C.rust);
                for (let i = 0; i < 3; i++) {
                    r(c, x + hx - 1 + i * 4, gy - 52, 1, 4, 'rgba(244,236,218,.8)');
                }
            });

            /* A ball in mid-arc with its shadow tracking underneath — the one
               moving thing here, and enough to make the court read as in use. */
            const p = (t / 1600) % 1;
            const bx = x + 34 + p * 66;
            const by = gy - 12 - Math.sin(p * Math.PI) * 34;
            r(c, bx - 3, gy - 3, 7, 2, 'rgba(20,20,10,.22)');
            disc(c, bx, by, 3, '#d2762e');
            r(c, bx - 3, by, 7, 1, shade('#d2762e', -40));
        },
    },

    /* -------------------------------------------------- citizen kiosk ----- */
    kiosk: {
        w: 84,
        anchor: 72,
        draw(c, x, gy, t) {
            const glow = 0.55 + 0.45 * Math.sin(t / 700);

            r(c, x + 12, gy - 2, 60, 2, 'rgba(20,20,10,.16)');

            /* A small covered stand: posts, counter, screen. */
            r(c, x + 16, gy - 26, 52, 26, C.wallShade);
            r(c, x + 16, gy - 26, 52, 3, shade(C.wallShade, -24));
            r(c, x + 12, gy - 30, 60, 4, C.wood);

            r(c, x + 20, gy - 56, 4, 30, C.woodDark);
            r(c, x + 60, gy - 56, 4, 30, C.woodDark);

            /* The screen under the canopy, lit and cycling. */
            r(c, x + 24, gy - 54, 36, 24, C.ink);
            r(c, x + 26, gy - 52, 32, 20, C.navy);
            r(c, x + 28, gy - 50, 28, 3, `rgba(242,169,59,${glow})`);
            for (let i = 0; i < 4; i++) {
                r(c, x + 28, gy - 45 + i * 4, 28 - i * 5, 2, 'rgba(244,236,218,.55)');
            }

            /* Canopy. */
            r(c, x + 10, gy - 62, 64, 5, C.roofRust);
            r(c, x + 10, gy - 62, 64, 2, shade(C.roofRust, 30));
            r(c, x + 18, gy - 65, 48, 3, shade(C.roofRust, -22));

            /* A stack of blank forms on the counter, for the taking. */
            for (let i = 0; i < 4; i++) {
                r(c, x + 30 - i, gy - 29 - i * 2, 22, 2, i % 2 ? C.cream : '#e8e2d2');
            }
        },
    },

    /* -------------------------------------------------- the shore --------- */
    beach: {
        w: 206,
        anchor: 78,
        draw(c, x, gy, t) {
            /* The water itself is not drawn here.
             *
             * It is a property of the ground this landmark stands on, not of the
             * landmark, so the band painter lays it down from the horizon to the
             * shoreline before any sprite runs — which is also why the sea
             * stretches to the edge of the town rather than stopping abruptly at
             * this sprite's 206th pixel. What is left here is everything that
             * floats on it or stands beside it. */
            const shore = GROUND_Y - SEA_DEPTH + 3;

            /* The breaking line, advancing and retreating over the sand. Drawn
               a little wider than this sprite's box on both sides so the foam
               carries into the neighbouring band instead of ending in a step —
               the only thing here allowed outside the box, and only because the
               band either side of it is water or sand too. */
            /* Drawn in short segments whose offset varies along the beach, not as
               one long rectangle — a shoreline that is straight to the pixel is
               the one detail that gives away a drawn sea. */
            for (let i = 0; i < 246; i += 6) {
                const off = Math.round(Math.sin(t / 1300 + i / 23) * 3);
                r(c, x - 20 + i, shore + off, 6, 3, C.foam);
                r(c, x - 20 + i, shore + 3 + off, 6, 2, 'rgba(223,240,244,.55)');
            }

            /*
             * A bangka drawn up on the sand.
             *
             * Built as a hull that narrows downward with a prow and stern rising
             * clear of it, and the near outrigger float carried in front of and
             * below the hull on two booms. The first attempt drew the hull as one
             * flat plank with two vertical stubs under it, which is a table —
             * what makes a boat a boat here is the taper and the lifted ends.
             */
            const bx = x + 130;

            /* Near outrigger float, and the booms out to it. Drawn first so the
               hull sits in front of where they meet it. */
            r(c, bx - 24, gy - 3, 48, 3, shade(C.wood, -34));
            r(c, bx - 24, gy - 3, 48, 1, shade(C.wood, -14));
            r(c, bx - 15, gy - 11, 3, 9, C.woodDark);
            r(c, bx + 12, gy - 11, 3, 9, C.woodDark);

            /* Hull: three strakes, each narrower than the one above it. */
            r(c, bx - 21, gy - 14, 42, 4, C.wood);
            r(c, bx - 21, gy - 14, 42, 1, shade(C.wood, 20));
            r(c, bx - 17, gy - 10, 34, 3, shade(C.wood, -26));
            r(c, bx - 12, gy - 7, 24, 2, shade(C.wood, -40));

            /* Prow and stern, lifted. */
            r(c, bx - 24, gy - 18, 4, 5, C.woodDark);
            r(c, bx + 20, gy - 18, 4, 5, C.woodDark);
            r(c, bx - 24, gy - 20, 3, 3, C.woodDark);

            /* Mast and a furled sail. */
            r(c, bx - 4, gy - 36, 2, 22, C.woodDark);
            r(c, bx - 2, gy - 35, 11, 13, C.cream);
            r(c, bx - 2, gy - 35, 11, 2, shade(C.cream, -20));
            r(c, bx - 2, gy - 27, 8, 1, C.rust);

            /* Two palms, leaning seaward, fronds moving out of step. */
            const palm = (px, h, seed) => {
                const sway = Math.sin(t / 800 + seed) * 2;
                for (let i = 0; i < h; i += 4) {
                    r(c, px + Math.round((i / h) * 5 + sway * (i / h)), gy - 4 - i, 4, 4, i % 8 ? C.wood : C.woodDark);
                }
                const tx = px + Math.round(5 + sway);
                const ty = gy - 6 - h;
                /* Six fronds, each a short stepped arc. */
                for (let f = 0; f < 6; f++) {
                    const dir = f < 3 ? -1 : 1;
                    const spread = (f % 3) + 1;
                    for (let s = 0; s < 7; s++) {
                        r(
                            c,
                            tx + dir * s * 3,
                            ty + Math.round((s * s) / 4) - spread * 3 + Math.round(Math.sin(t / 700 + f) * 0.8),
                            4,
                            2,
                            f % 2 ? C.leaf1 : C.leaf2,
                        );
                    }
                }
                /* Coconuts. */
                r(c, tx - 3, ty + 2, 3, 3, C.woodDark);
                r(c, tx + 2, ty + 3, 3, 3, C.woodDark);
            };
            palm(x + 22, 44, 0);
            palm(x + 62, 32, 2.1);

            /* A lighthouse on the point, its lamp turning. */
            const lx = x + 182;
            r(c, lx - 10, gy - 8, 22, 8, C.rockDark);
            r(c, lx - 8, gy - 52, 18, 44, C.cream);
            for (let i = 0; i < 3; i++) {
                r(c, lx - 8, gy - 46 + i * 14, 18, 6, C.rust);
            }
            r(c, lx - 10, gy - 58, 22, 6, C.stone);
            r(c, lx - 7, gy - 56, 16, 4, C.glass);
            /* The beam: brightness swings with a sine so it reads as rotating. */
            const beam = Math.max(0, Math.sin(t / 1000));
            r(c, lx - 7, gy - 56, 16, 4, `rgba(252,217,138,${beam})`);
            r(c, lx - 10, gy - 64, 22, 6, C.rust);
            r(c, lx - 4, gy - 68, 10, 4, C.rust);
            if (beam > 0.55) {
                r(c, lx + 9, gy - 57, 26, 1, `rgba(252,217,138,${(beam - 0.55) * 0.9})`);
                r(c, lx + 9, gy - 55, 20, 1, `rgba(252,217,138,${(beam - 0.55) * 0.6})`);
            }

            /* Two bangkas out on the water, bobbing. Positioned from the
               horizon rather than the ground line, because that is where the
               water is — the nearer one lower, and therefore larger sail. */
            [
                [46, 0.4, HORIZON + 26],
                [112, 1.7, HORIZON + 12],
            ].forEach(([ox, seed, yy0]) => {
                const yy = yy0 + Math.round(Math.sin(t / 700 + seed));
                r(c, x + ox - 7, yy, 15, 2, C.woodDark);
                r(c, x + ox - 1, yy - 7, 1, 7, C.woodDark);
                r(c, x + ox, yy - 6, 6, 5, C.cream);
                /* Wake. */
                r(c, x + ox - 10, yy + 2, 21, 1, 'rgba(223,240,244,.45)');
            });
        },
    },

    /* ================================================== the compound ======= */

    /*
     * One office, drawn to order.
     *
     * The staff compound has a building per destination, and there are up to a
     * dozen of them depending on what the signed-in employee may open. Drawing
     * twelve bespoke sprites would be twelve chances for one of them to drift
     * out of style, and would mean a new sprite every time a screen is added.
     *
     * So there is one building, and the place supplies its width, its height in
     * storeys, its two colours and a motif for the plaque over its door. That is
     * enough to make a dozen buildings that are obviously the same town and
     * obviously not each other — which is exactly the job. See MOTIFS below for
     * the sixteen-pixel emblems that do the actual telling apart.
     */
    office: {
        w: 118,
        anchor: 104,
        draw(c, x, gy, t, place) {
            const s = place.style || {};
            const wall = s.wall || C.wall;
            const roof = s.roof || C.roofTeal;
            const w = place.width || 118;
            const storeys = Math.max(1, s.storeys || 1);
            const h = 44 + (storeys - 1) * 28;
            const flick = 0.85 + 0.15 * Math.sin(t / 380 + w);

            r(c, x + 6, gy - 3, w - 12, 3, 'rgba(20,20,10,.18)');

            /* Body. */
            r(c, x + 8, gy - h, w - 16, h, wall);
            r(c, x + 8, gy - h, w - 16, 3, shade(wall, -20));
            r(c, x + 8, gy - h, 3, h, shade(wall, -10));
            r(c, x + w - 11, gy - h, 3, h, shade(wall, -26));

            /* Roof, stepped twice. */
            r(c, x + 2, gy - h - 8, w - 4, 5, shade(roof, -26));
            r(c, x + 2, gy - h - 8, w - 4, 2, shade(roof, 22));
            r(c, x + 10, gy - h - 13, w - 20, 5, roof);
            r(c, x + 10, gy - h - 13, w - 20, 2, shade(roof, 26));

            /* Signage band, with the plaque that says which office this is. */
            const bandY = gy - h + 3;
            r(c, x + 8, bandY, w - 16, 13, C.navy);
            r(c, x + 8, bandY, w - 16, 2, shade(C.navy, 24));

            const mid = x + Math.round(w / 2);
            r(c, mid - 10, bandY - 2, 20, 17, C.cream);
            r(c, mid - 10, bandY - 2, 20, 2, shade(C.cream, -18));
            const motif = MOTIFS[s.motif];
            if (motif) motif(c, mid - 8, bandY, roof);

            /*
             * The office's name, either side of the plaque.
             *
             * Short bars of varying width rather than a row of equal squares.
             * Equal squares read as a third row of windows — the building ends up
             * with glazing, glazing and more glazing — whereas uneven bars read
             * as words, which is what a signage band has on it. Nothing is
             * spelled out: at this size lettering would be mud, and a shape that
             * suggests text is more honest than one that pretends to be it.
             */
            const WORDS = [7, 4, 9, 5, 6, 8];
            [-1, 1].forEach((dir) => {
                let at = 15;
                for (let i = 0; at + WORDS[i % WORDS.length] < Math.floor(w / 2) - 6; i++) {
                    const len = WORDS[i % WORDS.length];
                    r(c, dir < 0 ? mid - at - len : mid + at, bandY + 5, len, 3, C.cream);
                    at += len + 3;
                }
            });

            /* Windows, one row per storey below the band. */
            for (let sy = 0; sy < storeys; sy++) {
                const wy = bandY + 20 + sy * 28;
                for (let wx = x + 16; wx < x + w - 30; wx += 22) {
                    r(c, wx, wy, 15, 17, C.glass);
                    r(c, wx, wy, 15, 6, C.glassLit);
                    r(c, wx + 7, wy, 1, 17, shade(C.glass, -24));
                    r(c, wx, wy + 8, 15, 1, shade(C.glass, -24));
                    r(c, wx - 1, wy + 17, 17, 2, shade(wall, -24));
                }
            }

            /* Door on the right, with a lamp over it and two steps down. */
            const dx = x + w - 28;
            r(c, dx, gy - 26, 17, 26, shade(C.wood, -26));
            r(c, dx + 2, gy - 24, 13, 24, C.wood);
            r(c, dx + 11, gy - 14, 2, 2, C.amber);
            r(c, dx - 2, gy - 30, 21, 4, roof);
            r(c, dx + 7, gy - 34, 3, 4, C.stoneDark);
            r(c, dx + 6, gy - 32, 5, 3, `rgba(252,217,138,${flick})`);
            for (let i = 0; i < 2; i++) {
                r(c, dx - 2 - i * 3, gy - 2 - i * 2, 21 + i * 6, 2, C.stone);
            }

            /* A planter along the front, so the buildings are not stood on bare
               concrete in a row. */
            r(c, x + 12, gy - 9, w - 46, 7, C.grassDark);
            r(c, x + 12, gy - 9, w - 46, 3, C.grass);
        },
    },

    /* -------------------------------------------------- the gate ---------- */
    gate: {
        w: 96,
        anchor: 96,
        draw(c, x, gy, t) {
            /* Guardhouse. */
            r(c, x + 4, gy - 40, 32, 40, C.wallShade);
            r(c, x + 6, gy - 38, 28, 38, C.wall);
            r(c, x + 10, gy - 32, 20, 15, C.glass);
            r(c, x + 10, gy - 32, 20, 5, C.glassLit);
            r(c, x, gy - 46, 40, 6, C.roofRust);
            r(c, x, gy - 46, 40, 2, shade(C.roofRust, 28));
            r(c, x + 8, gy - 50, 24, 4, shade(C.roofRust, -20));

            /* Gate posts and the arch sign between them. */
            [44, 86].forEach((px) => {
                r(c, x + px, gy - 62, 8, 62, C.stone);
                r(c, x + px + 2, gy - 62, 3, 62, C.rockLight);
                r(c, x + px - 1, gy - 66, 10, 4, C.stoneDark);
            });
            r(c, x + 44, gy - 76, 50, 11, C.navy);
            r(c, x + 44, gy - 76, 50, 2, shade(C.navy, 24));
            for (let i = 0; i < 5; i++) {
                r(c, x + 49 + i * 9, gy - 72, 6, 4, i === 2 ? C.amber : C.cream);
            }

            /* The barrier, raised. */
            r(c, x + 52, gy - 30, 3, 12, C.rust);
            for (let i = 0; i < 5; i++) {
                r(c, x + 55 + i * 7, gy - 34 - i * 4, 7, 3, i % 2 ? C.cream : C.rust);
            }
        },
    },

    /* -------------------------------------------------- flagpole ---------- */
    flagpole: {
        w: 76,
        anchor: 120,
        draw(c, x, gy, t) {
            const wave = Math.sin(t / 260) * 3;
            const cx = x + 38;

            /* Tiered plinth. */
            for (let i = 0; i < 3; i++) {
                r(c, cx - 22 + i * 5, gy - 4 - i * 4, 44 - i * 10, 4, i % 2 ? C.stone : C.rockLight);
            }

            r(c, cx - 1, gy - 108, 3, 92, C.stoneDark);
            r(c, cx - 1, gy - 108, 1, 92, C.rockLight);

            /* Flag, three bands that lag one another as it ripples. */
            r(c, cx + 2, gy - 106, 20, 5, C.navy);
            r(c, cx + 2, gy - 101 + Math.round(wave * 0.3), 20, 5, C.rust);
            r(c, cx + 2, gy - 96 + Math.round(wave * 0.55), 20, 5, C.cream);
            r(c, cx + 2, gy - 106, 6, 4, C.amber);
            disc(c, cx, gy - 110, 2, C.amber);

            /* A bench either side, facing the pole. */
            [-30, 22].forEach((bx) => {
                r(c, cx + bx, gy - 12, 16, 3, C.wood);
                r(c, cx + bx + 1, gy - 9, 2, 9, C.woodDark);
                r(c, cx + bx + 13, gy - 9, 2, 9, C.woodDark);
            });
        },
    },

    /* -------------------------------------------------- waiting shed ------ */
    shed: {
        w: 88,
        anchor: 78,
        draw(c, x, gy, t) {
            [8, 74].forEach((px) => {
                r(c, x + px, gy - 50, 5, 50, C.woodDark);
                r(c, x + px + 1, gy - 50, 2, 50, C.wood);
            });

            /*
             * Nipa roof, thatched.
             *
             * Three stepped courses is the shape; the vertical nicks are what
             * make it a roof. Without them the slab reads as a table top on two
             * legs, which is what this looked like at first — thatch is a texture
             * before it is a silhouette.
             */
            r(c, x + 2, gy - 58, 84, 7, C.nipaDark);
            r(c, x + 8, gy - 64, 72, 6, C.roofNipa);
            r(c, x + 20, gy - 68, 48, 4, shade(C.roofNipa, 16));

            for (let i = 0; i < 28; i++) {
                const nx = x + 4 + i * 3;
                r(c, nx, gy - 57, 1, 5, shade(C.nipaDark, -16));
                if (nx > x + 9 && nx < x + 79) r(c, nx + 1, gy - 63, 1, 4, shade(C.roofNipa, -18));
            }

            /* A shadow line under the eaves, so the roof sits over the space
               rather than floating level with it. */
            r(c, x + 6, gy - 51, 76, 2, 'rgba(20,20,10,.22)');

            /* Bench and a back rail. */
            r(c, x + 12, gy - 22, 66, 4, C.wood);
            r(c, x + 12, gy - 18, 66, 2, shade(C.wood, -30));
            [16, 40, 68].forEach((lx) => r(c, x + lx, gy - 18, 3, 18, C.woodDark));
            r(c, x + 12, gy - 34, 66, 3, shade(C.wood, -18));

            /* Somebody waiting, because an empty shed is furniture. */
            r(c, x + 30, gy - 36, 8, 14, C.teal);
            r(c, x + 31, gy - 42, 6, 6, C.skin);
            r(c, x + 31, gy - 43, 6, 3, C.hair);
            r(c, x + 30, gy - 22, 3, 8, C.slacks);
            r(c, x + 35, gy - 22, 3, 8, C.slacks);
        },
    },

    /* -------------------------------------------------- jeepney ----------- */
    jeepney: {
        w: 104,
        anchor: 62,
        draw(c, x, gy, t) {
            const bob = Math.round(Math.sin(t / 700));
            const y = gy - 4 + bob;

            r(c, x + 6, gy - 2, 92, 2, 'rgba(20,20,10,.2)');

            /* Long body, with the bonnet stepped down at the front. */
            r(c, x + 6, y - 26, 88, 22, C.rust);
            r(c, x + 6, y - 26, 88, 3, shade(C.rust, 26));
            r(c, x + 6, y - 8, 88, 4, shade(C.rust, -30));
            r(c, x + 74, y - 20, 24, 16, shade(C.rust, -12));
            r(c, x + 92, y - 14, 6, 6, C.amber);

            /* Roof and the chrome strip every jeepney has along it. */
            r(c, x + 4, y - 32, 74, 6, C.cream);
            r(c, x + 4, y - 32, 74, 2, '#ffffff');
            for (let i = 0; i < 8; i++) {
                r(c, x + 8 + i * 9, y - 34, 5, 2, i % 2 ? C.amber : C.teal);
            }

            /* Open side, with passengers' knees and the long bench. */
            for (let i = 0; i < 5; i++) {
                r(c, x + 12 + i * 13, y - 24, 9, 12, C.glass);
                r(c, x + 12 + i * 13, y - 24, 9, 4, C.glassLit);
            }
            r(c, x + 8, y - 12, 62, 3, shade(C.wood, -20));

            /* Wheels. */
            [22, 76].forEach((wx) => {
                disc(c, x + wx, y - 2, 6, C.ink);
                disc(c, x + wx, y - 2, 3, C.stoneDark);
                disc(c, x + wx, y - 2, 1, C.rockLight);
            });
        },
    },
};

/* ------------------------------------------------------------- 2b. motifs */

/*
 * The emblems on each office's door plaque, drawn in a sixteen-pixel box whose
 * top-left corner is (mx, my).
 *
 * Sixteen pixels is not much, so each of these is one idea and no more: a shape
 * that is recognisable at a glance and, more importantly, not mistakable for its
 * neighbour on the next building along. `tint` is the building's own roof
 * colour, used sparingly so the plaque belongs to the building carrying it.
 */
const MOTIFS = {
    /* Four tiles — the four numbers the dashboard is. */
    dashboard(c, mx, my, tint) {
        [
            [0, 0],
            [8, 0],
            [0, 8],
            [8, 8],
        ].forEach(([dx, dy], i) => {
            r(c, mx + dx, my + dy, 7, 7, i % 3 === 0 ? tint : C.navy);
            r(c, mx + dx, my + dy, 7, 2, 'rgba(255,255,255,.35)');
        });
    },

    /* A desk with a lamp leaning over it. */
    desk(c, mx, my, tint) {
        r(c, mx, my + 9, 16, 3, C.navy);
        r(c, mx + 1, my + 12, 2, 4, C.navy);
        r(c, mx + 13, my + 12, 2, 4, C.navy);
        r(c, mx + 3, my + 5, 2, 5, C.navy);
        r(c, mx + 3, my + 3, 6, 2, tint);
        r(c, mx + 10, my + 6, 5, 4, C.rust);
    },

    /* Three sheets, the top one with its corner turned. */
    documents(c, mx, my, tint) {
        r(c, mx + 4, my, 10, 13, 'rgba(27,31,42,.35)');
        r(c, mx + 2, my + 1, 10, 13, C.cream);
        r(c, mx + 1, my + 2, 11, 13, '#ffffff');
        r(c, mx + 1, my + 2, 11, 2, tint);
        [5, 8, 11].forEach((ly) => r(c, mx + 3, my + ly, 7, 1, C.navy));
        r(c, mx + 9, my + 12, 3, 3, C.navy);
    },

    /* A three-by-three of app tiles. */
    workspace(c, mx, my, tint) {
        for (let i = 0; i < 9; i++) {
            const dx = (i % 3) * 5.5;
            const dy = Math.floor(i / 3) * 5.5;
            r(c, mx + dx, my + dy, 4, 4, i % 4 === 0 ? tint : C.navy);
        }
    },

    /* A filing cabinet, two drawers with handles. */
    drive(c, mx, my, tint) {
        r(c, mx + 2, my, 12, 15, C.navy);
        r(c, mx + 3, my + 1, 10, 6, tint);
        r(c, mx + 3, my + 8, 10, 6, tint);
        r(c, mx + 6, my + 3, 4, 2, C.cream);
        r(c, mx + 6, my + 10, 4, 2, C.cream);
    },

    /* Three buildings of different heights. */
    offices(c, mx, my, tint) {
        r(c, mx, my + 6, 5, 9, C.navy);
        r(c, mx + 6, my + 2, 5, 13, tint);
        r(c, mx + 12, my + 8, 4, 7, C.navy);
        r(c, mx + 7, my + 4, 3, 2, C.cream);
        r(c, mx + 1, my + 8, 3, 2, C.cream);
    },

    /* Two figures, one behind the other. */
    users(c, mx, my, tint) {
        disc(c, mx + 5, my + 4, 3, C.navy);
        r(c, mx + 1, my + 8, 9, 7, C.navy);
        disc(c, mx + 11, my + 5, 2, tint);
        r(c, mx + 9, my + 9, 6, 6, tint);
    },

    /* A tile with a spanner across it. */
    apps(c, mx, my, tint) {
        r(c, mx + 1, my + 1, 9, 9, tint);
        r(c, mx + 8, my + 8, 3, 6, C.navy);
        r(c, mx + 10, my + 6, 5, 5, C.navy);
        r(c, mx + 12, my + 7, 2, 2, C.cream);
    },

    /* A megaphone, with two lines of sound. */
    notices(c, mx, my, tint) {
        r(c, mx, my + 5, 4, 5, C.navy);
        r(c, mx + 4, my + 2, 5, 11, tint);
        r(c, mx + 9, my, 3, 15, tint);
        r(c, mx + 13, my + 4, 3, 1, C.navy);
        r(c, mx + 13, my + 8, 3, 1, C.navy);
    },

    /* An open book, two pages either side of a spine. */
    disclosure(c, mx, my, tint) {
        r(c, mx, my + 3, 7, 11, C.cream);
        r(c, mx + 9, my + 3, 7, 11, C.cream);
        r(c, mx + 7, my + 2, 2, 12, C.navy);
        r(c, mx, my + 3, 7, 2, tint);
        r(c, mx + 9, my + 3, 7, 2, tint);
        [7, 10].forEach((ly) => {
            r(c, mx + 1, my + ly, 5, 1, C.navy);
            r(c, mx + 10, my + ly, 5, 1, C.navy);
        });
    },

    /* A lens over a line of writing. */
    audit(c, mx, my, tint) {
        r(c, mx, my + 12, 10, 1, C.navy);
        disc(c, mx + 7, my + 5, 5, C.navy);
        disc(c, mx + 7, my + 5, 3, tint);
        r(c, mx + 10, my + 9, 2, 2, C.navy);
        r(c, mx + 12, my + 11, 3, 3, C.navy);
    },

    /* A vault door with a dial. */
    storage(c, mx, my, tint) {
        r(c, mx + 1, my + 1, 14, 14, C.navy);
        r(c, mx + 2, my + 2, 12, 12, tint);
        disc(c, mx + 8, my + 8, 4, C.navy);
        disc(c, mx + 8, my + 8, 2, C.cream);
        r(c, mx + 7, my + 2, 2, 4, C.navy);
        r(c, mx + 7, my + 11, 2, 3, C.navy);
    },
};

/* ----------------------------------------------------------------- 3. layout */

const GROUND_Y = 246; // where every sprite's feet land, in logical pixels
const WORLD_H = 300;
const EDGE = 30; // breathing room at either end of the town
const GAP = 18; // between landmarks
const HORIZON = 180; // where the ground begins, below the treeline
const SEA_DEPTH = 30; // how far up from the shoreline the water reaches

/*
 * Walk the place list into positions.
 *
 * Each place is given the width its sprite asks for, in order, and the world is
 * however wide the walk ends up being. Nothing here needs to know what any
 * landmark is — which is the point, and why adding one to World.php needs no
 * change on this side beyond a sprite.
 */
function layout(places) {
    let cursor = EDGE;
    const laid = [];

    for (const place of places) {
        const sprite = SPRITES[place.sprite];

        /* A place whose sprite has not been drawn yet is skipped rather than
           thrown over — a half-finished landmark should not take the front page
           down with it. */
        if (!sprite) continue;

        /*
         * A place may override the size its sprite declares.
         *
         * This is what lets one parameterised building serve a dozen different
         * offices: the compound's sprite is the same function every time, and
         * the width and the height of the thing it draws come from the place. A
         * landmark that is only ever itself — the fountain, the shore — declares
         * its size on the sprite and overrides nothing.
         */
        const w = place.width || sprite.w;
        const anchor = place.anchor || sprite.anchor;

        laid.push({ place, sprite, x: cursor, w, anchor });
        cursor += w + GAP;
    }

    return { laid, width: cursor - GAP + EDGE };
}

/* ------------------------------------------------------------- 4. background */

/*
 * Sky, clouds, mountains, ground.
 *
 * The three background layers move at fractions of the pan offset, which is the
 * whole of the parallax: the mountains are far away, so they slide a third as
 * far as the town in front of them.
 */
function drawSky(c, t, w, panX) {
    r(c, 0, 0, w, 174, C.sky);
    r(c, 0, 0, w, 54, C.skyHigh);
    r(c, 0, 174, w, WORLD_H - 174, C.haze);

    /* Clouds. Two rows drifting at different speeds, each cloud a few stacked
       rectangles rather than a blob. */
    const rows = [
        { y: 20, speed: 0.014, scale: 1.3, count: 5, par: 0.15 },
        { y: 52, speed: 0.008, scale: 1, count: 4, par: 0.28 },
    ];

    for (const row of rows) {
        for (let i = 0; i < row.count; i++) {
            const span = w + 260;
            const seed = noise(i * 7 + row.y);
            const raw = seed * span + t * row.speed - panX * row.par;
            const cx = ((raw % span) + span) % span - 130;
            const cy = row.y + Math.round(seed * 14);
            const cw = Math.round((26 + seed * 30) * row.scale);

            c.fillStyle = C.cloud;
            c.fillRect(Math.round(cx), cy, cw, 5);
            c.fillRect(Math.round(cx + 5), cy - 4, cw - 12, 4);
            c.fillRect(Math.round(cx + 12), cy - 7, Math.max(4, cw - 26), 3);
            c.fillRect(Math.round(cx - 4), cy + 5, cw + 8, 2);
        }
    }
}

/* The range behind the town, and the waterfall down its face. Two ridges, the
   far one paler, drawn as stepped triangles — pixel mountains, not polygons. */
function drawMountains(c, t, w, panX) {
    const ridge = (offset, baseY, height, span, fill, cap, par) => {
        const shift = -panX * par + offset;
        for (let peak = -1; peak * span + shift < w + span; peak++) {
            /* Jittered off the regular interval. Without this the peaks sit at
               exactly `span` apart and the range reads as wallpaper — the eye
               finds the repeat immediately, however much the heights vary. */
            const px = peak * span + shift + (noise(peak * 8.3) - 0.5) * span * 0.55;
            const h = height * (0.62 + noise(peak * 3.7) * 0.72);
            const steps = Math.round(h / 4);

            for (let s = 0; s < steps; s++) {
                const half = Math.round(((steps - s) / steps) * (span / 2));
                const yy = baseY - s * 4;
                r(c, px - half, yy, half * 2, 4, fill);
                /* Sunlit left flank. */
                r(c, px - half, yy, Math.max(2, Math.round(half * 0.45)), 4, shade(fill, 16));
            }

            /* Snowless caps — this is Mindoro. A pale rock crown instead. */
            if (cap && noise(peak * 5.1) > 0.45) {
                const capH = Math.round(h * 0.16);
                for (let s = 0; s < capH / 4; s++) {
                    const half = Math.round(((capH / 4 - s) / (capH / 4)) * 9);
                    r(c, px - half, baseY - h + s * 4, half * 2, 4, cap);
                }
            }
        }
    };

    /*
     * Two ridges, and the far one is the pale one.
     *
     * Aerial perspective, which is the only depth cue available without any
     * blur: distance washes contrast out towards the colour of the sky. Get it
     * backwards — a dark ridge behind a light one — and the range reads as flat
     * cardboard however carefully the peaks are drawn.
     */
    ridge(40, 176, 88, 210, '#a9bcc6', '#c6d5da', 0.18);
    ridge(-90, 178, 58, 150, '#8b9ea9', '#a6b7c0', 0.3);

    /* The waterfall, on the near ridge, always in the same place because it is
       drawn at a fixed offset within that layer. */
    const wx = Math.round(-panX * 0.3 + 66);
    if (wx > -30 && wx < w + 30) {
        r(c, wx, 132, 7, 46, C.rockDark);
        for (let i = 0; i < 6; i++) {
            const p = (t / 900 + i / 6) % 1;
            r(c, wx + 1, 132 + p * 44, 5, 5, i % 2 ? C.foam : '#c9e6ee');
        }
        r(c, wx - 3, 176, 13, 3, C.foam);
    }

    /*
     * Treeline along the foot of the range, hiding the join.
     *
     * Deliberately not capped with a solid band. An unbroken 4px rule across the
     * full width — which is what this was — reads as a painted stripe rather
     * than as trees, and is the single most obvious thing in a drawn landscape.
     * The canopy is closed instead by overlapping crowns at varying heights, and
     * the last two rows are dithered so the tops break up against the sky.
     */
    const rows = [
        { step: 11, w: 12, base: 180, lo: 9, hi: 9, par: 0.36, tone: shade(C.leaf1, -20) },
        { step: 9, w: 10, base: 182, lo: 7, hi: 11, par: 0.42, tone: C.leaf1 },
    ];

    for (const row of rows) {
        for (let i = -1; i < Math.ceil(w / row.step) + 2; i++) {
            const tx = Math.round(i * row.step - ((panX * row.par) % row.step));
            const th = row.lo + Math.round(noise(i * 2.3 + row.base) * row.hi);

            r(c, tx, row.base - th, row.w, th, i % 3 === 0 ? shade(row.tone, -12) : row.tone);
            /* Two rows of crown, half-width, so the top edge is not a flat cut. */
            r(c, tx + 2, row.base - th - 2, row.w - 5, 2, shade(row.tone, 10));
        }
    }
}

/*
 * The ground the town stands on.
 *
 * Painted in bands, one per landmark, from the `ground` each place declares —
 * grass, plaza or sand. The bands are stitched at the midpoint of each gap so
 * there is no seam and no landmark ends up half on grass.
 */
function drawGround(c, laid, w, panX, worldW, t) {
    /* Base fill, so any sliver the bands do not claim is still ground rather
       than the sky showing through the floor. */
    r(c, 0, HORIZON, w, WORLD_H - HORIZON, C.grass);

    /*
     * One band per landmark, meeting its neighbours halfway across the gap
     * between them. Stitching at the midpoint rather than at a sprite's edge is
     * what keeps a landmark from standing with one foot on grass and the other
     * on sand, and means no band boundary is ever visible as a straight line
     * behind something.
     */
    laid.forEach((item, i) => {
        const prev = laid[i - 1];
        const next = laid[i + 1];
        const from = prev ? (prev.x + prev.w + item.x) / 2 : 0;
        const to = next ? (item.x + item.w + next.x) / 2 : worldW;
        const sx = Math.round(from - panX);
        const sw = Math.round(to - from);

        if (sx + sw < -26 || sx > w + 4) return;

        /* Clipped, so the dither and the paving joints below can be written as
           simple loops over whole 6px cells without any of them spilling into
           the neighbouring band. */
        c.save();
        c.beginPath();
        c.rect(sx, HORIZON, sw, WORLD_H - HORIZON);
        c.clip();

        const kind = item.place.ground;

        if (kind === 'sand') {
            drawSea(c, sx, sw, t, panX);
            r(c, sx, GROUND_Y - SEA_DEPTH + 3, sw, WORLD_H - GROUND_Y + SEA_DEPTH - 3, C.sand);
            dither(c, sx, GROUND_Y - SEA_DEPTH + 3, sw, C.sandAlt);
        } else if (kind === 'plaza') {
            r(c, sx, HORIZON, sw, WORLD_H - HORIZON, C.plaza);
            dither(c, sx, HORIZON, sw, C.plazaAlt);

            /* Paving joints. Anchored to the world rather than the viewport, so
               they stay put on the ground while the town is dragged past. */
            const joint = shade(C.plaza, -16);
            const worldFrom = Math.ceil(from / 22) * 22;
            for (let jx = worldFrom; jx < to; jx += 22) {
                r(c, Math.round(jx - panX), HORIZON, 1, WORLD_H - HORIZON, joint);
            }
            for (let jy = HORIZON + 14; jy < WORLD_H; jy += 18) {
                r(c, sx, jy, sw, 1, joint);
            }
        } else {
            r(c, sx, HORIZON, sw, WORLD_H - HORIZON, C.grass);
            dither(c, sx, HORIZON, sw, C.grassAlt);

            /* Tufts, scattered from the band's own world position so the same
               blades stay in the same places for the life of the page. */
            for (let g = 0; g < sw / 7; g++) {
                const gx = Math.round(from + noise(g + Math.round(from)) * sw - panX);
                const gyy = GROUND_Y + 4 + Math.round(noise(g * 3 + Math.round(from)) * 40);
                if (gyy > WORLD_H - 3) continue;
                r(c, gx, gyy, 1, 3, C.grassDark);
                r(c, gx + 2, gyy + 1, 1, 2, C.grassDark);
            }
        }

        /*
         * Feather the seam with the band to the left.
         *
         * Two flat colours meeting on a straight vertical line is the one thing
         * that gives away that the ground is a series of rectangles. Scattering
         * cells of the neighbour's tone across the join — denser near the seam,
         * thinning out over about twenty pixels — makes it read as one surface
         * changing rather than two surfaces abutting. Still inside the clip, so
         * only this band's half of the seam is touched; the band to the left
         * feathers its own half when its turn comes.
         */
        if (prev && prev.place.ground !== kind) {
            const [nearTone] = bandFor(prev.place.ground);
            const seam = Math.round(from - panX);

            /* Where either side is shore, the feather starts below the waterline.
               Scattering sand across the sea, or plaza paving into it, would be
               a worse seam than the one being fixed. */
            const wet = kind === 'sand' || prev.place.ground === 'sand';
            const featherTop = wet ? GROUND_Y - SEA_DEPTH + 3 : HORIZON;

            for (let step = 0; step < 22; step += 2) {
                for (let yy = featherTop; yy < WORLD_H; yy += 2) {
                    if (noise(step * 31 + yy * 7 + Math.round(from)) > step / 22) {
                        r(c, seam + step, yy, 2, 2, nearTone);
                    }
                }
            }
        }

        c.restore();
    });

    /* The path along the front of the town, tying every band together and
       giving the eye one continuous line to follow from end to end. */
    r(c, 0, WORLD_H - 16, w, 16, C.road);
    r(c, 0, WORLD_H - 16, w, 2, shade(C.road, -18));
    const dashFrom = Math.ceil(panX / 24) * 24;
    for (let dx = dashFrom; dx - panX < w; dx += 24) {
        r(c, Math.round(dx - panX), WORLD_H - 9, 12, 2, shade(C.road, 22));
    }
}

/* The two tones a kind of ground is painted in: the base, and the one the
   checker below scatters over it. */
function bandFor(kind) {
    if (kind === 'plaza') return [C.plaza, C.plazaAlt];
    if (kind === 'sand') return [C.sand, C.sandAlt];
    return [C.grass, C.grassAlt];
}

/* A 6px checker of the alternate tone. What stops a flat band reading as a flat
   band without costing a texture to load. */
function dither(c, sx, top, sw, tone) {
    const startX = Math.floor(sx / 6) * 6;

    for (let yy = top; yy < WORLD_H; yy += 6) {
        for (let xx = startX; xx < sx + sw; xx += 6) {
            if (((xx + yy) / 6) % 2 === 0) r(c, xx, yy, 6, 6, tone);
        }
    }
}

/*
 * The water, from the horizon down to the shoreline.
 *
 * Rows of alternating blue whose phase shifts with time, which is the cheapest
 * convincing swell there is: no row moves, but the pattern of light and dark
 * rows travels through them. Glints are confined to the far half so the water
 * reads as receding rather than as a flat blue rectangle with sparkles.
 */
function drawSea(c, sx, sw, t, panX) {
    const seaTop = GROUND_Y - SEA_DEPTH;

    r(c, sx, HORIZON, sw, seaTop - HORIZON, C.seaDeep);

    /* Haze at the horizon. The same aerial perspective the mountains use: water
       at the far edge is closer to the colour of the sky than to the colour of
       water, and without this the sea meets the treeline on a hard dark line. */
    r(c, sx, HORIZON, sw, 3, '#86b3c6');
    r(c, sx, HORIZON + 3, sw, 3, '#6ea0ba');

    for (let row = 0; row < SEA_DEPTH + 3; row += 2) {
        const yy = seaTop + row;
        const p = Math.sin(t / 900 + row / 4);

        r(c, sx, yy, sw, 2, p > 0.2 ? C.sea : C.seaDeep);

        if (row < SEA_DEPTH / 2 && p > 0.7) {
            for (let i = 0; i < Math.ceil(sw / 60); i++) {
                const gx = sx + ((i * 53 + row * 9 + Math.floor(t / 60) - Math.round(panX)) % Math.max(1, sw));
                r(c, gx, yy, 4, 1, 'rgba(223,240,244,.5)');
            }
        }
    }

    /* A paler shelf just before the sand, where the water runs shallow. */
    r(c, sx, seaTop - 5, sw, 4, '#4f9ab8');
}

/* Birds, high up and crossing slowly. Two-pixel wings, flapping. */
function drawBirds(c, t, w, panX) {
    [
        [0.02, 26, 0.1],
        [0.03, 40, 0.14],
        [0.015, 18, 0.08],
    ].forEach(([speed, y0, par], i) => {
        const span = w + 80;
        const raw = t * speed + i * 260 - panX * par;
        const bx = ((raw % span) + span) % span - 40;
        const by = y0 + Math.sin(t / 600 + i * 2) * 3;
        const flap = Math.sin(t / 130 + i * 3) > 0 ? -2 : 1;

        c.fillStyle = 'rgba(40,46,58,.55)';
        c.fillRect(Math.round(bx - 4), Math.round(by + flap), 3, 1);
        c.fillRect(Math.round(bx - 1), Math.round(by), 2, 1);
        c.fillRect(Math.round(bx + 1), Math.round(by + flap), 3, 1);
    });
}

/* ------------------------------------------------------------------- 5. boot */

function boot() {
    /* Announced first, so the CSS can swap the fallback list for the stage. It
       being the first statement is the contract: anything that throws after
       this point leaves a visible but inert town, and anything that throws
       before it leaves the plain list of links, which still works. */
    root.dataset.world = 'on';

    const data = JSON.parse(document.getElementById('worldData').textContent);
    const places = data.places;

    const canvas = document.getElementById('worldCanvas');
    const c = canvas.getContext('2d');
    const labelsEl = document.getElementById('worldLabels');
    const tagEl = document.getElementById('worldTag');
    const hintEl = document.getElementById('worldHint');
    const keynavEl = document.getElementById('worldKeynav');

    /* An offscreen twin of the canvas, painted with one flat colour per
       landmark and never shown. A click reads the pixel under the pointer and
       gets an index back — exact hit testing against irregular shapes for the
       cost of drawing the frame twice, and no bounding boxes to keep in step
       with the art. */
    const pickCanvas = document.createElement('canvas');
    const pickCtx = pickCanvas.getContext('2d', { willReadFrequently: true });

    const { laid, width: worldW } = layout(places);

    let scale = 3;
    let viewW = 0; // logical pixels visible across the stage
    let stageH = 0; // the stage's height in CSS pixels
    let canvasTop = 0; // where the canvas's top edge sits relative to the stage
    let panX = 0; // logical pixels scrolled from the left edge
    let hovered = -1;
    let motion = readMotion();
    let started = performance.now();
    let elapsed = 0;

    /* ------------------------------------------------------- 6. measurement */

    /*
     * Fit the world to the stage.
     *
     * The backing store is exactly one pixel per logical pixel and the scaling
     * is left entirely to CSS, which — with image-rendering:pixelated and an
     * integer scale — turns each logical pixel into a hard square block. The
     * obvious alternative, sizing the canvas by devicePixelRatio, is actively
     * worse here: at a ratio of 1.25 you get 1.25 samples per logical pixel
     * upscaled by a further 3.2, so a "4px" block lands as three pixels in one
     * place and four in the next. Pixel art with inconsistent pixels is just
     * blurry art.
     *
     * The scale comes from the stage's height, and the world is allowed to be
     * cropped rather than shrunk to fit. LOOK is the promise: however short the
     * stage, at least this many logical rows stay visible, counted up from the
     * bottom — so the ground, the landmarks and their feet are never what gets
     * cut. What goes first is empty sky, which nobody came for.
     */
    const LOOK = 200;

    function fit() {
        const rect = stage.getBoundingClientRect();

        scale = Math.max(1, Math.floor(rect.height / LOOK));
        viewW = Math.ceil(rect.width / scale);
        stageH = rect.height;

        canvas.width = viewW;
        canvas.height = WORLD_H;
        canvas.style.width = viewW * scale + 'px';
        canvas.style.height = WORLD_H * scale + 'px';

        pickCanvas.width = viewW;
        pickCanvas.height = WORLD_H;

        c.imageSmoothingEnabled = false;
        pickCtx.imageSmoothingEnabled = false;

        /* The canvas is bottom-anchored by CSS, so when it is taller than the
           stage its top edge sits above it — a negative offset. Everything
           positioned in CSS pixels over the canvas has to add this, or the
           labels drift down by exactly the amount that was cropped. */
        canvasTop = stageH - WORLD_H * scale;

        clampPan();
        drawLabels();
    }

    /* Logical row -> where it lands in the stage's own coordinates. */
    function screenY(logicalY) {
        return canvasTop + logicalY * scale;
    }

    function maxPan() {
        return Math.max(0, worldW - viewW);
    }

    function clampPan() {
        panX = Math.min(maxPan(), Math.max(0, panX));
    }

    /* ------------------------------------------------------------ 7. drawing */

    function frame(now) {
        if (motion) elapsed = now - started;

        c.clearRect(0, 0, viewW, WORLD_H);
        pickCtx.clearRect(0, 0, viewW, WORLD_H);

        drawSky(c, elapsed, viewW, panX);
        drawMountains(c, elapsed, viewW, panX);
        drawBirds(c, elapsed, viewW, panX);
        drawGround(c, laid, viewW, panX, worldW, elapsed);

        laid.forEach((item, i) => {
            const sx = item.x - panX;

            /* Nothing offscreen is drawn — at 1x on a phone that is most of the
               town, and the pick pass would otherwise cost as much as the
               visible one. */
            if (sx + item.w < -8 || sx > viewW + 8) return;

            const lift = i === hovered ? -2 : 0;

            c.save();
            c.translate(0, lift);
            item.sprite.draw(c, sx, GROUND_Y, elapsed, item.place);
            c.restore();

            /* The same shape again, flat, into the pick canvas. Index + 1
               because 0 is "nothing here". */
            pickCtx.save();
            pickCtx.translate(0, lift);
            paintFlat(pickCtx, item, sx, i + 1);
            pickCtx.restore();
        });

        requestAnimationFrame(frame);
    }

    /*
     * The clickable shape of a landmark.
     *
     * Deliberately a rectangle rather than the sprite's exact silhouette. The
     * sprites are drawn with dozens of calls and re-running them into a flat
     * colour would make every sky-coloured gap between two palm fronds a hole
     * in the target. A generous box over the whole landmark is what somebody
     * aiming at "the treehouse" actually means.
     */
    function paintFlat(ctx, item, sx, id) {
        ctx.fillStyle = `rgb(${id},0,0)`;
        ctx.fillRect(Math.round(sx), GROUND_Y - item.anchor, item.w, item.anchor + 8);
    }

    /* Screen point -> landmark index, or -1. The canvas rect is used rather than
       the stage's because it already accounts for the crop: when the world is
       taller than the stage, rect.top is negative, and dividing through by the
       scale lands on the right logical row without any correction. */
    function hitTest(clientX, clientY) {
        const rect = canvas.getBoundingClientRect();
        const lx = Math.floor((clientX - rect.left) / scale);
        const ly = Math.floor((clientY - rect.top) / scale);

        if (lx < 0 || ly < 0 || lx >= viewW || ly >= WORLD_H) return -1;

        const d = pickCtx.getImageData(lx, ly, 1, 1).data;

        return d[3] > 200 && d[1] === 0 && d[2] === 0 && d[0] > 0 ? d[0] - 1 : -1;
    }

    /* --------------------------------------------------------- 8. the labels */

    /*
     * Names, in HTML over the canvas.
     *
     * Repositioned on every pan and resize rather than every frame: the town
     * only moves when somebody moves it, and a DOM write per landmark per frame
     * is the one thing that would make this page stutter.
     */
    function drawLabels() {
        labelsEl.textContent = '';

        laid.forEach((item) => {
            const sx = item.x - panX;
            if (sx + item.w < 0 || sx > viewW) return;

            const el = document.createElement('div');
            el.className = 'world-label';
            el.textContent = item.place.name;

            if (item.place.badge) {
                const b = document.createElement('span');
                b.className = 'badge';
                b.textContent = item.place.badge;
                el.append(document.createElement('br'), b);
            }

            el.style.left = (sx + item.w / 2) * scale + 'px';
            /* Never above the stage. On a short viewport the world is cropped
               from the top, and the tallest landmark's name is the first thing
               that would go over the edge — better slightly overlapping its own
               canopy than not on the page at all. */
            el.style.top = Math.max(16, screenY(GROUND_Y - item.anchor) - 6) + 'px';
            labelsEl.appendChild(el);
        });
    }

    function showTag(i) {
        const item = laid[i];
        if (!item) {
            tagEl.dataset.show = 'false';
            return;
        }

        tagEl.textContent = item.place.name;
        const b = document.createElement('b');
        b.textContent = item.place.blurb;
        tagEl.appendChild(b);

        tagEl.style.left = (item.x + item.w / 2 - panX) * scale + 'px';
        /* The tag is anchored by its bottom edge, so this floor is its own
           height plus the label it has to clear — without it, hovering the
           tallest landmark on a short viewport puts the tag off the top of the
           stage, which is the one place it is no use. */
        tagEl.style.top = Math.max(62, screenY(GROUND_Y - item.anchor) - 30) + 'px';
        tagEl.dataset.show = 'true';
    }

    /* ------------------------------------------------------ 9. going places */

    /*
     * What a click on a landmark does.
     *
     * A link leaves, behind the cloud wipe. Scenery has nowhere to go, so the
     * guide says its line instead — which is the difference between decoration
     * that ignores you and decoration that answers.
     */
    function visit(place) {
        if (place.kind === 'link' && place.url) {
            npc.say(place.say, { hold: true });
            wipe(() => {
                window.location.href = place.url;
            });
            return;
        }

        npc.say(place.say);
    }

    /* --------------------------------------------------------- 10. gestures */

    /*
     * One gesture at a time, tracked without reference to which pointer it is.
     *
     * The obvious version of this compares ev.pointerId against the id recorded
     * on pointerdown, and it is a trap: a pointerup whose id does not match is
     * silently dropped, which leaves a drag stuck open and swallows the click
     * that should have followed it. Since only one gesture is ever in flight —
     * a second finger is ignored outright, below — the id buys nothing and
     * costs a whole class of stuck states.
     */
    let drag = null;

    canvas.addEventListener('pointerdown', (ev) => {
        if (drag) return;

        drag = {
            startX: ev.clientX,
            startPan: panX,
            moved: false,
        };

        try {
            canvas.setPointerCapture(ev.pointerId);
        } catch {
            /* Safari on an old iPad. Dragging still works through the events
               below; only capture outside the element is lost. */
        }
    });

    canvas.addEventListener('pointermove', (ev) => {
        if (drag) {
            const dx = (ev.clientX - drag.startX) / scale;

            /* A few pixels of slop before it counts as a drag, so a slightly
               unsteady tap still opens the landmark under it. */
            if (Math.abs(dx) > 3) {
                drag.moved = true;
                canvas.classList.add('dragging');
                panX = drag.startPan - dx;
                clampPan();
                drawLabels();
                tagEl.dataset.show = 'false';
                hovered = -1;
            }
            return;
        }

        const i = hitTest(ev.clientX, ev.clientY);
        if (i !== hovered) {
            hovered = i;
            showTag(i);
        }
        canvas.style.cursor = i >= 0 ? 'pointer' : 'grab';
    });

    const endDrag = (ev) => {
        if (!drag) return;

        const wasDrag = drag.moved;
        drag = null;
        canvas.classList.remove('dragging');

        /* A press that never moved far enough to count as a drag is a click on
           whatever is under it. */
        if (!wasDrag) {
            const i = hitTest(ev.clientX, ev.clientY);
            if (i >= 0) visit(laid[i].place);
        }
    };

    canvas.addEventListener('pointerup', endDrag);
    canvas.addEventListener('pointercancel', () => {
        drag = null;
        canvas.classList.remove('dragging');
    });

    canvas.addEventListener('pointerleave', () => {
        hovered = -1;
        tagEl.dataset.show = 'false';
    });

    /* A horizontal wheel or trackpad swipe pans the town. A vertical one is
       left to the page, so scrolling down to the notices still works with the
       pointer over the world. */
    stage.addEventListener(
        'wheel',
        (ev) => {
            if (Math.abs(ev.deltaX) <= Math.abs(ev.deltaY)) return;
            ev.preventDefault();
            panX += ev.deltaX / scale;
            clampPan();
            drawLabels();
        },
        { passive: false },
    );

    /* ------------------------------------------------- 11. keyboard route */

    /*
     * Every landmark, as a focusable control.
     *
     * A canvas is not reachable by keyboard and a drawn town is not readable by
     * a screen reader, so the same list is rendered here as real controls: an
     * anchor where the place is a link, a button where it is scenery. Focusing
     * one pans the town to it, so the drawn view and the keyboard's idea of
     * where you are never disagree.
     */
    laid.forEach((item, i) => {
        const place = item.place;
        const el = document.createElement(place.kind === 'link' ? 'a' : 'button');

        if (place.kind === 'link') {
            el.href = place.url;
        } else {
            el.type = 'button';
        }

        el.textContent = place.name + ' — ' + place.blurb;

        el.addEventListener('focus', () => {
            panX = item.x + item.w / 2 - viewW / 2;
            clampPan();
            drawLabels();
            hovered = i;
            showTag(i);
        });

        el.addEventListener('click', (ev) => {
            ev.preventDefault();
            visit(place);
        });

        keynavEl.appendChild(el);
    });

    document.addEventListener('keydown', (ev) => {
        if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
        /* Only when nothing is being typed into, and only while the world is
           actually on screen — the arrow keys belong to the page otherwise, and
           somebody reading the notices below should be able to use them there. */
        if (ev.target instanceof Element && ev.target.closest('input, textarea, select')) return;
        if (stage.getBoundingClientRect().bottom < 40) return;

        ev.preventDefault();
        panX += ev.key === 'ArrowRight' ? 60 : -60;
        clampPan();
        drawLabels();
        tagEl.dataset.show = 'false';
    });

    /* ------------------------------------------------------------ 12. motion */

    function readMotion() {
        const saved = localStorage.getItem('world:motion');
        if (saved !== null) return saved === 'on';
        return !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    function applyMotion() {
        root.dataset.motion = motion ? 'on' : 'off';
        /* Re-baselining the clock means switching motion back on continues from
           where the town froze rather than jumping forward by however long it
           was still. */
        if (motion) started = performance.now() - elapsed;
    }

    const motionToggle = document.getElementById('worldMotion');
    motionToggle.checked = motion;
    motionToggle.addEventListener('change', (ev) => {
        motion = ev.target.checked;
        localStorage.setItem('world:motion', motion ? 'on' : 'off');
        applyMotion();
    });

    const gearBtn = document.getElementById('worldGear');
    const gearPop = document.getElementById('worldPop');
    gearBtn.addEventListener('click', () => {
        gearPop.hidden = !gearPop.hidden;
    });
    document.addEventListener('click', (ev) => {
        if (gearPop.hidden) return;
        if (gearBtn.contains(ev.target) || gearPop.contains(ev.target)) return;
        gearPop.hidden = true;
    });

    applyMotion();

    /* --------------------------------------------------------- 13. cloud wipe */

    const wipeEl = document.getElementById('worldWipe');

    function wipe(then) {
        if (!motion) {
            then();
            return;
        }

        wipeEl.classList.add('active');
        requestAnimationFrame(() => wipeEl.classList.add('cover'));
        setTimeout(then, 420);
    }

    /* ------------------------------------------------------------- 14. guide */

    const npc = createGuide({
        el: document.getElementById('worldNpc'),
        bubble: document.getElementById('worldBubble'),
        textEl: document.getElementById('worldNpcText'),
        intro: data.intro,
        tips: data.tips,
        motion: () => motion,
    });

    /* --------------------------------------------------------- 15. splash */

    startSplash(data.title, data.subtitle, () => npc.begin(), () => motion);

    /* ------------------------------------------------------------ 16. go */

    drawGuideSprite(document.getElementById('worldNpcCanvas'));
    drawGearIcon(document.getElementById('worldGearIcon'));

    /* Optional: the public page has a corner button, and a screen that has
       nowhere useful to put one simply omits it. */
    const cornerIcon = document.getElementById('worldTrackIcon');
    if (cornerIcon) drawCornerIcon(cornerIcon);

    /* The hint has to be re-decided on every resize, not written once: whether
       there is anything off to the side depends on how wide the stage is, and a
       phone turned landscape can go from six landmarks hidden to none. */
    function updateHint() {
        hintEl.textContent =
            maxPan() > 0
                ? 'Drag sideways to walk through town — click a landmark to go in'
                : 'Click a landmark to go in';
    }

    new ResizeObserver(() => {
        fit();
        updateHint();
    }).observe(stage);

    fit();
    updateHint();
    requestAnimationFrame(frame);
}

/* ------------------------------------------------------------- 17. the guide */

/*
 * Mayor Mike.
 *
 * Types his lines rather than showing them, which is the difference between a
 * tooltip and somebody talking. The intro runs once per browser session for the
 * same reason the splash does — it is a greeting, and being greeted five times
 * is being nagged.
 */
function createGuide({ el, bubble, textEl, intro, tips, motion }) {
    let timer = null;
    let mode = 'idle';
    let index = 0;

    function type(text, opts = {}) {
        clearTimeout(timer);
        bubble.classList.add('show');
        textEl.textContent = '';

        const done = () => {
            if (opts.hold) return;
            timer = setTimeout(() => bubble.classList.remove('show'), 3400);
            if (opts.then) opts.then();
        };

        if (!motion()) {
            textEl.textContent = text;
            timer = setTimeout(done, 2400);
            return;
        }

        el.classList.add('talking');
        let i = 0;

        (function step() {
            textEl.textContent += text.charAt(i);
            i += 1;

            if (i < text.length) {
                timer = setTimeout(step, 24);
                return;
            }

            el.classList.remove('talking');
            timer = setTimeout(done, 2400);
        })();
    }

    function introStep() {
        if (index < intro.length) {
            const line = intro[index];
            index += 1;
            type(line, { then: introStep });
            return;
        }

        mode = 'idle';
        type('Click me any time for a tip.');
    }

    el.addEventListener('click', () => {
        if (mode === 'intro') {
            clearTimeout(timer);
            el.classList.remove('talking');
            index = intro.length;
            mode = 'idle';
            type('Click me any time for a tip.');
            return;
        }

        type(tips[Math.floor(Math.random() * tips.length)]);
    });

    return {
        begin() {
            el.classList.add('ready');

            /* Greeted already this session: he is there, he just does not start
               talking again. */
            if (sessionStorage.getItem('world:greeted')) {
                mode = 'idle';
                return;
            }

            sessionStorage.setItem('world:greeted', '1');
            mode = 'intro';
            setTimeout(introStep, 250);
        },
        say(text, opts) {
            mode = 'idle';
            if (text) type(text, opts);
        },
    };
}

/* -------------------------------------------------------------- 18. splash */

function startSplash(title, subtitle, onDone, motion) {
    const el = document.getElementById('worldSplash');
    const bar = document.getElementById('worldSplashBar');

    /* Straight past it on a return visit. The town is what they came back for. */
    if (sessionStorage.getItem('world:splashed')) {
        el.remove();
        onDone();
        return;
    }

    const letters = (node, text, delay) => {
        node.textContent = '';
        text.split('').forEach((ch, i) => {
            const s = document.createElement('span');
            s.textContent = ch === ' ' ? ' ' : ch;
            s.style.animationDelay = delay + i * 0.045 + 's';
            node.appendChild(s);
        });
    };

    letters(document.getElementById('worldSplashTitle'), title, 0.15);
    letters(document.getElementById('worldSplashSub'), subtitle, 0.15 + title.length * 0.045 + 0.1);

    drawSeal(document.getElementById('worldSplashSeal'));

    let finished = false;

    function finish() {
        if (finished) return;
        finished = true;
        sessionStorage.setItem('world:splashed', '1');
        el.classList.add('done');
        setTimeout(() => el.remove(), 600);
        onDone();
    }

    el.addEventListener('click', finish);

    let pct = 0;
    const tick = setInterval(() => {
        pct += motion() ? 4 : 30;
        bar.style.width = Math.min(100, pct) + '%';

        if (pct >= 100) {
            clearInterval(tick);
            setTimeout(finish, 250);
        }
    }, 60);
}

/* --------------------------------------------------------------- 19. icons */

/* The seal on the splash: a rust square with an amber cross, the same shape as
   the one on the hall's signage band, drawn large. */
function drawSeal(canvas) {
    const c = canvas.getContext('2d');
    c.imageSmoothingEnabled = false;
    r(c, 0, 0, 32, 32, C.ink);
    r(c, 2, 2, 28, 28, C.rust);
    r(c, 13, 4, 6, 24, C.amber);
    r(c, 4, 13, 24, 6, C.amber);
    disc(c, 16, 16, 4, C.cream);
    disc(c, 16, 16, 2, C.navy);
}

function drawGearIcon(canvas) {
    const c = canvas.getContext('2d');
    c.imageSmoothingEnabled = false;
    c.clearRect(0, 0, 16, 16);
    disc(c, 8, 8, 6, '#7e8fa8');
    for (let i = 0; i < 8; i++) {
        const a = (i * Math.PI) / 4;
        r(c, 8 + Math.cos(a) * 7 - 1, 8 + Math.sin(a) * 7 - 1, 2, 2, C.ink);
    }
    disc(c, 8, 8, 2, C.amber);
}

/*
 * The left-hand corner button's icon.
 *
 * Which one is decided by the markup, not here: the Blade component sets
 * data-icon, because what that corner leads to is a property of the screen the
 * world is embedded in — notices on the public page, the dashboard in the staff
 * compound — and the renderer has no business knowing either.
 */
function drawCornerIcon(canvas) {
    const c = canvas.getContext('2d');
    c.imageSmoothingEnabled = false;
    c.clearRect(0, 0, 16, 16);

    if (canvas.dataset.icon === 'home') {
        /* A doorway with a step, and an arrow going in. */
        r(c, 2, 2, 12, 13, C.navy);
        r(c, 4, 4, 8, 11, C.cream);
        r(c, 1, 14, 14, 2, C.navy);
        r(c, 6, 8, 6, 2, C.navy);
        r(c, 8, 6, 2, 2, C.navy);
        r(c, 8, 10, 2, 2, C.navy);
        r(c, 10, 7, 2, 4, C.navy);
        return;
    }

    /* An envelope: the way to something waiting to be read. */
    r(c, 1, 3, 14, 11, C.navy);
    r(c, 2, 4, 12, 9, C.cream);
    /* The flap, as two stepped diagonals meeting in the middle. */
    for (let i = 0; i < 6; i++) {
        r(c, 2 + i, 4 + i, 2, 1, C.navy);
        r(c, 13 - i, 4 + i, 2, 1, C.navy);
    }
}

/*
 * Mayor Mike himself, in a barong.
 *
 * Drawn rather than shipped as a PNG so he costs nothing to load, scales to any
 * pixel ratio, and can be recoloured by changing one entry in the palette.
 * 48 x 72 logical pixels, feet at the bottom edge — the CSS animates him about
 * that edge, so anything drawn below it would look like it was floating.
 */
function drawGuideSprite(canvas) {
    const c = canvas.getContext('2d');
    c.imageSmoothingEnabled = false;
    c.clearRect(0, 0, 48, 72);

    /* Shoes and slacks. */
    r(c, 14, 68, 9, 4, C.ink);
    r(c, 25, 68, 9, 4, C.ink);
    r(c, 15, 50, 8, 18, C.slacks);
    r(c, 25, 50, 8, 18, C.slacks);
    r(c, 15, 50, 18, 3, shade(C.slacks, -16));

    /* The barong: cream, long-sleeved, with the vertical embroidery panel it
       is known for and a hem that sits below the belt. */
    r(c, 12, 28, 24, 24, C.barong);
    r(c, 12, 28, 24, 3, shade(C.barong, -14));
    r(c, 12, 49, 24, 3, shade(C.barong, -20));
    r(c, 8, 30, 5, 18, C.barong);
    r(c, 35, 30, 5, 18, C.barong);
    r(c, 8, 46, 5, 3, shade(C.barong, -16));
    r(c, 35, 46, 5, 3, shade(C.barong, -16));
    /* Embroidery: two columns of small marks either side of the buttons. */
    for (let i = 0; i < 5; i++) {
        r(c, 19, 32 + i * 4, 2, 2, '#ded4bd');
        r(c, 27, 32 + i * 4, 2, 2, '#ded4bd');
        r(c, 23, 33 + i * 4, 1, 1, C.stoneDark);
    }
    /* Collar. */
    r(c, 18, 26, 12, 3, shade(C.barong, -10));
    r(c, 20, 29, 3, 4, C.skinDark);
    r(c, 25, 29, 3, 4, C.skinDark);

    /* Hands. */
    r(c, 8, 48, 5, 5, C.skin);
    r(c, 35, 48, 5, 5, C.skin);

    /* Head. */
    r(c, 17, 26, 14, 3, C.skinDark);
    r(c, 16, 12, 16, 15, C.skin);
    r(c, 16, 12, 16, 3, shade(C.skin, 12));
    r(c, 15, 16, 2, 6, C.skin);
    r(c, 31, 16, 2, 6, C.skin);

    /* Hair, with a side part. */
    r(c, 15, 8, 18, 6, C.hair);
    r(c, 14, 11, 3, 6, C.hair);
    r(c, 31, 11, 3, 6, C.hair);
    r(c, 22, 8, 8, 3, shade(C.hair, 16));

    /* Face. Two dark pixels and a short line is a face at this size; anything
       more becomes a smudge. */
    r(c, 20, 18, 2, 2, C.ink);
    r(c, 26, 18, 2, 2, C.ink);
    r(c, 21, 22, 6, 1, C.skinDark);
    r(c, 22, 23, 4, 1, C.skinDark);

    /* A rolled document under one arm — he is, after all, the mayor. */
    r(c, 36, 40, 4, 14, C.cream);
    r(c, 36, 40, 4, 2, shade(C.cream, -18));
    r(c, 36, 46, 4, 1, C.rust);
}

/* ----------------------------------------------------------------- 20. start */

/*
 * Last, not first.
 *
 * boot() reads the palette and the sprite table, both of which are `const` at
 * module scope — calling it above their declarations would put them in the
 * temporal dead zone and throw before a single pixel was drawn. No stage on the
 * page means this is not the welcome screen, in which case the file does
 * nothing rather than complaining about it.
 */
if (stage) {
    boot();
}
