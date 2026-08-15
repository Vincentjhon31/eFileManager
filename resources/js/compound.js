/*
|------------------------------------------------------------------------------
| The compound, drawn
|------------------------------------------------------------------------------
|
| The other half of App\Support\Compound. That class decides what the buildings
| are, where they stand and what is behind their doors; this file decides what
| they look like. Nothing here knows a URL or a route name.
|
| Sibling of world.js, not a copy of it. Both draw a place you can walk through
| in the same paint — see ./world/paint.js — and share every part of the
| interface that is not the drawing: the guide, the splash, the wipe, the
| landmark panel, all in ./world/chrome.js. What differs is the projection, and
| that is what lives here.
|
| The projection is a two-to-one dimetric one, which everybody calls isometric:
|
|     iso(x, y) = [(x - y) * TW/2, (x + y) * TH/2]
|
| A grid cell becomes a diamond twice as wide as it is tall, and a building is
| that diamond extruded upward — four flat polygons, two side faces and a roof
| with a lip, each a single arithmetic shade of the two colours PHP handed over.
| There is no light source, no matrix and no perspective divide. There is also
| no z-index: everything is one canvas, and what is in front of what is decided
| by sorting on gx + gy + (w + h) / 2, which is the painter's algorithm and is
| the whole of the depth handling.
|
| Those coordinates are world coordinates, with no screen offset in them at all.
| The camera is a whole-pixel translate on the context and an integer zoom on
| the canvas, which means every sprite draws in the one coordinate system it was
| written in and none of them had to learn about panning. The canvas is exactly
| the size of what can be seen, so there is no edge to the drawing — the
| compound sits in open country that runs to all four sides of the glass.
|
| Sections below:
|   1. Projection, the country and the ground
|   2. Buildings
|   3. Scenery — the placed kind, and the scattered kind
|   4. The frame loop
|   5. Camera, zoom, hit testing
|   6. Labels, the marker, the keyboard route
|   7. Boot
|
*/

import { C, disc, noise, poly, r, shade } from './world/paint.js';
import {
    createGuide,
    createPanel,
    createWipe,
    drawDockIcon,
    drawGuideSprite,
    prefersMotion,
    startSplash,
} from './world/chrome.js';

const root = document.documentElement;
const stage = document.getElementById('worldStage');

/* --------------------------------------------------- 1. projection + ground */

/*
 * A cell is 20 across and 10 down.
 *
 * Two-to-one exactly, and both even, because every half appears in the
 * projection: an odd tile width would put half the diamonds on a half-pixel and
 * hand back the soft grey edges the whole technique exists to avoid.
 *
 * Not larger, tempting as it is. A twenty-cell grid at 24x12 comes to 324
 * logical pixels tall, and a stage six hundred pixels high divides that by two
 * and floors to one — so the whole compound would have been drawn at 1x on an
 * ordinary monitor, which is the one scale at which pixel art looks like a
 * mistake rather than a decision. Everything about this projection is chosen so
 * that the integer scale lands on 2 or 3 where people actually work.
 */
const TW = 20;
const TH = 10;

/*
 * The compound, and the country it stands in.
 *
 * This was a diamond of grass on a rectangle of sky for a while, which read as
 * a card rather than a place; then an island, which read as a place but one
 * that had run out of room. It is inland now. The grid is the municipality's
 * own ground, a dirt verge runs around it, and past that is open country to
 * every edge of the glass whichever way you look.
 *
 * Which matters for more than the look of it: an office added next year needs
 * somewhere to stand, and ground that ends in water is ground that cannot be
 * extended.
 *
 * VERGE is the track around the compound — the line between what may be built
 * on and what may not. RING is how far the drawn country goes past it; the
 * camera is clamped inside that, so nobody ever arrives at the edge of it.
 */
const VERGE = 1;
const RING = 18;

/*
 * The ground the municipality has actually taken in.
 *
 * Handing the whole map over at once made the compound look like a mostly-empty
 * car park, so it comes in blocks: what is not in yet is drawn as country and
 * cannot be built on, and the track around the compound follows the boundary of
 * what is — which means the compound visibly grows when another block is taken
 * in.
 *
 * Both set from the payload at boot. See App\Support\Compound.
 */
let DISTRICT = 7;
let unlocked = new Set();

const isOpenGround = (x, y) =>
    x >= 0 &&
    y >= 0 &&
    x < COLS &&
    y < ROWS &&
    unlocked.has(Math.floor(x / DISTRICT) + ',' + Math.floor(y / DISTRICT));

/* The country is drawn in blocks this many cells across. It is scenery, not
   ground somebody places on, so it does not need a diamond per cell — and at
   one diamond per cell there were three and a half thousand of them a frame. */
const FIELD = 4;

/*
 * How big the compound is, in cells.
 *
 * There is no fixed grid any more, which is the point. This used to be one
 * number — a twenty-eight cell square — and the compound could be filled but
 * never enlarged: once the last block inside the square had been taken in, that
 * was the whole of the municipality for ever.
 *
 * So the compound is now exactly as wide and as deep as the blocks taken into
 * it, PHP works that out in App\Support\Compound::extent(), and both of these
 * *change while the page is open*: taking in a block along the north or east
 * edge comes back with a bigger compound, and grow() below moves the edge out
 * without a reload. Everything that walks the ground reads them rather than
 * closing over them for that reason.
 */
let COLS = 28;
let ROWS = 28;

/* Set from the stage on every resize; the canvas is always exactly the size of
   what can be seen, in logical pixels. */
let LW = 440;
let LH = 270;

/*
 * World space, with its origin at the back corner of cell (0,0).
 *
 * No screen offset in here at all any more. Where the compound lands is the
 * camera's business, and the camera is a translate on the context — which means
 * every sprite below still draws in the one coordinate system it was written
 * in, and none of them had to learn about panning or zoom.
 */
const iso = (x, y) => [(x - y) * (TW / 2), (x + y) * (TH / 2)];

/*
 * How far the drawn world reaches, so the camera can be stopped inside it.
 *
 * Worked out from the corners rather than from a span, now that the compound
 * need not be square. Screen-x is furthest left at the far corner of the last
 * *row* and furthest right at the far corner of the last *column*, which are
 * the same number only while the two happen to match.
 */
function worldBounds() {
    return {
        minX: (-(ROWS + 2 * RING) * TW) / 2,
        maxX: ((COLS + 2 * RING) * TW) / 2,
        /* Buildings are extruded upward, and the back corner is the top of the
           picture — sixty pixels clears the tallest roof standing on it. */
        minY: -RING * TH - 60,
        maxY: ((COLS + ROWS + 2 * RING) * TH) / 2,
    };
}

/*
 * The projection, run backwards, on a *distance* rather than a point.
 *
 * Dragging a building never needs to know which cell the cursor is over — only
 * how many cells it has travelled since the press, which is the same transform
 * with the origin left out. Doing it this way means the building keeps whatever
 * offset it was grabbed at, so it does not jump under the cursor on the first
 * pixel of movement.
 */
const isoDelta = (dx, dy) => [
    (dx / (TW / 2) + dy / (TH / 2)) / 2,
    (dy / (TH / 2) - dx / (TW / 2)) / 2,
];

/* Raise a projected point. Subtracting from screen-y is the only thing that
   means "up" in an isometric view. */
const up = (p, d) => [p[0], p[1] - d];

/*
 * What is under a cell.
 *
 * Inside the grid it is whatever PHP said — see App\Support\Compound for the
 * letters. One cell outside it is the verge, the dirt track that marks where
 * the municipality's ground stops. Past that is country, which is drawn in
 * blocks and needs no cell of its own.
 */
function tileAt(ground, x, y) {
    if (isOpenGround(x, y)) {
        return ground[y] ? ground[y][x] : 'g';
    }

    /*
     * The verge follows the compound's real boundary rather than the grid's.
     * Land nobody has taken in yet is country whichever side of the grid line
     * it happens to be on, and the track runs around what is actually in — so
     * taking a block in visibly enlarges the compound rather than filling in a
     * hole in it.
     */
    for (let dx = -VERGE; dx <= VERGE; dx++) {
        for (let dy = -VERGE; dy <= VERGE; dy++) {
            if (isOpenGround(x + dx, y + dy)) return 'v';
        }
    }

    return 'o';
}

/*
 * The country, in blocks.
 *
 * Fields rather than lawn: two greens and a ploughed brown, chosen by noise so
 * the same land comes back after every resize, laid out on the same isometric
 * grammar as the compound but four cells to a block. Per cell it was three and
 * a half thousand paths a frame to draw grass that nobody can build on.
 */
function drawCountry(c, camX, camY) {
    /* Under everything, in case a rounding error ever shows a seam. */
    r(c, camX, camY, LW, LH, C.grassDark);

    const from = -RING;
    const toX = COLS + RING;
    const toY = ROWS + RING;

    for (let y = from; y < toY; y += FIELD) {
        for (let x = from; x < toX; x += FIELD) {
            const seed = noise(x * 17.31 + y * 5.77);

            const A = iso(x, y);
            const B = iso(x + FIELD, y);
            const D = iso(x + FIELD, y + FIELD);
            const E = iso(x, y + FIELD);

            /* Cheap reject: the block's bounding box against the view. */
            if (B[0] < camX || A[0] > camX + LW || D[1] < camY || A[1] > camY + LH) continue;

            const ploughed = seed > 0.86;
            const fill = ploughed ? '#9c8355' : seed > 0.45 ? C.grassDark : shade(C.grassDark, 10);

            poly(c, [A, B, D, E], fill);
            poly(c, [E, D, [D[0], D[1] + 2], [E[0], E[1] + 2]], shade(fill, -20));

            if (ploughed) {
                /* Furrows, along the block's own diagonal. */
                for (let i = 1; i < FIELD * 2; i++) {
                    const f = i / (FIELD * 2);
                    const P = [A[0] + (E[0] - A[0]) * f, A[1] + (E[1] - A[1]) * f];
                    const Q = [B[0] + (D[0] - B[0]) * f, B[1] + (D[1] - B[1]) * f];

                    for (let k = 0; k <= 10; k++) {
                        const g = k / 10;
                        r(
                            c,
                            P[0] + (Q[0] - P[0]) * g,
                            P[1] + (Q[1] - P[1]) * g,
                            2,
                            1,
                            shade(fill, -14),
                        );
                    }
                }
            }
        }
    }
}

/*
 * The compound's own ground, painted in anti-diagonal bands.
 *
 * Band by band rather than row by row so the near edge of every tile is drawn
 * after the tile behind it — the same ordering the buildings use, for the same
 * reason. Only the grid and its verge: everything beyond is country, and was
 * drawn first.
 */
function drawGround(c, ground, camX, camY, arranging) {
    const from = -VERGE;
    const toX = COLS + VERGE;
    const toY = ROWS + VERGE;

    for (let band = from * 2; band <= toX + toY; band++) {
        for (let x = from; x < toX; x++) {
            const y = band - x;
            if (y < from || y >= toY) continue;

            const A = iso(x, y);

            /* One diamond is twenty across and ten down, so a band of thirty
               either way is more than enough margin for the tile's own size. */
            if (A[0] < camX - 30 || A[0] > camX + LW + 30) continue;
            if (A[1] < camY - 30 || A[1] > camY + LH + 30) continue;

            const kind = tileAt(ground, x, y);

            /* Country, including the blocks inside the grid that nobody has
               taken in. drawCountry has already put it down. */
            if (kind === 'o') continue;

            const B = iso(x + 1, y);
            const D = iso(x + 1, y + 1);
            const E = iso(x, y + 1);

            let fill;

            if (kind === 'v') {
                /* The track around the compound. It is the boundary, and the
                   only thing on the screen that says where you may build. */
                fill = (x + y) % 2 ? '#b9a888' : '#b0a081';
            } else if (kind === 'r') {
                fill = C.road;
            } else if (kind === 'p') {
                fill = (x + y) % 2 ? C.plaza : C.plazaAlt;
            } else {
                fill = (x + y) % 2 ? C.grass : C.grassAlt;
            }

            poly(c, [A, B, D, E], fill);

            /* A two-pixel lip along the near edge. It is what stops a field of
               diamonds reading as flat wallpaper. */
            poly(c, [E, D, [D[0], D[1] + 2], [E[0], E[1] + 2]], shade(fill, -22));

            /*
             * While somebody is arranging, every buildable cell gets its edges
             * drawn. Knowing where a building may go is the whole job, and up
             * to now the only way to find out was to drop one and be told no.
             */
            if (arranging && kind !== 'v') {
                poly(c, [A, B, [B[0], B[1] + 2], [A[0], A[1] + 2]], 'rgba(27,31,42,.34)');
                poly(c, [A, E, [E[0] + 2, E[1]], [A[0] + 2, A[1]]], 'rgba(27,31,42,.34)');
            }

            /* A tuft or two on the grass, in fixed places. noise() is
               deterministic, so a resize redraws the identical compound. */
            if (kind === 'g' && noise(x * 31 + y * 7) > 0.86) {
                const [cx, cy] = iso(x + 0.5, y + 0.5);
                r(c, cx - 2, cy - 3, 1, 3, C.grassDark);
                r(c, cx, cy - 4, 1, 4, C.grassDark);
                r(c, cx + 2, cy - 3, 1, 3, C.grassDark);
            }
        }
    }
}

/* ---------------------------------------------------------- 2. buildings */

/*
 * One office, extruded.
 *
 * Four polygons and nothing else: the left face, the right face, the roof's
 * underside and the roof itself with a raised lip. The two faces are the same
 * wall colour at two different shades, which is what tells the eye where the
 * light is without there being any light.
 *
 * `flat` fills every face with one colour instead. That is the pick pass — see
 * section 5 — and it is why this takes the parameter rather than there being a
 * second function that would drift out of step with this one.
 */
function drawIsoBuilding(c, b, lift, flat) {
    const h = b.height + lift;
    const A = iso(b.gx, b.gy);
    const B = iso(b.gx + b.w, b.gy);
    const D = iso(b.gx + b.w, b.gy + b.h);
    const E = iso(b.gx, b.gy + b.h);

    if (flat) {
        poly(c, [up(A, h), up(B, h), up(D, h), up(E, h)], flat);
        poly(c, [E, D, up(D, h), up(E, h)], flat);
        poly(c, [D, B, up(B, h), up(D, h)], flat);
        return;
    }

    const wall = b.wall || C.wall;
    const roof = b.roof || C.roofTeal;

    /* The two visible walls. */
    poly(c, [E, D, up(D, h), up(E, h)], shade(wall, -26));
    poly(c, [D, B, up(B, h), up(D, h)], shade(wall, -58));

    /* Windows, as a run of small panels along each face at a constant fraction
       of the height. Counted from the face's own length so a wide building gets
       more of them rather than wider ones. */
    const windows = (P, Q, fill, lit) => {
        const n = Math.max(1, Math.round(Math.hypot(Q[0] - P[0], Q[1] - P[1]) / 15));
        const storeys = Math.max(1, Math.round(b.height / 22));

        for (let s = 0; s < storeys; s++) {
            const base = 0.16 + (s * 0.72) / storeys;
            const top = base + 0.46 / storeys;

            for (let i = 0; i < n; i++) {
                const at = (f) => [P[0] + (Q[0] - P[0]) * f, P[1] + (Q[1] - P[1]) * f];
                const p0 = at((i + 0.3) / n);
                const p1 = at((i + 0.72) / n);

                poly(
                    c,
                    [up(p0, h * top), up(p1, h * top), up(p1, h * base), up(p0, h * base)],
                    fill,
                );
                poly(
                    c,
                    [
                        up(p0, h * top),
                        up(p1, h * top),
                        up(p1, h * (top - 0.06)),
                        up(p0, h * (top - 0.06)),
                    ],
                    lit,
                );
            }
        }
    };

    /* A warehouse has a roller door and no windows at all, which is most of
       what makes it read as a warehouse rather than as a wide office. */
    if (b.style === 'shed') {
        const [P, Q] = [E, D];

        for (let i = 1; i < 4; i++) {
            const f = i / 4;

            r(
                c,
                P[0] + (Q[0] - P[0]) * f - 1,
                P[1] + (Q[1] - P[1]) * f - h,
                2,
                h,
                shade(wall, -34),
            );
        }

        const [dx, dy] = [P[0] + (Q[0] - P[0]) * 0.5, P[1] + (Q[1] - P[1]) * 0.5];

        r(c, dx - 13, dy - h * 0.72, 26, h * 0.72, shade(C.stone, -18));
        for (let i = 0; i < 6; i++) r(c, dx - 13, dy - h * 0.72 + i * 4, 26, 2, C.stone);
    } else {
        windows(E, D, C.glass, C.glassLit);
        windows(D, B, shade(C.glass, -14), shade(C.glassLit, -14));
    }

    /* The door, on the corner nearest the front. Every building in this town
       faces the same way, which is what a compound looks like. */
    const doorH = Math.min(h * 0.34, 18);
    poly(
        c,
        [
            [D[0] - 4, D[1]],
            [D[0] + 4, D[1]],
            [D[0] + 4, D[1] - doorH],
            [D[0] - 4, D[1] - doorH],
        ],
        C.woodDark,
    );
    r(c, D[0] + 1, D[1] - doorH / 2, 2, 2, C.amber);

    /*
     * A portico on the two front faces, for the grand ones.
     *
     * Columns and a pediment are the whole difference between a hall and a
     * block, and they cost eight rectangles — which is a much better return
     * than another sprite would have been. Drawn after the walls and before the
     * roof, because that is where a portico is.
     */
    if (b.style === 'hall') {
        const colH = h * 0.82;

        [
            [E, D],
            [D, B],
        ].forEach(([P, Q], face) => {
            const n = Math.max(2, Math.round(Math.hypot(Q[0] - P[0], Q[1] - P[1]) / 13));

            for (let i = 0; i <= n; i++) {
                const f = i / n;
                const x = P[0] + (Q[0] - P[0]) * f;
                const y = P[1] + (Q[1] - P[1]) * f;

                r(c, x - 2, y - colH, 4, colH, face ? shade(C.cream, -22) : C.cream);
                r(c, x - 3, y - colH - 2, 6, 3, face ? shade(C.cream, -30) : shade(C.cream, -12));
                r(c, x - 3, y - 3, 6, 3, face ? shade(C.cream, -30) : shade(C.cream, -12));
            }
        });
    }

    /*
     * Roof: an overhanging underside, the roof itself, and a lip above it.
     * Three parallel quads is the cheapest thing that reads as a slab with a
     * rim — and an annex is the same three with a much deeper overhang and no
     * rim at all, which is what a low building with a wide roof looks like.
     */
    const annex = b.style === 'annex';
    const e = annex ? 7 : 3;

    poly(
        c,
        [
            [A[0], A[1] - h - e],
            [B[0] + e * 2, B[1] - h],
            [D[0], D[1] - h + e],
            [E[0] - e * 2, E[1] - h],
        ],
        shade(roof, -40),
    );
    poly(c, [up(A, h), up(B, h), up(D, h), up(E, h)], roof);

    if (!annex) {
        poly(c, [up(A, h + 5), up(B, h + 5), up(D, h + 5), up(E, h + 5)], shade(roof, 26));
    }

    /* And the pediment over the front corner, which is the part that says hall
       from across the compound. Stepped, because a smooth triangle on a pixel
       canvas is a triangle with grey edges. */
    if (b.style === 'hall') {
        for (let i = 0; i < 9; i++) {
            r(
                c,
                D[0] - 18 + i * 2,
                D[1] - h - 4 - i * 2,
                36 - i * 4,
                2,
                i < 7 ? C.cream : shade(C.cream, -18),
            );
        }

        r(c, D[0] - 20, D[1] - h - 2, 40, 3, shade(C.cream, -26));
    }
}

/* ------------------------------------------------------------ 3. scenery */

/*
 * The four things in a compound that are not offices.
 *
 * Each draws itself from its cell, and each takes the same `flat` parameter as
 * a building so the pick pass works identically. A gate is not a small office
 * with a different colour, and drawing it as one would make the compound a
 * chart of coloured boxes.
 */
/*
 * A prop, placed.
 *
 * The plants are drawn by PROPS, which knows about a cell and nothing about
 * pick buffers — it was written for the scenery scattered across the country,
 * where nothing is clickable. A placed tree is clickable, so this is the
 * adapter: the flat pass gets a box big enough to hit, and the visible pass is
 * the same drawing the country uses. One tree, drawn one way.
 */
function placed(c, b, flat, halfWidth, height, draw) {
    if (flat) {
        const [cx, cy] = iso(b.gx + b.w / 2, b.gy + b.h / 2);

        r(c, cx - halfWidth, cy - height, halfWidth * 2 + 1, height + 4, flat);

        return;
    }

    draw();
}

const SCENERY = {
    /* Two pillars, a beam between them, and the guardhouse beside it. */
    gate(c, b, flat) {
        const left = iso(b.gx, b.gy + b.h);
        const right = iso(b.gx + b.w, b.gy + b.h);
        const post = (p, colour) => {
            r(c, p[0] - 3, p[1] - 34, 6, 34, colour || C.stone);
            if (!flat) r(c, p[0] - 1, p[1] - 34, 2, 34, C.rockLight);
        };

        post(left, flat);
        post(right, flat);

        r(c, left[0], left[1] - 38, right[0] - left[0], 6, flat || C.rust);
        if (!flat) r(c, left[0], left[1] - 38, right[0] - left[0], 2, shade(C.rust, 24));

        drawIsoBuilding(
            c,
            {
                gx: b.gx,
                gy: b.gy,
                w: 1,
                h: b.h,
                height: 20,
                wall: C.wall,
                roof: C.roofRust,
            },
            0,
            flat,
        );
    },

    /* A pole with a flag on it, and the flag moves. Nothing else in the
       compound moves except the sea, which is the point of putting it in the
       middle of the plaza. */
    flagpole(c, b, flat, t) {
        const foot = iso(b.gx + 0.5, b.gy + 0.5);

        if (flat) {
            r(c, foot[0] - 6, foot[1] - b.height - 12, 22, b.height + 12, flat);
            return;
        }

        disc(c, foot[0], foot[1], 5, C.plazaAlt);
        r(c, foot[0] - 4, foot[1] - 4, 8, 4, C.stone);
        r(c, foot[0] - 1, foot[1] - b.height, 2, b.height, C.rockLight);
        r(c, foot[0] - 1, foot[1] - b.height, 2, 4, C.amber);

        /* The flag, as three stacked bands with a wave running through them. */
        const wave = (i) => Math.round(Math.sin(t / 260 + i * 0.9));
        for (let i = 0; i < 12; i++) {
            const y = foot[1] - b.height + 4 + wave(i);
            r(c, foot[0] + 1 + i, y, 1, 4, C.navy);
            r(c, foot[0] + 1 + i, y + 4, 1, 4, C.rust);
            if (i < 5) r(c, foot[0] + 1 + i, y, 1, 8, i < 3 ? C.cream : C.navy);
        }
    },

    /* A roof on four posts, which is what a waiting shed is. */
    shed(c, b, flat) {
        const A = iso(b.gx, b.gy);
        const B = iso(b.gx + b.w, b.gy);
        const D = iso(b.gx + b.w, b.gy + b.h);
        const E = iso(b.gx, b.gy + b.h);
        const h = b.height;

        [A, B, D, E].forEach((p) => r(c, p[0] - 1, p[1] - h, 2, h, flat || C.woodDark));

        if (!flat) {
            /* A bench along the back. */
            poly(c, [A, B, [B[0], B[1] + 4], [A[0], A[1] + 4]], C.wood);
        }

        poly(c, [up(A, h), up(B, h), up(D, h), up(E, h)], flat || C.roofNipa);
        if (!flat)
            poly(
                c,
                [up(A, h + 4), up(B, h + 4), up(D, h + 4), up(E, h + 4)],
                shade(C.roofNipa, 18),
            );
    },

    /* --------------------------------------------------------- planting -- */

    /*
     * The three plants, and a bed of flowers.
     *
     * Deliberately the same drawings as the scattered scenery out in the
     * country — PROPS below — because a tree somebody placed and a tree that
     * grew there should be the same tree. These are the wrappers that let one
     * be placed, dragged and taken down like anything else.
     */
    tree(c, b, flat, t) {
        placed(c, b, flat, 7, 22, () => PROPS.tree(c, b.gx, b.gy, t, 0.5));
    },

    palm(c, b, flat, t) {
        placed(c, b, flat, 8, 26, () => PROPS.palm(c, b.gx, b.gy, t, 0.7));
    },

    bush(c, b, flat, t) {
        placed(c, b, flat, 7, 11, () => PROPS.bush(c, b.gx, b.gy, t, 0.9));
    },

    /* A raised bed, planted in rows. */
    flowers(c, b, flat) {
        const A = iso(b.gx, b.gy);
        const B = iso(b.gx + b.w, b.gy);
        const D = iso(b.gx + b.w, b.gy + b.h);
        const E = iso(b.gx, b.gy + b.h);
        const h = 5;

        poly(c, [E, D, up(D, h), up(E, h)], flat || '#8a6a44');
        poly(c, [D, B, up(B, h), up(D, h)], flat || '#71553799');
        poly(c, [up(A, h), up(B, h), up(D, h), up(E, h)], flat || '#5c4830');

        if (flat) return;

        /* Four colours, in fixed places, so the same bed comes back. */
        const blooms = [C.rust, C.amber, '#d86f9c', C.cream];

        for (let i = 0; i < 14; i++) {
            const f = (i + 0.5) / 14;
            const x = E[0] + (D[0] - E[0]) * f;
            const y = E[1] + (D[1] - E[1]) * f - h;
            const n = noise(i * 3.7 + b.gx + b.gy);

            r(c, x - 1, y - 4 - Math.round(n * 2), 2, 2, blooms[i % blooms.length]);
            r(c, x, y - 2, 1, 2, C.leaf1);
        }
    },

    /* ---------------------------------------------------------- shelter -- */

    /* A canvas tent, the kind that goes up for registration day. */
    tent(c, b, flat, t) {
        const A = iso(b.gx, b.gy);
        const B = iso(b.gx + b.w, b.gy);
        const D = iso(b.gx + b.w, b.gy + b.h);
        const E = iso(b.gx, b.gy + b.h);
        const h = b.height;
        const cloth = b.wall || C.cream;

        [A, B, D, E].forEach((p) => r(c, p[0] - 1, p[1] - h + 6, 2, h - 6, flat || C.woodDark));

        if (flat) {
            r(c, E[0], A[1] - h - 8, D[0] - E[0], h + 8, flat);

            return;
        }

        /* Two sloping panels meeting at a ridge over the middle. */
        const [mx, my] = iso(b.gx + b.w / 2, b.gy + b.h / 2);
        const ridge = my - h - 10;

        poly(c, [up(A, h - 6), up(B, h - 6), [mx, ridge]], shade(cloth, 12));
        poly(c, [up(B, h - 6), up(D, h - 6), [mx, ridge]], shade(cloth, -18));
        poly(c, [up(D, h - 6), up(E, h - 6), [mx, ridge]], shade(cloth, -34));
        poly(c, [up(E, h - 6), up(A, h - 6), [mx, ridge]], cloth);

        /* A scalloped valance, which is the part that says tent. */
        [
            [E, D],
            [D, B],
        ].forEach(([P, Q], face) => {
            const n = Math.max(3, Math.round(Math.hypot(Q[0] - P[0], Q[1] - P[1]) / 8));

            for (let i = 0; i < n; i++) {
                const f = (i + 0.5) / n;
                const x = P[0] + (Q[0] - P[0]) * f;
                const y = P[1] + (Q[1] - P[1]) * f - h + 6;

                r(c, x - 3, y, 6, 3, i % 2 ? C.rust : shade(cloth, face ? -30 : -10));
            }
        });
    },

    /* Somewhere to wait. */
    bench(c, b, flat) {
        const [cx, cy] = iso(b.gx + 0.5, b.gy + 0.5);

        if (flat) {
            r(c, cx - 10, cy - 12, 21, 14, flat);

            return;
        }

        r(c, cx - 9, cy - 1, 19, 2, 'rgba(20,20,10,.2)');
        [-7, 6].forEach((dx) => r(c, cx + dx, cy - 6, 3, 6, C.stoneDark));
        r(c, cx - 10, cy - 8, 21, 3, C.wood);
        r(c, cx - 10, cy - 8, 21, 1, shade(C.wood, 20));
        r(c, cx - 10, cy - 13, 21, 3, C.wood);
        [-8, 7].forEach((dx) => r(c, cx + dx, cy - 13, 2, 6, shade(C.wood, -22)));
    },

    /* ------------------------------------------------------------ civic -- */

    /*
     * A fountain, which is the one thing on the map with water in it.
     *
     * Same construction as the town's: a basin as stacked runs, a jet that
     * rises and falls, and a ring of spray. Frozen when movement is off,
     * because t stops advancing rather than there being a special case.
     */
    fountain(c, b, flat, t) {
        const [cx, cy] = iso(b.gx + b.w / 2, b.gy + b.h / 2);

        if (flat) {
            r(c, cx - 16, cy - 26, 33, 30, flat);

            return;
        }

        disc(c, cx, cy, 16, C.stone);
        disc(c, cx, cy, 13, C.rockLight);
        disc(c, cx, cy - 1, 11, C.sea);
        disc(c, cx, cy - 2, 9, shade(C.sea, 18));

        /* The plinth and the jet. */
        r(c, cx - 3, cy - 12, 7, 11, C.stone);
        r(c, cx - 2, cy - 12, 3, 11, C.rockLight);
        r(c, cx - 5, cy - 15, 11, 3, C.stone);

        const rise = 6 + Math.round(Math.sin(t / 420) * 2);

        r(c, cx - 1, cy - 15 - rise, 2, rise, C.foam);

        for (let i = 0; i < 6; i++) {
            const a = (i * Math.PI) / 3 + t / 900;
            const spread = 7 + Math.sin(t / 380 + i) * 2;

            r(c, cx + Math.cos(a) * spread, cy - 12 + Math.sin(a) * 3, 2, 2, C.foam);
        }
    },

    /* A plinth, and somebody standing on it. */
    monument(c, b, flat) {
        const [cx, cy] = iso(b.gx + 0.5, b.gy + 0.5);

        if (flat) {
            r(c, cx - 8, cy - 32, 17, 34, flat);

            return;
        }

        r(c, cx - 9, cy - 2, 19, 3, 'rgba(20,20,10,.2)');
        r(c, cx - 8, cy - 6, 17, 5, C.stone);
        r(c, cx - 6, cy - 18, 13, 12, C.rockLight);
        r(c, cx - 6, cy - 18, 13, 2, C.stone);
        r(c, cx - 7, cy - 21, 15, 3, C.stone);

        /* The figure: a coat, a head, and an arm out. Anything more at this
           size is a smudge. */
        r(c, cx - 3, cy - 30, 7, 9, C.rockDark);
        r(c, cx - 2, cy - 34, 5, 5, C.rock);
        r(c, cx + 4, cy - 32, 4, 2, C.rockDark);
    },

    /* Which way to which office. */
    sign(c, b, flat) {
        const [cx, cy] = iso(b.gx + 0.5, b.gy + 0.5);

        if (flat) {
            r(c, cx - 11, cy - 18, 23, 20, flat);

            return;
        }

        r(c, cx - 6, cy - 1, 13, 2, 'rgba(20,20,10,.2)');
        r(c, cx - 1, cy - 12, 3, 12, C.woodDark);
        r(c, cx - 11, cy - 18, 23, 7, C.cream);
        r(c, cx - 11, cy - 18, 23, 2, shade(C.cream, -20));

        for (let i = 0; i < 3; i++) r(c, cx - 8 + i * 6, cy - 15, 4, 2, C.navy);

        r(c, cx - 9, cy - 10, 19, 5, C.navy);
        for (let i = 0; i < 4; i++) r(c, cx - 7 + i * 4, cy - 9, 3, 2, C.cream);
    },

    /* Lit after five. */
    lamp(c, b, flat, t) {
        const [cx, cy] = iso(b.gx + 0.5, b.gy + 0.5);

        if (flat) {
            r(c, cx - 5, cy - 28, 11, 30, flat);

            return;
        }

        r(c, cx - 5, cy - 1, 11, 2, 'rgba(20,20,10,.2)');
        r(c, cx - 3, cy - 4, 7, 4, C.stone);
        r(c, cx - 1, cy - 24, 3, 21, C.stoneDark);
        r(c, cx, cy - 24, 1, 21, C.rockLight);
        r(c, cx - 4, cy - 28, 9, 5, C.ink);

        const flicker = 0.72 + 0.28 * Math.abs(Math.sin(t / 1400));

        r(c, cx - 3, cy - 27, 7, 3, `rgba(252,217,138,${flicker})`);
        r(c, cx - 6, cy - 24, 13, 2, `rgba(252,217,138,${flicker * 0.25})`);
    },

    /*
     * The covered court, which every compound in the country has.
     *
     * Painted lines on the slab, four posts and a roof over them — and the roof
     * is drawn last and translucent so the court underneath is still readable,
     * which is the only way this works from above.
     */
    court(c, b, flat, t) {
        const A = iso(b.gx, b.gy);
        const B = iso(b.gx + b.w, b.gy);
        const D = iso(b.gx + b.w, b.gy + b.h);
        const E = iso(b.gx, b.gy + b.h);
        const h = b.height;

        if (flat) {
            poly(c, [up(A, h + 6), up(B, h + 6), up(D, h + 6), up(E, h + 6)], flat);
            poly(c, [E, D, up(D, h), up(E, h)], flat);

            return;
        }

        /* The slab. */
        poly(c, [A, B, D, E], '#b9895c');
        poly(c, [E, D, [D[0], D[1] + 2], [E[0], E[1] + 2]], '#9a7049');

        /* Centre line and circle, in paint. */
        const [mx, my] = iso(b.gx + b.w / 2, b.gy + b.h / 2);
        const [lx, ly] = iso(b.gx, b.gy + b.h / 2);
        const [rx, ry] = iso(b.gx + b.w, b.gy + b.h / 2);

        for (let i = 0; i <= 30; i++) {
            const f = i / 30;

            r(c, lx + (rx - lx) * f, ly + (ry - ly) * f, 2, 1, 'rgba(244,236,218,.6)');
        }

        for (let i = 0; i < 16; i++) {
            const a = (i * Math.PI) / 8;

            r(c, mx + Math.cos(a) * 12, my + Math.sin(a) * 6, 2, 1, 'rgba(244,236,218,.6)');
        }

        /* Posts, then the roof. */
        [A, B, D, E].forEach((p) => {
            r(c, p[0] - 2, p[1] - h, 4, h, C.stoneDark);
            r(c, p[0] - 1, p[1] - h, 2, h, C.stone);
        });

        const roof = b.roof || C.roofTeal;

        poly(c, [up(A, h), up(B, h), up(D, h), up(E, h)], roof);
        poly(c, [up(A, h + 6), up(B, h + 6), up(D, h + 6), up(E, h + 6)], shade(roof, 22));

        /* Corrugation, along the near slope. */
        for (let i = 0; i <= 20; i++) {
            const f = i / 20;
            const x = E[0] + (D[0] - E[0]) * f;
            const y = E[1] + (D[1] - E[1]) * f - h - 6;

            r(c, x, y, 1, 7, shade(roof, -18));
        }
    },

    /* --------------------------------------------------------- boundary -- */

    /*
     * A length of wall.
     *
     * Drawn along the cell's near edge rather than filling it, so a run of them
     * reads as one wall with a top rather than as a row of blocks. Placed a
     * cell at a time, or three at a time with the long one.
     */
    wall(c, b, flat) {
        const E = iso(b.gx, b.gy + b.h);
        const D = iso(b.gx + b.w, b.gy + b.h);
        const h = b.height;
        const face = b.wall || '#cfc6b0';

        poly(c, [E, D, up(D, h), up(E, h)], flat || shade(face, -18));

        if (flat) return;

        poly(c, [up(E, h), up(D, h), up(D, h + 4), up(E, h + 4)], face);
        poly(
            c,
            [up(E, h + 4), up(D, h + 4), [D[0], D[1] - h - 6], [E[0], E[1] - h - 6]],
            shade(face, 16),
        );

        /* Pillars at both ends, and every second cell along. */
        for (let i = 0; i <= b.w; i++) {
            const f = i / b.w;
            const x = E[0] + (D[0] - E[0]) * f;
            const y = E[1] + (D[1] - E[1]) * f;

            r(c, x - 2, y - h - 8, 5, h + 8, shade(face, -8));
            r(c, x - 2, y - h - 10, 5, 3, shade(face, -26));
        }
    },

    /* Leaves when full. */
    jeepney(c, b, flat) {
        const front = iso(b.gx, b.gy + b.h);
        const back = iso(b.gx + b.w, b.gy + b.h);
        const h = b.height;
        const w = back[0] - front[0];

        r(c, front[0], front[1] - h, w, h, flat || C.cream);

        if (flat) return;

        r(c, front[0], front[1] - h, w, 4, C.rust);
        r(c, front[0], front[1] - Math.round(h * 0.62), w, Math.round(h * 0.3), C.glass);
        for (let i = 0; i + 8 < w; i += 10) {
            r(
                c,
                front[0] + 4 + i,
                front[1] - Math.round(h * 0.62),
                2,
                Math.round(h * 0.3),
                C.cream,
            );
        }
        r(c, front[0] + 3, front[1] - 3, 5, 4, C.ink);
        r(c, front[0] + w - 9, front[1] - 3, 5, 4, C.ink);
        r(c, front[0], front[1] - h - 3, w, 3, shade(C.cream, -22));
    },
};

/*
 * The scenery nobody placed.
 *
 * The four sprites above are rows in compound_buildings — somebody put them
 * there and can drag them somewhere else. These are not: they are what fills
 * the compound's spare corners and the country around it, scattered by noise()
 * and therefore identical on every load and every resize.
 *
 * Each draws from the centre of its cell and takes the same `flat` parameter as
 * a building, because they go through the same depth sort — a palm in front of
 * the Treasurer's Office has to be drawn after it, and one behind it before.
 * They are never in the pick buffer, though: clicking a rock should do nothing,
 * and clicking the office behind it should open the office.
 */
const PROPS = {
    /* A round-headed tree for the compound's spare grass. */
    tree(c, x, y, t, seed) {
        const [cx, cy] = iso(x + 0.5, y + 0.5);
        const sway = Math.round(Math.sin(t / 900 + seed * 6) * 1);

        r(c, cx - 4, cy - 1, 9, 3, 'rgba(20,20,10,.18)');
        r(c, cx - 1, cy - 12, 3, 12, C.woodDark);
        disc(c, cx + sway, cy - 17, 7, C.leaf1);
        disc(c, cx + sway - 2, cy - 19, 5, C.leaf2);
        disc(c, cx + sway + 2, cy - 20, 4, C.leaf3);
    },

    /* And a palm for the beach, leaning the way the wind is going. */
    palm(c, x, y, t, seed) {
        const [cx, cy] = iso(x + 0.5, y + 0.5);
        const lean = seed > 0.5 ? 1 : -1;
        const sway = Math.round(Math.sin(t / 760 + seed * 9) * 1);

        r(c, cx - 5, cy - 1, 11, 3, 'rgba(20,20,10,.16)');

        for (let i = 0; i < 16; i++) {
            r(
                c,
                cx + Math.round((i / 16) * 5) * lean,
                cy - i,
                2,
                1,
                i % 4 === 3 ? C.nipaDark : C.wood,
            );
        }

        const hx = cx + 5 * lean + sway;
        const hy = cy - 17;

        [-1, 1].forEach((dir) => {
            for (let i = 0; i < 8; i++) {
                r(
                    c,
                    hx + i * dir,
                    hy + Math.round((i * i) / 12) - 1,
                    2,
                    2,
                    i < 4 ? C.leaf3 : C.leaf1,
                );
                r(
                    c,
                    hx + i * dir,
                    hy - 3 + Math.round((i * i) / 9),
                    2,
                    2,
                    i < 4 ? C.leaf2 : C.leaf1,
                );
            }
        });

        r(c, hx - 1, hy - 1, 3, 3, C.nipaDark);
    },

    /* A bush, for the verge and the hedgerows. */
    bush(c, x, y, t, seed) {
        const [cx, cy] = iso(x + 0.5, y + 0.5);

        r(c, cx - 5, cy - 1, 11, 2, 'rgba(20,20,10,.15)');
        disc(c, cx - 3, cy - 4, 4, C.leaf1);
        disc(c, cx + 3, cy - 4, 4, C.leaf1);
        disc(c, cx, cy - 6, 5, C.leaf2);
        disc(c, cx - 1, cy - 8, 3, C.leaf3);
        if (seed > 0.94) {
            /* Something in flower, now and then. */
            r(c, cx + 2, cy - 8, 2, 2, C.amber);
            r(c, cx - 4, cy - 6, 2, 2, C.amber);
        }
    },

    /* Rocks, for the rougher ground at the edges. */
    rock(c, x, y, t, seed) {
        const [cx, cy] = iso(x + 0.5, y + 0.5);
        const big = seed > 0.7;

        r(c, cx - 7, cy - 1, 15, 2, 'rgba(20,20,10,.18)');
        disc(c, cx, cy - 3, big ? 6 : 4, C.rock);
        disc(c, cx - 1, cy - 5, big ? 4 : 3, C.rockLight);
        if (big) disc(c, cx + 5, cy - 2, 3, C.rockDark);
    },

    /*
     * A nipa hut out in the fields.
     *
     * The country is not empty — people live in it. A handful of these is what
     * turns the ground past the verge from a texture into somewhere.
     */
    hut(c, x, y, t, seed) {
        const [cx, cy] = iso(x + 0.5, y + 0.5);
        const w = seed > 0.6 ? 20 : 16;

        r(c, cx - w / 2 - 1, cy - 2, w + 2, 3, 'rgba(20,20,10,.2)');

        /* Stilts, then the walls, then a deep nipa roof over both. */
        [-1, 1].forEach((dir) => r(c, cx + (dir * w) / 2 - 2, cy - 6, 2, 6, C.woodDark));
        r(c, cx - w / 2, cy - 15, w, 10, C.wallShade);
        r(c, cx - w / 2, cy - 15, w, 2, shade(C.wallShade, -18));
        r(c, cx - 3, cy - 11, 6, 6, C.woodDark);

        r(c, cx - w / 2 - 4, cy - 20, w + 8, 6, C.nipaDark);
        r(c, cx - w / 2 + 1, cy - 24, w - 2, 5, C.roofNipa);
        r(c, cx - 4, cy - 26, 8, 3, shade(C.roofNipa, 16));
    },
};

/* ------------------------------------------------------------ 4. the loop */

function boot() {
    root.dataset.world = 'on';

    const data = JSON.parse(document.getElementById('worldData').textContent);
    const places = data.places || [];

    /* Rows of characters rather than strings: a brush changes one cell at a
       time, and a string would have to be rebuilt for every square of every
       stroke. Indexing reads the same either way. */
    const ground = (data.ground || []).map((row) => row.split(''));

    COLS = data.cols || 28;
    ROWS = data.rows || 28;
    DISTRICT = data.district || 7;
    unlocked = new Set(data.unlocked || []);

    /* The blocks that could be taken in next — the ones touching ground the
       compound already holds. There is always at least one, so there is always
       room to grow. Empty for everybody who could not take one in anyway; see
       App\Support\Compound::payload. */
    let land = data.land || [];

    /* And the blocks that could go back the other way: empty ones on the
       outside that the rest of the compound does not depend on. Often empty,
       because most blocks have something standing on them. */
    let giveBack = data.giveBack || [];

    const canvas = document.getElementById('worldCanvas');
    const ctx = canvas.getContext('2d');
    const labelsEl = document.getElementById('worldLabels');
    const tagEl = document.getElementById('worldTag');
    const keynavEl = document.getElementById('worldKeynav');
    const hintEl = document.getElementById('worldHint');

    /* The pick buffer: every frame is drawn twice, once for the eye and once as
       flat rgb(index+1, 0, 0) into an offscreen canvas nobody sees. A click
       reads one pixel from it. No geometry, and it fits an isometric silhouette
       exactly — which a bounding rectangle, the town's approach, would not. */
    const pickCanvas = document.createElement('canvas');
    const pickCtx = pickCanvas.getContext('2d', { willReadFrequently: true });

    /*
     * The camera: an integer zoom and a whole-pixel corner.
     *
     * Both integers on purpose. A fractional zoom smears every edge in the
     * picture, which is the one thing this whole technique exists to avoid, and
     * a fractional camera does the same thing to a sharp drawing by landing it
     * on half a pixel.
     */
    let zoom = 2;
    let minZoom = 1;
    let maxZoom = 6;
    let camX = 0;
    let camY = 0;

    /* Whether the camera has been put somewhere sensible yet. The first fit
       chooses the zoom and centres the compound; every fit after it — a resize, a
       rotated phone — leaves both alone, because moving somebody's view because
       they opened the console is rude. */
    let placed = false;

    /* On, unless the machine asks otherwise. There is no toggle on this
       screen any more — see the dock in the compound layout for why. */
    let motion = prefersMotion();
    let started = performance.now();
    let elapsed = 0;
    let hovered = -1;
    const anchors = [];

    /* The editor. `moving` is the building under the cursor mid-drag and the
       cell it would land on; `dirty` is everything moved since the last save,
       keyed by id so dragging one building four times still saves once. */
    let arranging = false;
    let moving = null;

    /* Which block of the frontier the cursor is over, and which block of the
       ground that could be given back the panel is pointing at. Two of them
       because they mean opposite things and are drawn in opposite colours. */
    let hoveredLand = -1;
    let hoveredGiveBack = -1;

    /* Which half of the Land tab is showing. Kept out here so that taking a
       block in and coming back to the tab does not drop somebody back on the
       list they were not using. */
    let landMode = 'take';

    /* A building chosen from the templates and not yet put down. While this is
       set the cursor carries a ghost and a click drops it. */
    let placing = null;

    /* The surface currently loaded on the brush, if any, and the cells it has
       laid since the stroke began. A stroke is one request. */
    let brush = null;
    let stroke = null;

    /*
     * Picking a surface up, and putting it down.
     *
     * Through these two and nowhere else, because a loaded brush is a mode —
     * every press on the map means something different while one is on — and a
     * mode you can enter from one place and only leave from another is a trap.
     * It was one: the panel that loaded the brush was also the only thing that
     * could unload it, and closing the panel left every click on the map still
     * laying path.
     *
     * So there are now four ways out and they all come through here: the chip
     * above the dock, Escape, leaving arrange mode, and the panel itself.
     */
    const chipEl = document.getElementById('compoundBrushChip');
    const chipName = document.getElementById('compoundBrushName');
    const chipSwatch = document.getElementById('compoundBrushSwatch');

    function takeUpTheBrush(surface) {
        brush = surface.id;

        if (!arranging) arrangeBtn?.click();

        if (chipEl) {
            chipName.textContent = surface.name;
            drawBrushSwatch(chipSwatch, surface.id);
            chipEl.hidden = false;
        }

        npc.say('Drag across the compound to lay it. Done, or Escape, when you have finished.');
    }

    function putTheBrushDown() {
        if (!brush) return;

        brush = null;
        stroke = null;

        if (chipEl) chipEl.hidden = true;
    }

    document.getElementById('compoundBrushStop')?.addEventListener('click', putTheBrushDown);

    const dirty = new Map();

    /* Nearest the front last. The centre of the footprint along the grid's
       diagonal is the depth of a building, and sorting on it is the entire
       reason nothing here needs a z-index. Re-sorted after every drop, because
       moving a building is exactly the thing that changes it. */
    let inDepthOrder = [];

    function sortByDepth() {
        inDepthOrder = places
            .map((place, i) => ({
                place,
                i,
                d: place.gx + place.gy + (place.w + place.h) / 2,
            }))
            .sort((a, b) => a.d - b.d);
    }

    sortByDepth();

    /*
     * The scattered scenery, worked out once and again after every drop.
     *
     * After a drop because a building that moves uncovers the cell it was
     * standing on, and a compound where the Legal Office leaves a bald patch
     * behind it is a compound that has been edited rather than arranged.
     */
    let props = [];

    function rebuildProps() {
        const covered = new Set();

        places.forEach((place) => {
            for (let x = place.gx; x < place.gx + place.w; x++) {
                for (let y = place.gy; y < place.gy + place.h; y++) covered.add(x + ',' + y);
            }
        });

        props = [];

        for (let y = -RING; y < ROWS + RING; y++) {
            for (let x = -RING; x < COLS + RING; x++) {
                if (covered.has(x + ',' + y)) continue;

                const seed = noise(x * 73.13 + y * 31.77);
                const tile = tileAt(ground, x, y);
                let kind = null;

                /*
                 * Thresholds, not counts, and the country's are low.
                 *
                 * Inside the compound almost everything is either a building or
                 * the grass between two of them, so a tree is rare. Outside it
                 * the land is the subject, and empty green would be a texture
                 * rather than a place — so the ratio inverts.
                 */
                if (tile === 'g') kind = seed > 0.9 ? 'tree' : null;
                else if (tile === 'v') kind = seed > 0.86 ? 'bush' : null;
                else if (tile === 'o') {
                    kind =
                        seed > 0.985
                            ? 'hut'
                            : seed > 0.94
                              ? 'palm'
                              : seed > 0.87
                                ? 'tree'
                                : seed > 0.83
                                  ? 'bush'
                                  : seed > 0.815
                                    ? 'rock'
                                    : null;
                }

                if (kind) props.push({ kind, x, y, seed, d: x + y + 1 });
            }
        }

        /* Into depth order once, here, so the frame loop can walk it and the
           buildings together in a single pass. */
        props.sort((a, b) => a.d - b.d);
    }

    rebuildProps();

    /* Which building is the signed-in employee's own, or -1. No sentinel
       string to fall back on: an office code concatenated onto a placeholder
       is a value that can only ever fail to match, which is a comparison
       pretending to be a lookup. */
    const mine = data.you ? places.findIndex((p) => p.id === 'office:' + data.you) : -1;

    function frame(now) {
        if (motion) elapsed = now - started;
        const t = elapsed;

        /*
         * The camera, applied once to each context.
         *
         * Whole pixels, so the drawing lands on the grid it was composed on.
         * Everything after this draws in world coordinates and knows nothing
         * about where the view happens to be.
         */
        ctx.setTransform(1, 0, 0, 1, -camX, -camY);
        pickCtx.setTransform(1, 0, 0, 1, -camX, -camY);
        pickCtx.clearRect(camX, camY, LW, LH);

        drawCountry(ctx, camX, camY);
        drawGround(ctx, ground, camX, camY, arranging);

        /*
         * Buildings and scenery, merged and sorted together.
         *
         * A palm in front of the Treasurer's Office has to be drawn after it
         * and one behind it before, which is the same rule the buildings already
         * follow among themselves — so they go through one sort rather than
         * being drawn in two passes that could never agree about depth.
         */
        let p = 0;

        inDepthOrder.forEach((item) => {
            while (p < props.length && props[p].d <= item.d) {
                const prop = props[p++];
                PROPS[prop.kind](ctx, prop.x, prop.y, t, prop.seed);
            }

            drawPlace(item, t);
        });

        while (p < props.length) {
            const prop = props[p++];
            PROPS[prop.kind](ctx, prop.x, prop.y, t, prop.seed);
        }

        if (arranging) drawLand(ctx);
        if (moving) drawGhost(ctx);
        if (placing) drawPlacement(ctx);

        requestAnimationFrame(frame);
    }

    /*
     * The ground on either side of the compound's edge, while it is being
     * arranged.
     *
     * Amber for a block that could be taken in, with a padlock over it. Rust for
     * one that could be given back, which is only ever drawn while the row for
     * it is hovered in the panel — an outline over ground that is already the
     * compound, shown all the time, would read as a warning about ground that is
     * perfectly fine.
     *
     * Only in arrange mode and only for somebody who can actually change it: a
     * padlock over a field you cannot do anything about is a locked door where
     * there was no door.
     */
    function drawLand(c) {
        land.forEach((block, i) => {
            const hot = i === hoveredLand;

            washBlock(c, block, hot ? 'rgba(242,169,59,.34)' : 'rgba(242,169,59,.15)', C.amber);

            const [cx, cy] = landAnchor(block);
            drawPadlock(c, cx, cy, hot);
        });

        /*
         * The blocks that could go back the other way.
         *
         * A peg with a minus on it, only ever a marker — no wash until it is
         * hovered. These stand on ground that is already the compound and looks
         * perfectly fine, and a rust stain over four of its blocks all the time
         * would read as a warning about something wrong with them.
         */
        giveBack.forEach((block, i) => {
            const hot = i === hoveredGiveBack;

            if (hot) washBlock(c, block, 'rgba(193,70,47,.28)', C.rust);

            const [cx, cy] = landAnchor(block);
            drawGiveBackPeg(c, cx, cy, hot);
        });
    }

    /** A block, washed in a colour and outlined in dashes along its diagonals. */
    function washBlock(c, block, wash, edge) {
        for (let x = block.x0; x < block.x1; x++) {
            for (let y = block.y0; y < block.y1; y++) {
                poly(c, [iso(x, y), iso(x + 1, y), iso(x + 1, y + 1), iso(x, y + 1)], wash);
            }
        }

        const A = iso(block.x0, block.y0);
        const B = iso(block.x1, block.y0);
        const D = iso(block.x1, block.y1);
        const E = iso(block.x0, block.y1);

        [
            [A, B],
            [B, D],
            [D, E],
            [E, A],
        ].forEach(([P, Q]) => {
            const steps = Math.max(2, Math.round(Math.hypot(Q[0] - P[0], Q[1] - P[1]) / 4));

            for (let k = 0; k <= steps; k++) {
                if (k % 2) continue;

                const f = k / steps;
                r(c, P[0] + (Q[0] - P[0]) * f - 1, P[1] + (Q[1] - P[1]) * f - 1, 2, 2, edge);
            }
        });
    }

    /** Where a block's padlock stands, in world coordinates. */
    function landAnchor(block) {
        const [cx, cy] = iso((block.x0 + block.x1) / 2, (block.y0 + block.y1) / 2);

        return [cx, cy - 14];
    }

    function drawPadlock(c, cx, cy, hot) {
        const lift = hot ? 2 : 0;

        r(c, cx - 9, cy + 2 - lift, 19, 3, 'rgba(20,20,10,.25)');
        r(c, cx - 4, cy - 15 - lift, 8, 6, C.stoneDark);
        r(c, cx - 2, cy - 15 - lift, 3, 6, C.rockLight);
        r(c, cx - 8, cy - 11 - lift, 17, 12, hot ? shade(C.amber, 16) : C.amber);
        r(c, cx - 8, cy - 11 - lift, 17, 3, shade(C.amber, 28));
        r(c, cx - 8, cy - 1 - lift, 17, 2, shade(C.amber, -26));
        r(c, cx - 2, cy - 7 - lift, 3, 5, C.ink);
    }

    /**
     * The marker over ground that could be given back.
     *
     * A surveyor's peg with a rust plaque and a minus cut into it, standing at
     * the same height and taking the same press as the padlock beside it. The
     * two are deliberately nothing like each other: a padlock in a second
     * colour would be a padlock, and somebody scanning the map for where to
     * grow would press one thinking it was the other.
     */
    function drawGiveBackPeg(c, cx, cy, hot) {
        const lift = hot ? 2 : 0;

        r(c, cx - 8, cy + 2 - lift, 17, 3, 'rgba(20,20,10,.25)');

        /* The post, driven into the ground it stands on. */
        r(c, cx - 1, cy - 6 - lift, 3, 8, C.stoneDark);

        /* The plaque. */
        r(c, cx - 9, cy - 15 - lift, 19, 10, hot ? shade(C.rust, 18) : C.rust);
        r(c, cx - 9, cy - 15 - lift, 19, 2, shade(C.rust, 26));
        r(c, cx - 9, cy - 7 - lift, 19, 2, shade(C.rust, -28));

        /* And the minus, which is the whole of what it says. */
        r(c, cx - 5, cy - 11 - lift, 11, 3, C.cream);
    }
    /**
     * The land marker under the cursor, either kind.
     *
     * Both the padlock over ground that could be taken in and the peg over
     * ground that could be given back, because the map is where somebody
     * actually decides about a block — they are looking at it — and a marker
     * you can see but not press is worse than no marker.
     *
     * @return {{mode: 'take'|'give', i: number, block: object}|null}
     */
    function markerAt(clientX, clientY) {
        if (!arranging) return null;

        const rect = canvas.getBoundingClientRect();
        const lx = (clientX - rect.left) / zoom + camX;
        const ly = (clientY - rect.top) / zoom + camY;

        const hit = (block) => {
            const [cx, cy] = landAnchor(block);

            return Math.abs(lx - cx) <= 11 && ly <= cy + 3 && ly >= cy - 17;
        };

        for (let i = 0; i < land.length; i++) {
            if (hit(land[i])) return { mode: 'take', i, block: land[i] };
        }

        for (let i = 0; i < giveBack.length; i++) {
            if (hit(giveBack[i])) return { mode: 'give', i, block: giveBack[i] };
        }

        return null;
    }

    /*
     * Where every label and hover tag is pinned, worked out from the buildings
     * rather than from the last frame that happened to draw them.
     *
     * The frame loop sets these as it goes, which is enough while nothing
     * changes — but the moment a building moves or one is added, the labels are
     * drawn before the next frame has caught up and every name ends up over
     * somebody else's roof. Anything that changes the building list calls this
     * first.
     */
    function refreshAnchors() {
        places.forEach((place, i) => {
            const [ax, ay] = iso(place.gx + place.w / 2, place.gy + place.h / 2);
            anchors[i] = { x: ax, y: ay - place.height - 14 };
        });

        anchors.length = places.length;
    }

    function drawPlace({ place, i }, t) {
        const lift = i === hovered ? 3 : 0;
        const draw = SCENERY[place.sprite];

        if (draw) {
            draw(ctx, place, null, t);
            draw(pickCtx, place, `rgb(${i + 1},0,0)`, t);
        } else {
            drawIsoBuilding(ctx, place, lift, null);
            drawIsoBuilding(pickCtx, place, lift, `rgb(${i + 1},0,0)`);
        }

        const [ax, ay] = iso(place.gx + place.w / 2, place.gy + place.h / 2);
        anchors[i] = { x: ax, y: ay - place.height - 14 - lift };

        /* Your own office, marked. A pin above the roof that bobs, because a
           marker that holds still is furniture and one that moves is somebody
           pointing. */
        if (i === mine) {
            const bob = motion ? Math.round(Math.sin(t / 380) * 2) : 0;
            const my = ay - place.height - 20 - lift + bob;
            r(ctx, ax - 4, my, 9, 9, C.ink);
            r(ctx, ax - 3, my + 1, 7, 7, C.amber);
            r(ctx, ax - 1, my + 3, 3, 3, C.ink);
            for (let k = 0; k < 4; k++) r(ctx, ax - 1 + k, my + 9 + k, 3 - k, 1, C.ink);
        }
    }

    /*
     * The footprint under a building being dragged.
     *
     * Amber where it may land, rust where it may not. Drawn after everything
     * else so it is never behind the roof it is describing — this is the one
     * thing on the map that deliberately ignores the depth sort, because it is
     * not part of the compound, it is a hand pointing at it.
     */
    /*
     * A building that has been chosen but not put down.
     *
     * The real thing, drawn where it would land, over a footprint that says
     * whether it may. Showing the actual building rather than an outline is the
     * difference between placing something and filling in a form about it.
     */
    function drawPlacement(c) {
        const ok = canPlace(placing, placing.gx, placing.gy);

        outline(c, placing, placing.gx, placing.gy, ok);

        if (!ok) return;

        drawIsoBuilding(c, { ...placing, gx: placing.gx, gy: placing.gy }, 0, null);
    }

    function drawGhost(c) {
        outline(
            c,
            moving.place,
            moving.gx,
            moving.gy,
            canPlace(moving.place, moving.gx, moving.gy),
        );
    }

    /** A footprint on the ground: amber where it may land, rust where it may not. */
    function outline(c, box, gx, gy, ok) {
        const tint = ok ? 'rgba(242,169,59,.55)' : 'rgba(193,70,47,.55)';
        const edge = ok ? C.amber : C.rust;

        for (let x = gx; x < gx + box.w; x++) {
            for (let y = gy; y < gy + box.h; y++) {
                poly(c, [iso(x, y), iso(x + 1, y), iso(x + 1, y + 1), iso(x, y + 1)], tint);
            }
        }

        const A = iso(gx, gy);
        const B = iso(gx + box.w, gy);
        const D = iso(gx + box.w, gy + box.h);
        const E = iso(gx, gy + box.h);

        [
            [A, B],
            [B, D],
            [D, E],
            [E, A],
        ].forEach(([P, Q]) => {
            const steps = Math.max(2, Math.round(Math.hypot(Q[0] - P[0], Q[1] - P[1]) / 2));

            for (let i = 0; i <= steps; i++) {
                const f = i / steps;
                r(c, P[0] + (Q[0] - P[0]) * f - 1, P[1] + (Q[1] - P[1]) * f - 1, 2, 2, edge);
            }
        });
    }

    /* ------------------------------------------- 5. camera and hit testing */

    /*
     * The canvas is the viewport.
     *
     * It used to be a fixed 440x270 picture of the whole compound, scaled up
     * and centred, with the stage's own colour showing around it. Now it is
     * exactly as many logical pixels as fit on the glass at the current zoom,
     * and what is drawn into it is whatever the camera is looking at — so there
     * is no edge to the drawing and no empty margin around it.
     */
    function resize() {
        const sw = Math.max(1, stage.clientWidth);
        const sh = Math.max(1, stage.clientHeight);
        const b = worldBounds();

        /*
         * You may not zoom out past the sea that has been drawn.
         *
         * Rather than drawing more world for a wide monitor — which is
         * unbounded, and would be paid for on every frame by everybody — the
         * floor on zoom rises until what can be seen fits inside what exists.
         * On any ordinary screen that floor is 2, and the compound fills it.
         */
        minZoom = Math.max(1, Math.ceil(Math.max(sw / (b.maxX - b.minX), sh / (b.maxY - b.minY))));
        maxZoom = Math.max(minZoom + 1, Math.min(6, minZoom + 3));

        /*
         * The opening view is the whole compound, as close as it will go.
         *
         * Not one step in from the floor, which was the first attempt and cut
         * the Mayor's Office off the top of the screen — the compound is taller
         * than its own footprint, because every roof on it is. Whoever arrives
         * here should see all of it and then choose to go closer.
         */
        if (!placed) {
            const f = focusBox();

            zoom = Math.max(
                minZoom,
                Math.min(
                    maxZoom,
                    Math.floor(Math.min(sw / (f.maxX - f.minX), (sh - DOCK) / (f.maxY - f.minY))),
                ),
            );
        }

        zoom = Math.min(maxZoom, Math.max(minZoom, zoom));

        LW = Math.ceil(sw / zoom);
        LH = Math.ceil(sh / zoom);

        if (!placed) {
            centreOnTown();
            placed = true;
        }

        /* Setting the width resets every context flag, including this one. */
        canvas.width = pickCanvas.width = LW;
        canvas.height = pickCanvas.height = LH;
        ctx.imageSmoothingEnabled = false;
        pickCtx.imageSmoothingEnabled = false;

        canvas.style.left = '0px';
        canvas.style.top = '0px';
        canvas.style.width = LW * zoom + 'px';
        canvas.style.height = LH * zoom + 'px';

        clampCam();
        refreshAnchors();
        drawLabels();
        updateZoomButtons();
    }

    /*
     * What the opening view is of.
     *
     * The buildings, not the ground. A municipality that has taken in room for
     * next year's annexe has ground nothing stands on yet, and centring on the
     * ground puts the whole town in one corner and half a screen of empty field
     * in the other. Which is exactly what it did.
     *
     * Worked out rather than cached: it changes the moment somebody drags a
     * building, and every caller wants the current answer.
     */
    function focusBox() {
        if (places.length === 0) {
            return { minX: 0, maxX: COLS * TW, minY: 0, maxY: ROWS * TH };
        }

        let x0 = Infinity;
        let y0 = Infinity;
        let x1 = -Infinity;
        let y1 = -Infinity;
        let tallest = 0;

        places.forEach((place) => {
            x0 = Math.min(x0, place.gx);
            y0 = Math.min(y0, place.gy);
            x1 = Math.max(x1, place.gx + place.w);
            y1 = Math.max(y1, place.gy + place.h);
            tallest = Math.max(tallest, place.height);
        });

        /* Two cells of air around it, and room above for the tallest roof in
           the compound — which is what the back row would otherwise lose, the
           buildings being taller than their own footprints. */
        const pad = 2;

        return {
            minX: iso(x0 - pad, y1 + pad)[0],
            maxX: iso(x1 + pad, y0 - pad)[0],
            minY: iso(x0 - pad, y0 - pad)[1] - tallest - 20,
            maxY: iso(x1 + pad, y1 + pad)[1],
        };
    }

    /*
     * How much of the bottom of the stage the dock is standing on.
     *
     * The map runs edge to edge behind it, which is right — a control bar with
     * a grey strip under it is a toolbar, not a dock — but it does mean the
     * bottom hundred pixels are spoken for, and centring the town in the whole
     * stage put its front row underneath them.
     */
    const DOCK = 104;

    /** Put the town — not the ground it has room to grow into — in the middle. */
    function centreOnTown() {
        const f = focusBox();

        camX = (f.minX + f.maxX) / 2 - LW / 2;
        camY = (f.minY + f.maxY) / 2 - (LH - DOCK / zoom) / 2;
    }

    function clampCam() {
        const b = worldBounds();

        camX = Math.round(
            b.maxX - b.minX <= LW
                ? (b.minX + b.maxX - LW) / 2
                : Math.min(Math.max(camX, b.minX), b.maxX - LW),
        );

        camY = Math.round(
            b.maxY - b.minY <= LH
                ? (b.minY + b.maxY - LH) / 2
                : Math.min(Math.max(camY, b.minY), b.maxY - LH),
        );
    }

    /** Whether there is anywhere left to drag to. */
    function canRoam() {
        const b = worldBounds();

        return b.maxX - b.minX > LW || b.maxY - b.minY > LH;
    }

    /*
     * Zoom about a point, so the thing under the cursor stays under it.
     *
     * Zooming about the centre of the screen is the version everybody writes
     * first and nobody likes: you point at the Health Office, zoom, and the
     * Health Office has left.
     */
    function zoomTo(next, clientX, clientY) {
        next = Math.min(maxZoom, Math.max(minZoom, next));
        if (next === zoom) return;

        const rect = stage.getBoundingClientRect();
        const px = clientX === undefined ? stage.clientWidth / 2 : clientX - rect.left;
        const py = clientY === undefined ? stage.clientHeight / 2 : clientY - rect.top;

        /* The world point under that spot before and after. Holding it still is
           the whole of it. */
        const wx = camX + px / zoom;
        const wy = camY + py / zoom;

        zoom = next;

        camX = wx - px / zoom;
        camY = wy - py / zoom;

        resize();
    }

    function hitTest(clientX, clientY) {
        const rect = canvas.getBoundingClientRect();
        const lx = Math.floor((clientX - rect.left) / zoom);
        const ly = Math.floor((clientY - rect.top) / zoom);

        if (lx < 0 || ly < 0 || lx >= LW || ly >= LH) return -1;

        const d = pickCtx.getImageData(lx, ly, 1, 1).data;

        return d[3] > 200 && d[1] === 0 && d[2] === 0 && d[0] > 0 ? d[0] - 1 : -1;
    }

    /** World point to a position within the stage, in CSS pixels. */
    const onScreen = (wx, wy) => [(wx - camX) * zoom, (wy - camY) * zoom];

    /*
     * Screen point to the grid cell under it.
     *
     * The projection run backwards about the camera. isoDelta does the same
     * arithmetic on a distance, which is what a drag needs; this is what
     * carrying something needs, because the thing follows the cursor rather
     * than the cursor's travel.
     */
    function cellAt(clientX, clientY) {
        const rect = canvas.getBoundingClientRect();
        const wx = (clientX - rect.left) / zoom + camX;
        const wy = (clientY - rect.top) / zoom + camY;

        return [
            Math.floor((wx / (TW / 2) + wy / (TH / 2)) / 2),
            Math.floor((wy / (TH / 2) - wx / (TW / 2)) / 2),
        ];
    }

    /* ------------------------------------- 6. labels, the tag, the keyboard */

    /*
     * Names in HTML over the canvas.
     *
     * Twenty-one of them, all on screen at once, which is exactly why they are
     * not drawn into the canvas: pixel lettering at this size is mud, and these
     * have to be read. Repositioned on pan and resize only — a DOM write per
     * label per frame is the one thing that would make this stutter.
     *
     * Twenty-one names over a map this size do not all fit, and the version
     * that simply drew them all was a thicket somebody had to read twice. So
     * they are laid out greedily: your own office first, then front to back,
     * and any name that would land on top of one already placed is dropped.
     * Nothing is lost by dropping one — hovering the building still names it,
     * and so does the panel — whereas a name printed through another name
     * costs both of them.
     */
    function drawLabels() {
        labelsEl.textContent = '';

        const kept = [];

        /* Your office first so it is never the one dropped, then nearest the
           front, because a name belongs to the building the eye is on. */
        const order = inDepthOrder.filter(({ place }) => place.kind === 'office').reverse();

        if (mine >= 0) {
            order.unshift(
                ...order.splice(
                    order.findIndex((o) => o.i === mine),
                    1,
                ),
            );
        }

        order.forEach(({ place, i }) => {
            const anchor = anchors[i];
            if (!anchor) return;

            const [lx, ly] = onScreen(anchor.x, anchor.y - 4);

            /* Off the glass entirely: nothing to lay out, and nothing to let
               crowd out a label that is on it. */
            if (
                lx < -160 ||
                ly < -40 ||
                lx > stage.clientWidth + 160 ||
                ly > stage.clientHeight + 40
            ) {
                return;
            }

            const el = document.createElement('div');
            el.className = 'compound-label';
            el.textContent = place.name;
            if (i === mine) el.dataset.mine = 'true';
            el.style.left = lx + 'px';
            el.style.top = ly + 'px';
            labelsEl.appendChild(el);

            /* Measured after insertion rather than estimated from the string:
               the display face is loaded by then, and a guess at its width is
               exactly the kind that is wrong for "Sangguniang Bayan". */
            const box = el.getBoundingClientRect();
            const clash = kept.some(
                (k) =>
                    box.left < k.right + 4 &&
                    k.left - 4 < box.right &&
                    box.top < k.bottom + 2 &&
                    k.top - 2 < box.bottom,
            );

            if (clash) {
                el.remove();
                return;
            }

            kept.push(box);
        });
    }

    function showTag(i) {
        const place = places[i];
        const anchor = anchors[i];

        if (!place || !anchor) {
            tagEl.dataset.show = 'false';
            return;
        }

        tagEl.textContent = place.name;
        const b = document.createElement('b');
        b.textContent = place.blurb;
        tagEl.appendChild(b);

        const [lx, ly] = onScreen(anchor.x, anchor.y - 12);
        tagEl.style.left = lx + 'px';
        tagEl.style.top = Math.max(40, ly) + 'px';
        tagEl.dataset.show = 'true';
    }

    /* --------------------------------------------------------- interaction */

    const wipe = createWipe(() => motion);

    /* Where a guest is sent to sign in. From the markup, because a route name
       is the Blade component's business and never this file's. */
    const signInHref = stage.dataset.signIn || '';

    function heading(text) {
        const h = document.createElement('h3');
        h.className = 'world-extra-head';
        h.textContent = text;

        return h;
    }

    const panel = createPanel({
        wipe,
        onOpen: () => {
            tagEl.dataset.show = 'false';
        },

        /*
         * What is inside an office, under the photograph.
         *
         * Three blocks, in the order somebody needs them: the nameplate facts,
         * what this office has posted for the public, and — only in your own
         * office — the screens you can open. Another office's building has no
         * doors, which is not a refusal: there is nothing behind it for
         * somebody who does not work there.
         */
        extra(host, place) {
            if (place.kind !== 'office') return;

            if (place.facts && place.facts.length) {
                const dl = document.createElement('dl');
                dl.className = 'world-facts';

                place.facts.forEach((fact) => {
                    const dt = document.createElement('dt');
                    dt.textContent = fact.label;
                    const dd = document.createElement('dd');
                    dd.textContent = fact.value;
                    dl.append(dt, dd);
                });

                host.appendChild(dl);
            }

            if (place.notices && place.notices.length) {
                host.appendChild(heading('Posted by this office'));

                const ul = document.createElement('ul');
                ul.className = 'world-links';

                place.notices.forEach((notice) => {
                    const li = document.createElement('li');
                    const a = document.createElement('a');
                    a.href = notice.url;
                    a.textContent = notice.title;
                    li.appendChild(a);
                    ul.appendChild(li);
                });

                host.appendChild(ul);
            }

            if (place.links && place.links.length) {
                host.appendChild(heading('Your screens'));

                const ul = document.createElement('ul');
                ul.className = 'world-links doors';

                place.links.forEach((link) => {
                    const li = document.createElement('li');
                    const a = document.createElement('a');
                    a.href = link.url;
                    a.textContent = link.label;
                    li.appendChild(a);
                    ul.appendChild(li);
                });

                host.appendChild(ul);
            } else if (place.mine === false && !data.you) {
                const p = document.createElement('p');
                p.className = 'world-signin';
                const a = document.createElement('a');
                a.href = signInHref;
                a.textContent = 'Sign in';
                p.append(a, document.createTextNode(' to open anything behind this door.'));
                host.appendChild(p);
            }
        },
    });

    function visit(place) {
        npc.say(place.say);
        panel.open(place);
    }

    /* One gesture at a time, tracked without reference to which pointer it is —
       the same discipline as the town's, and for the same reason: a pointerup
       whose id does not match is silently dropped, which leaves a drag stuck
       open and swallows the click that should have followed it. */
    let drag = null;

    canvas.addEventListener('pointerdown', (ev) => {
        if (drag) return;

        /* A building being placed is put down on the press, not on a drag, and
           nothing else happens until it is. */
        if (placing) {
            putDown(ev);

            return;
        }

        /* And a land marker is pressed, not dragged. It opens the block rather
           than acting on it: taking ground in and giving it back are both worth
           a sentence and a button, not a stray click on a small icon. */
        const marker = markerAt(ev.clientX, ev.clientY);

        if (marker) {
            openBlock(marker.mode, marker.block);

            return;
        }

        /* A loaded brush lays ground and does nothing else — no panning, no
           picking a building up. Somebody paving a path is paving a path.

           Only while arranging, which is belt and braces: leaving arrange mode
           puts the brush down, so this should never be reachable with one still
           loaded. It is here because "a stray click paved something" is the
           exact failure this whole section exists to stop. */
        if (brush && arranging) {
            stroke = new Map();
            paintAt(ev.clientX, ev.clientY);

            try {
                canvas.setPointerCapture(ev.pointerId);
            } catch {
                /* Same old iPad as everywhere else. */
            }

            return;
        }

        const i = arranging ? hitTest(ev.clientX, ev.clientY) : -1;

        drag = {
            x: ev.clientX,
            y: ev.clientY,
            camX,
            camY,
            moved: false,
            building: i,
        };

        if (i >= 0) {
            moving = {
                place: places[i],
                i,
                gx: places[i].gx,
                gy: places[i].gy,
            };
            tagEl.dataset.show = 'false';
        }

        try {
            canvas.setPointerCapture(ev.pointerId);
        } catch {
            /* Safari on an old iPad. Dragging still works through the events
               below; only capture outside the element is lost. */
        }
    });

    canvas.addEventListener('pointermove', (ev) => {
        /* Carrying a building: the cell under the cursor is where it would go,
           which is a point rather than a distance and so needs the projection
           run backwards about the camera rather than about the grab. */
        if (placing) {
            const [gx, gy] = cellAt(ev.clientX, ev.clientY);

            placing.gx = Math.max(0, Math.min(COLS - placing.w, gx - Math.floor(placing.w / 2)));
            placing.gy = Math.max(0, Math.min(ROWS - placing.h, gy - Math.floor(placing.h / 2)));

            return;
        }

        if (stroke) {
            paintAt(ev.clientX, ev.clientY);

            return;
        }

        const marker = markerAt(ev.clientX, ev.clientY);
        const overTake = marker?.mode === 'take' ? marker.i : -1;
        const overGive = marker?.mode === 'give' ? marker.i : -1;

        if (overTake !== hoveredLand || overGive !== hoveredGiveBack) {
            hoveredLand = overTake;
            hoveredGiveBack = overGive;
            canvas.style.cursor = marker ? 'pointer' : 'default';
        }

        if (marker) {
            tagEl.dataset.show = 'false';

            return;
        }

        if (drag) {
            const dx = (ev.clientX - drag.x) / zoom;
            const dy = (ev.clientY - drag.y) / zoom;

            /* Dragging a building: the pointer's travel becomes a whole number
               of cells, and the ghost follows. Nothing is committed until the
               drop, so a drag over the sea and back again costs nothing. */
            if (moving) {
                const [gdx, gdy] = isoDelta(dx, dy);
                const gx = Math.round(moving.place.gx + gdx);
                const gy = Math.round(moving.place.gy + gdy);

                if (gx !== moving.gx || gy !== moving.gy) {
                    moving.gx = gx;
                    moving.gy = gy;
                }

                if (Math.abs(dx) > 3 || Math.abs(dy) > 3) drag.moved = true;

                return;
            }

            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) {
                drag.moved = true;
                canvas.classList.add('dragging');
                camX = drag.camX - dx;
                camY = drag.camY - dy;
                clampCam();
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

        canvas.style.cursor = arranging
            ? i >= 0
                ? 'move'
                : 'default'
            : i >= 0
              ? 'pointer'
              : canRoam()
                ? 'grab'
                : 'default';
    });

    const endDrag = (ev) => {
        /* A stroke is one request, however many squares long it was. */
        if (stroke) {
            const laid = [...stroke.values()];
            stroke = null;

            if (laid.length) sendGround(laid);

            return;
        }

        if (!drag) return;

        const wasDrag = drag.moved;
        const dropped = moving;

        drag = null;
        moving = null;
        canvas.classList.remove('dragging');

        if (dropped) {
            if (wasDrag) {
                drop(dropped);
            } else {
                /* A press in arrange mode that never moved is not a visit —
                   somebody rearranging is not browsing — but it is not nothing
                   either. It is the only way to take a building down. */
                openBuilding(dropped.place);
            }

            return;
        }

        if (wasDrag) return;

        const i = hitTest(ev.clientX, ev.clientY);

        if (i >= 0) {
            visit(places[i]);
        } else {
            panel.close();
        }
    };

    canvas.addEventListener('pointerup', endDrag);
    canvas.addEventListener('pointercancel', () => {
        drag = null;
        moving = null;
        canvas.classList.remove('dragging');
    });

    canvas.addEventListener('pointerleave', () => {
        hovered = -1;
        tagEl.dataset.show = 'false';
    });

    /* ------------------------------------------------------------- zooming */

    /*
     * The wheel zooms rather than scrolls.
     *
     * This is a map, and on a map a wheel means closer or further away — which
     * is also why the page below it is reachable by the skip link at the top,
     * the header's own buttons, and the link in the hint. Trapping the wheel
     * over a full-screen element is a real cost and it is being paid on
     * purpose.
     */
    stage.addEventListener(
        'wheel',
        (ev) => {
            if (ev.ctrlKey) return;

            ev.preventDefault();
            zoomTo(zoom + (ev.deltaY < 0 ? 1 : -1), ev.clientX, ev.clientY);
        },
        { passive: false },
    );

    /* Double-click to go in, the way every map does. */
    canvas.addEventListener('dblclick', (ev) => {
        if (arranging) return;

        zoomTo(zoom + 1, ev.clientX, ev.clientY);
    });

    /*
     * Pinch, for the half of the municipality reading this on a phone.
     *
     * Two pointers down means the gesture is a pinch and not a drag, so the pan
     * in flight is abandoned — otherwise the map lurches sideways by whatever
     * the second thumb happened to land at.
     */
    const touches = new Map();
    let pinch = 0;

    stage.addEventListener('pointerdown', (ev) => {
        if (ev.pointerType !== 'touch') return;

        touches.set(ev.pointerId, ev);

        if (touches.size === 2) {
            drag = null;
            moving = null;
            pinch = spread();
        }
    });

    stage.addEventListener('pointermove', (ev) => {
        if (ev.pointerType !== 'touch' || !touches.has(ev.pointerId)) return;

        touches.set(ev.pointerId, ev);

        if (touches.size !== 2 || !pinch) return;

        const now = spread();
        const mid = midpoint();

        /* A full step of zoom per half again as far apart, which is roughly
           where a thumb and forefinger expect it. */
        if (now > pinch * 1.5) {
            zoomTo(zoom + 1, mid[0], mid[1]);
            pinch = now;
        } else if (now < pinch / 1.5) {
            zoomTo(zoom - 1, mid[0], mid[1]);
            pinch = now;
        }
    });

    const forgetTouch = (ev) => {
        touches.delete(ev.pointerId);
        if (touches.size < 2) pinch = 0;
    };

    stage.addEventListener('pointerup', forgetTouch);
    stage.addEventListener('pointercancel', forgetTouch);

    function spread() {
        const [a, b] = [...touches.values()];

        return Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY) || 1;
    }

    function midpoint() {
        const [a, b] = [...touches.values()];

        return [(a.clientX + b.clientX) / 2, (a.clientY + b.clientY) / 2];
    }

    const zoomIn = document.getElementById('compoundZoomIn');
    const zoomOut = document.getElementById('compoundZoomOut');

    if (zoomIn) zoomIn.addEventListener('click', () => zoomTo(zoom + 1));
    if (zoomOut) zoomOut.addEventListener('click', () => zoomTo(zoom - 1));

    function updateZoomButtons() {
        if (zoomIn) zoomIn.disabled = zoom >= maxZoom;
        if (zoomOut) zoomOut.disabled = zoom <= minZoom;
    }

    /* ------------------------------------------------------------ the sheet */

    /*
     * One panel, three jobs: finding an office, putting a building up, and
     * taking one down. Three overlays would be three sets of the same chrome
     * and three chances for two of them to be open at once.
     */
    const sheetEl = document.getElementById('compoundSheet');
    const sheetTitle = document.getElementById('compoundSheetTitle');
    const sheetBody = document.getElementById('compoundSheetBody');

    function openSheet(title, build) {
        sheetTitle.textContent = title;
        sheetBody.textContent = '';
        build(sheetBody);

        sheetEl.hidden = false;
        void sheetEl.offsetHeight;
        sheetEl.dataset.open = 'true';
    }

    function closeSheet() {
        sheetEl.dataset.open = 'false';
        sheetEl.hidden = true;
        sheetBody.textContent = '';
    }

    document.getElementById('compoundSheetClose').addEventListener('click', closeSheet);

    const el = (tag, className, text) => {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;

        return node;
    };

    /*
     * Finding an office.
     *
     * What the list of cards under the map used to be for. Matches on the name
     * and on the code, because half the municipality calls the Municipal
     * Treasurer's Office "MTO" and the search box should not care which.
     */
    function openFind() {
        openSheet('Find an office', (host) => {
            const input = el('input', 'compound-search');
            input.type = 'search';
            input.placeholder = 'Name or code…';
            input.setAttribute('aria-label', 'Find an office');

            const results = el('ul', 'compound-results');

            const draw = () => {
                const term = input.value.trim().toLowerCase();
                results.textContent = '';

                const found = places
                    .map((place, i) => ({ place, i }))
                    .filter(({ place }) => place.kind === 'office')
                    .filter(
                        ({ place }) =>
                            term === '' ||
                            place.name.toLowerCase().includes(term) ||
                            (place.blurb || '').toLowerCase().includes(term) ||
                            (place.say || '').toLowerCase().includes(term),
                    );

                if (found.length === 0) {
                    results.appendChild(el('li', 'compound-empty', 'No office matches that.'));

                    return;
                }

                found.forEach(({ place, i }) => {
                    const li = el('li');
                    const button = el('button', 'compound-result');
                    button.type = 'button';
                    button.appendChild(el('b', null, place.name));
                    button.appendChild(el('span', null, place.blurb));
                    button.addEventListener('click', () => {
                        closeSheet();
                        focusOn(i);
                    });

                    li.appendChild(button);
                    results.appendChild(li);
                });
            };

            input.addEventListener('input', draw);

            /* Enter takes the first match, which is what somebody who typed
               three letters and stopped is asking for. */
            input.addEventListener('keydown', (ev) => {
                if (ev.key !== 'Enter') return;

                ev.preventDefault();
                results.querySelector('button')?.click();
            });

            host.append(input, results);
            draw();
            setTimeout(() => input.focus(), 0);
        });
    }

    /* ------------------------------------------------------- the arranging */

    /* Absent for anybody without settings.manage — Blade does not render it —
       and everything below is then dead code that never runs. */
    const arrangeBtn = document.getElementById('compoundArrange');
    const toastEl = document.getElementById('compoundToast');

    /*
     * Whether a footprint may land here.
     *
     * The same two rules the server enforces — inside the compound's own
     * ground, and off every other building — and this copy exists so the ghost
     * turns red before the drop rather than after it. It is feedback, not
     * enforcement: see App\Http\Controllers\CompoundLayoutController, which
     * re-checks everything and refuses a request that ignored this.
     */
    function canPlace(place, gx, gy) {
        for (let x = gx; x < gx + place.w; x++) {
            for (let y = gy; y < gy + place.h; y++) {
                /* Inside the grid *and* on ground the municipality has taken
                   in. The bounds check alone let the ghost sit amber over a
                   field and then be refused on the way down, which is the exact
                   disagreement this copy of the rule exists to prevent. */
                if (!isOpenGround(x, y)) return false;
            }
        }

        return !places.some(
            (other) =>
                other !== place &&
                gx < other.gx + other.w &&
                other.gx < gx + place.w &&
                gy < other.gy + other.h &&
                other.gy < gy + place.h,
        );
    }

    function drop(dropped) {
        if (!canPlace(dropped.place, dropped.gx, dropped.gy)) {
            toast('It cannot stand there.', 'bad');

            return;
        }

        /* Where it was, kept so a refusal can put it back. The two checks agree
           almost always — they are the same two rules — but "almost" is the
           word that matters: if they ever disagree, the map on screen must end
           up saying what the database says, not what this file hoped. */
        const from = dirty.get(dropped.place.building)?.from ?? {
            gx: dropped.place.gx,
            gy: dropped.place.gy,
        };

        dropped.place.gx = dropped.gx;
        dropped.place.gy = dropped.gy;

        dirty.set(dropped.place.building, {
            id: dropped.place.building,
            gx: dropped.gx,
            gy: dropped.gy,
            from,
        });

        sortByDepth();
        rebuildProps();
        refreshAnchors();
        drawLabels();
        queueSave();
    }

    /** Put everything in a refused batch back where the server still has it. */
    function revert(batch) {
        batch.forEach((move) => {
            const place = places.find((p) => p.building === move.id);
            if (!place) return;

            place.gx = move.from.gx;
            place.gy = move.from.gy;
        });

        sortByDepth();
        rebuildProps();
        refreshAnchors();
        drawLabels();
    }

    /*
     * Saved a moment after the last drop, not on every one.
     *
     * Arranging a compound is a run of small corrections — nudge, nudge,
     * nudge — and a request per nudge would write twenty audit entries for one
     * decision. Waiting for the hand to stop makes it one.
     */
    let saveTimer = null;

    function queueSave() {
        toast('Moved.', 'pending');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(save, 700);
    }

    async function save() {
        if (dirty.size === 0) return;

        const batch = [...dirty.values()];
        dirty.clear();

        try {
            const response = await fetch(arrangeBtn.dataset.url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({
                    buildings: batch.map(({ id, gx, gy }) => ({ id, gx, gy })),
                }),
            });

            if (response.ok) {
                toast('Saved.', 'good');

                return;
            }

            /*
             * The server refused, so the screen goes back to what the server
             * still holds. Anything else leaves a compound that looks arranged
             * and is not, until somebody reloads and their afternoon's work
             * jumps back into place.
             *
             * Its reason is better than anything this file could invent — it
             * names the building.
             */
            revert(batch);

            const body = await response.json().catch(() => null);
            toast(body?.message || 'That arrangement could not be saved.', 'bad');
        } catch {
            revert(batch);
            toast('Could not reach the server. Nothing was moved.', 'bad');
        }
    }

    let toastTimer = null;

    function toast(message, tone) {
        if (!toastEl) return;

        toastEl.textContent = message;
        toastEl.dataset.tone = tone;
        toastEl.dataset.show = 'true';

        clearTimeout(toastTimer);
        toastTimer = setTimeout(
            () => {
                toastEl.dataset.show = 'false';
            },
            tone === 'bad' ? 6000 : 2400,
        );
    }

    /* ---------------------------------------------------------- putting up */

    const addBtn = document.getElementById('compoundAdd');

    /*
     * Choosing what to build.
     *
     * A design, an office and a colour, in that order, because that is the
     * order somebody decides them in: you know you need a building before you
     * know it is teal. Nothing is sent until it has been put down somewhere.
     */
    /*
     * A piece, drawn small.
     *
     * The picker used to be a grid of names, which is a list of words for a
     * screen whose whole subject is what things look like — nobody could tell a
     * hall from an annex without placing one. Every tile now draws the actual
     * piece with the actual code, so the picture in the panel and the thing on
     * the map cannot disagree.
     */
    function drawPreview(canvas, template, colour) {
        const c = canvas.getContext('2d');
        const cw = canvas.width;
        const ch = canvas.height;

        c.imageSmoothingEnabled = false;
        c.clearRect(0, 0, cw, ch);

        /* A patch of ground under it, so a flagpole is not floating. */
        c.setTransform(1, 0, 0, 1, 0, 0);
        r(c, 0, 0, cw, ch, C.grass);

        const piece = {
            gx: 0,
            gy: 0,
            w: template.w,
            h: template.h,
            height: template.height,
            style: template.style,
            wall: template.paint ? colour.wall : undefined,
            roof: template.paint ? colour.roof : undefined,
        };

        /* Centre the footprint, and leave the top two thirds for whatever
           stands on it — which is where the difference between these is. */
        const [fx, fy] = iso(piece.w / 2, piece.h / 2);

        c.setTransform(1, 0, 0, 1, Math.round(cw / 2 - fx), Math.round(ch * 0.72 - fy));

        for (let x = -1; x <= piece.w; x++) {
            for (let y = -1; y <= piece.h; y++) {
                const fill = (x + y) % 2 ? C.grass : C.grassAlt;

                poly(c, [iso(x, y), iso(x + 1, y), iso(x + 1, y + 1), iso(x, y + 1)], fill);
            }
        }

        const draw = SCENERY[template.sprite];

        if (draw) {
            draw(c, piece, null, 0);
        } else {
            drawIsoBuilding(c, piece, 0, null);
        }

        c.setTransform(1, 0, 0, 1, 0, 0);
    }

    /**
     * The emblem on a category tab.
     *
     * Five of the seven name a template and are drawn with the template's own
     * code, so the Planting tab is a tree and not the word "Planting" — the
     * same trick the piece grid uses, at a smaller size. The other two place
     * nothing at all: Ground shows a square of paving meeting a path, and Land
     * shows a block half taken in, half still country, which is the whole of
     * what that tab is about.
     */
    function drawCategoryIcon(canvas, category, colour) {
        const c = canvas.getContext('2d');
        const cw = canvas.width;
        const ch = canvas.height;

        c.imageSmoothingEnabled = false;
        c.clearRect(0, 0, cw, ch);
        c.setTransform(1, 0, 0, 1, 0, 0);
        r(c, 0, 0, cw, ch, C.grass);

        if (category.icon === 'paving' || category.icon === 'plot') {
            c.setTransform(1, 0, 0, 1, Math.round(cw / 2), Math.round(ch / 2) - 8);

            for (let x = 0; x < 2; x++) {
                for (let y = 0; y < 2; y++) {
                    /* Paving: the plaza chequer against the path it runs into.
                       Land: two squares of compound and two still country, the
                       boundary running between them. */
                    const fill =
                        category.icon === 'paving'
                            ? x + y === 0
                                ? C.road
                                : (x + y) % 2
                                  ? C.plaza
                                  : C.plazaAlt
                            : x + y < 2
                              ? (x + y) % 2
                                  ? C.grass
                                  : C.grassAlt
                              : shade(C.grassDark, x === y ? 6 : -14);

                    poly(c, [iso(x, y), iso(x + 1, y), iso(x + 1, y + 1), iso(x, y + 1)], fill);
                }
            }

            /*
             * The line between held ground and country, and a padlock over the
             * country side.
             *
             * The same padlock the map draws over a block nobody has taken in,
             * because two symbols for one idea is one too many — and without it
             * this tab and the Ground tab were both a flat patch of diamonds
             * and told apart only by reading their labels, which is the problem
             * drawing them was meant to solve.
             */
            if (category.icon === 'plot') {
                const track = '#b9a888';

                poly(c, [iso(0, 2), iso(2, 2), iso(2, 2 + 0.2), iso(0, 2 + 0.2)], track);
                poly(c, [iso(2, 0), iso(2 + 0.2, 0), iso(2 + 0.2, 2), iso(2, 2)], track);

                const [px, py] = iso(2.5, 2.5);
                drawPadlock(c, px, py - 5, false);
            }

            c.setTransform(1, 0, 0, 1, 0, 0);

            return;
        }

        const template = (data.templates || []).find((t) => t.id === category.icon);

        if (!template) return;

        const piece = {
            gx: 0,
            gy: 0,
            w: template.w,
            h: template.h,
            height: template.height,
            style: template.style,
            wall: template.paint ? colour.wall : undefined,
            roof: template.paint ? colour.roof : undefined,
        };

        const [fx, fy] = iso(piece.w / 2, piece.h / 2);
        c.setTransform(1, 0, 0, 1, Math.round(cw / 2 - fx), Math.round(ch * 0.78 - fy));

        for (let x = 0; x < piece.w; x++) {
            for (let y = 0; y < piece.h; y++) {
                const fill = (x + y) % 2 ? C.grass : C.grassAlt;

                poly(c, [iso(x, y), iso(x + 1, y), iso(x + 1, y + 1), iso(x, y + 1)], fill);
            }
        }

        const draw = SCENERY[template.sprite];

        if (draw) {
            draw(c, piece, null, 0);
        } else {
            drawIsoBuilding(c, piece, 0, null);
        }

        c.setTransform(1, 0, 0, 1, 0, 0);
    }

    function openAdd() {
        openSheet('Put up a building', (host) => {
            const templates = data.templates || [];
            const palette = data.palette || [];
            const categories = data.categories || [];

            let category = categories[0];
            let template = templates.find((t) => t.category === category.id);
            let colour = palette[0];
            let officeId = '';
            let newName = '';

            /*
             * The tabs. Ground and land are in here too: "make the compound
             * bigger" and "put something on it" are the same errand, and two
             * panels for one errand is one panel too many.
             *
             * Drawn, not written. This was a strip of small words that wrapped
             * onto a second line at seven categories and looked like it had
             * broken rather than been laid out — and on a screen whose entire
             * subject is what things look like, the one part of it made of
             * nothing but text was the part telling you the least. Each tab is
             * now the thing it holds, drawn with that thing's own code, on an
             * even grid that does not wrap by accident.
             */
            const tabs = el('div', 'compound-cats');

            categories.forEach((cat) => {
                const tab = el('button', 'compound-cat');
                tab.type = 'button';
                tab.title = cat.blurb;
                tab.dataset.on = String(cat === category);

                const icon = document.createElement('canvas');
                icon.width = 44;
                icon.height = 34;
                icon.setAttribute('aria-hidden', 'true');
                drawCategoryIcon(icon, cat, palette[0]);

                tab.append(icon, el('b', null, cat.name));

                tab.addEventListener('click', () => {
                    category = cat;
                    tabs.querySelectorAll('button').forEach((b) => (b.dataset.on = 'false'));
                    tab.dataset.on = 'true';
                    showCategory();
                });

                tabs.appendChild(tab);
            });

            host.appendChild(tabs);

            const pane = el('div', 'compound-pane');
            host.appendChild(pane);

            function showCategory() {
                pane.textContent = '';

                if (category.id === 'ground') return showGround(pane);
                if (category.id === 'land') return showLand(pane);

                return showPieces(pane);
            }

            /* ------------------------------------------------ the pieces -- */

            function showPieces(host) {
                const shown = templates.filter((t) => t.category === category.id);

                if (!shown.includes(template)) template = shown[0];

                const grid = el('div', 'compound-templates');

                shown.forEach((t) => {
                    const button = el('button', 'compound-template');
                    button.type = 'button';
                    button.dataset.on = String(t === template);

                    /* The piece itself, drawn with the piece's own code. A grid
                       of names told nobody the difference between a hall and an
                       annex. */
                    const preview = document.createElement('canvas');
                    preview.width = 78;
                    preview.height = 58;
                    preview.setAttribute('aria-hidden', 'true');
                    drawPreview(preview, t, colour);

                    button.append(preview, el('b', null, t.name));
                    button.title = t.blurb;

                    button.addEventListener('click', () => {
                        template = t;
                        grid.querySelectorAll('button').forEach((b) => (b.dataset.on = 'false'));
                        button.dataset.on = 'true';
                        officeFields.hidden = t.kind !== 'office';
                        paintFields.hidden = !t.paint;
                    });

                    grid.appendChild(button);
                });

                host.appendChild(grid);

                /* Which office it is for. Only for the designs that are offices
                   — a flagpole belongs to nobody. */
                const officeFields = el('div', 'compound-field');
                officeFields.hidden = template.kind !== 'office';

                officeFields.appendChild(el('h3', 'compound-sheet-head-3', 'For which office'));

                const select = el('select', 'compound-select');
                const none = el('option', null, 'Choose an office…');
                none.value = '';
                select.appendChild(none);

                (data.vacant || []).forEach((office) => {
                    const option = el('option', null, office.name + ' · ' + office.code);
                    option.value = String(office.id);
                    select.appendChild(option);
                });

                if (data.canCreateOffices) {
                    const fresh = el('option', null, 'A new office…');
                    fresh.value = 'new';
                    select.appendChild(fresh);
                }

                const nameField = el('input', 'compound-input');
                nameField.type = 'text';
                nameField.placeholder = 'Name of the new office';
                nameField.maxLength = 255;
                nameField.autocomplete = 'off';
                nameField.hidden = true;

                const codeField = el('input', 'compound-input');
                codeField.type = 'text';
                codeField.placeholder = 'Code (optional — MO, MTO…)';
                codeField.maxLength = 12;
                codeField.autocomplete = 'off';
                codeField.hidden = true;

                select.addEventListener('change', () => {
                    officeId = select.value;
                    nameField.hidden = codeField.hidden = officeId !== 'new';
                });

                nameField.addEventListener('input', () => (newName = nameField.value));

                officeFields.append(select, nameField, codeField);

                if ((data.vacant || []).length === 0 && !data.canCreateOffices) {
                    officeFields.appendChild(
                        el(
                            'p',
                            'compound-note',
                            'Every office already has a building in the compound.',
                        ),
                    );
                }

                host.appendChild(officeFields);

                /* The paint. Only for the pieces that take it: a tree is the
                   colour a tree is. */
                const paintFields = el('div', 'compound-field');
                paintFields.hidden = !template.paint;
                paintFields.appendChild(el('h3', 'compound-sheet-head-3', 'Colour'));

                const swatches = el('div', 'compound-swatches');

                palette.forEach((p) => {
                    const button = el('button', 'compound-swatch');
                    button.type = 'button';
                    button.title = p.name;
                    button.setAttribute('aria-label', p.name);
                    button.style.background = p.wall;
                    button.style.borderBottom = '10px solid ' + p.roof;
                    button.dataset.on = String(p === colour);

                    button.addEventListener('click', () => {
                        colour = p;
                        swatches
                            .querySelectorAll('button')
                            .forEach((b) => (b.dataset.on = 'false'));
                        button.dataset.on = 'true';

                        /* Every preview repaints, so the picker always shows the
                           colour the thing would actually be. */
                        grid.querySelectorAll('button').forEach((b, i) => {
                            drawPreview(b.querySelector('canvas'), shown[i], colour);
                        });
                    });

                    swatches.appendChild(button);
                });

                paintFields.appendChild(swatches);
                host.appendChild(paintFields);

                const go = el('button', 'compound-go', 'Place it on the map →');
                go.type = 'button';
                go.addEventListener('click', () => {
                    if (template.kind === 'office' && officeId === '') {
                        toast('Choose which office this building is for.', 'bad');

                        return;
                    }

                    if (officeId === 'new' && newName.trim() === '') {
                        toast('Give the new office a name.', 'bad');

                        return;
                    }

                    closeSheet();
                    startPlacing({
                        template,
                        colour,
                        departmentId:
                            officeId === 'new' || officeId === '' ? null : Number(officeId),
                        createOffice: officeId === 'new',
                        officeName: officeId === 'new' ? newName.trim() : null,
                        officeCode:
                            officeId === 'new' ? codeField.value.trim().toUpperCase() : null,
                    });
                });

                host.appendChild(go);
            }

            /* ------------------------------------------------ the ground -- */

            /*
             * Paths and paving.
             *
             * Pick a surface, then drag across the compound to lay it. The same
             * gesture lifts it again with the grass brush, because putting it
             * back has to be as easy as putting it down or nobody experiments.
             */
            function showGround(host) {
                host.appendChild(
                    el(
                        'p',
                        'compound-note',
                        'Pick a surface and the panel gets out of the way. Then drag across the ' +
                            'compound to lay it, and press Done — or Escape — when you have ' +
                            'finished.',
                    ),
                );

                const list = el('div', 'compound-brushes');

                (data.brushes || []).forEach((b) => {
                    const button = el('button', 'compound-brush');
                    button.type = 'button';
                    button.dataset.on = String(brush === b.id);

                    const swatch = document.createElement('canvas');
                    swatch.width = 52;
                    swatch.height = 34;
                    swatch.setAttribute('aria-hidden', 'true');
                    drawBrushSwatch(swatch, b.id);

                    button.append(swatch, el('b', null, b.name), el('span', null, b.blurb));

                    /*
                     * Picking a surface closes the panel.
                     *
                     * You cannot paint through it — it covers the left third of
                     * the map — so leaving it open was leaving somebody one step
                     * short of the thing they had just asked for, and it made
                     * Escape mean "close the panel" at exactly the moment the
                     * panel was telling them Escape meant "put the brush down".
                     */
                    button.addEventListener('click', () => {
                        takeUpTheBrush(b);
                        closeSheet();
                    });

                    list.appendChild(button);
                });

                host.appendChild(list);

                if (brush) {
                    const stop = el('button', 'compound-quiet', 'Put the brush down');
                    stop.type = 'button';
                    stop.addEventListener('click', () => {
                        putTheBrushDown();
                        list.querySelectorAll('button').forEach((x) => (x.dataset.on = 'false'));
                        stop.remove();
                    });

                    host.appendChild(stop);
                }
            }

            /* -------------------------------------------------- the land -- */

            /*
             * Taking in more ground.
             *
             * The compound is only as big as the land the municipality has taken
             * in, and this is where it grows. Listed as well as drawn on the map,
             * because a padlock somewhere off the right-hand edge is a padlock
             * nobody finds.
             *
             * There is always something in this list. The compound used to be a
             * fixed square that could be filled up, and once its last block had
             * been taken in this tab said "every block is already part of the
             * compound" — which was true and useless, because the answer to "we
             * need more room" cannot be no. The list is now the frontier: the
             * blocks touching what is already held, which is never empty and
             * which moves outwards every time one is taken in.
             */
            function showLand(host) {
                host.appendChild(
                    el(
                        'p',
                        'compound-note',
                        'The compound is as big as the ground taken into it, and no bigger. ' +
                            'Every block you can do something with is marked on the map while ' +
                            'you are arranging — an amber padlock to take ground in, a rust peg ' +
                            'to give it back. Press one there, or pick it out of the list here.',
                    ),
                );

                /*
                 * One list at a time.
                 *
                 * The two used to sit one above the other, and with seventeen
                 * blocks on the frontier the way to give ground back was to
                 * scroll past all of them — which is the same as not having it.
                 * Both lists are now one press away and neither is under the
                 * other.
                 */
                const modes = [
                    { id: 'take', label: 'Take in', blocks: land },
                    { id: 'give', label: 'Give back', blocks: giveBack },
                ];

                const bar = el('div', 'compound-seg');
                const pane = el('div');

                modes.forEach((m) => {
                    const button = el('button', 'compound-seg-btn');
                    button.type = 'button';
                    button.dataset.mode = m.id;
                    button.dataset.on = String(m.id === landMode);
                    button.append(
                        el('b', null, m.label),
                        el('span', null, String(m.blocks.length)),
                    );

                    button.addEventListener('click', () => {
                        landMode = m.id;
                        bar.querySelectorAll('button').forEach((b) => (b.dataset.on = 'false'));
                        button.dataset.on = 'true';
                        showList();
                    });

                    bar.appendChild(button);
                });

                host.append(bar, pane);

                function showList() {
                    pane.textContent = '';

                    const mode = modes.find((m) => m.id === landMode) || modes[0];

                    if (mode.id === 'give') {
                        pane.appendChild(
                            el(
                                'p',
                                'compound-note',
                                'Only empty blocks on the outside, and never the last one. A ' +
                                    'block with anything standing on it, or one holding the rest ' +
                                    'of the compound together, is not offered.',
                            ),
                        );
                    }

                    if (mode.blocks.length === 0) {
                        pane.appendChild(
                            el(
                                'p',
                                'compound-empty',
                                mode.id === 'take'
                                    ? 'Nothing to take in from here.'
                                    : 'Nothing can be given back right now. Take a building down ' +
                                      'first, or give back a block further out.',
                            ),
                        );

                        return;
                    }

                    pane.appendChild(blockList(mode.blocks, mode.id));
                }

                showList();
            }

            /**
             * One list of blocks, either way round.
             *
             * A row opens the block rather than acting on it — the same panel
             * the marker on the map opens, so there is one description of what
             * a block is and one button that changes it, however you got there.
             */
            function blockList(blocks, mode) {
                const list = el('div', 'compound-blocks');

                blocks.forEach((block, i) => {
                    const button = el('button', 'compound-block');
                    button.type = 'button';
                    button.dataset.mode = mode;

                    const wider =
                        mode === 'take'
                            ? block.x1 > COLS || block.y1 > ROWS
                            : block.x1 >= COLS || block.y1 >= ROWS;

                    button.append(
                        el('b', null, 'Block ' + (block.dx + 1) + '–' + (block.dy + 1)),
                        el(
                            'span',
                            null,
                            (mode === 'take'
                                ? wider
                                    ? 'Makes the compound bigger'
                                    : 'Fills in the edge'
                                : wider
                                  ? 'Makes the compound smaller'
                                  : 'Trims the edge') +
                                ' · squares ' +
                                block.x0 +
                                ',' +
                                block.y0 +
                                ' to ' +
                                (block.x1 - 1) +
                                ',' +
                                (block.y1 - 1),
                        ),
                    );

                    /* Hovering the row lights the block on the map, so the words
                       and the ground are obviously about each other. */
                    button.addEventListener('mouseenter', () => {
                        hoveredLand = mode === 'take' ? i : -1;
                        hoveredGiveBack = mode === 'give' ? i : -1;
                        if (!arranging) arrangeBtn?.click();
                    });

                    button.addEventListener('click', () => openBlock(mode, block));

                    list.appendChild(button);
                });

                return list;
            }

            showCategory();
        });
    }

    /** A patch of the surface a brush lays, for the picker. */
    function drawBrushSwatch(canvas, kind) {
        const c = canvas.getContext('2d');
        c.imageSmoothingEnabled = false;
        c.clearRect(0, 0, canvas.width, canvas.height);
        c.setTransform(1, 0, 0, 1, Math.round(canvas.width / 2), 6);

        for (let x = 0; x < 2; x++) {
            for (let y = 0; y < 2; y++) {
                const fill =
                    kind === 'r'
                        ? C.road
                        : kind === 'p'
                          ? (x + y) % 2
                              ? C.plaza
                              : C.plazaAlt
                          : (x + y) % 2
                            ? C.grass
                            : C.grassAlt;

                const D = iso(x + 1, y + 1);
                const E = iso(x, y + 1);

                poly(c, [iso(x, y), iso(x + 1, y), D, E], fill);
                poly(c, [E, D, [D[0], D[1] + 2], [E[0], E[1] + 2]], shade(fill, -22));
            }
        }

        c.setTransform(1, 0, 0, 1, 0, 0);
    }

    /** Pick it up. From here the cursor carries it until it is put down. */
    function startPlacing(chosen) {
        if (!arranging) arrangeBtn?.click();

        placing = {
            ...chosen.template,
            wall: chosen.colour.wall,
            roof: chosen.colour.roof,
            gx: 0,
            gy: 0,
            chosen,
        };

        npc.say('Somewhere inside the track. Escape if you change your mind.');
        toast('Click where it goes. Escape to cancel.', 'pending');
    }

    async function putDown(ev) {
        const chosen = placing.chosen;

        if (!canPlace(placing, placing.gx, placing.gy)) {
            toast('It cannot stand there.', 'bad');

            return;
        }

        const body = {
            template: chosen.template.id,
            gx: placing.gx,
            gy: placing.gy,
            wall: chosen.colour.wall,
            roof: chosen.colour.roof,
            department_id: chosen.departmentId,
            create_office: chosen.createOffice === true,
            office_name: chosen.officeName,
            office_code: chosen.officeCode || null,
        };

        placing = null;

        const result = await send(addBtn.dataset.url, 'POST', body);

        if (!result) return;

        adopt(result);
        toast('Up it goes.', 'good');
    }

    /*
     * Lay one square, if it is the municipality's to lay and not already that.
     *
     * Changed on screen immediately and sent when the hand comes up: a path
     * that appears half a second after you drew it is a path you draw twice.
     */
    function paintAt(clientX, clientY) {
        const [x, y] = cellAt(clientX, clientY);

        if (!isOpenGround(x, y)) return;
        if (ground[y][x] === brush) return;

        /* What was there, kept so a refusal can put it back — the same bargain
           the layout editor makes, and for the same reason: the screen must end
           up saying what the database says. */
        stroke.set(x + ',' + y, { x, y, kind: brush, was: ground[y][x] });
        ground[y][x] = brush;
        rebuildProps();
    }

    async function sendGround(laid) {
        const result = await send(addBtn?.dataset.groundUrl, 'PATCH', {
            tiles: laid.map(({ x, y, kind }) => ({ x, y, kind })),
        });

        if (!result) {
            laid.forEach(({ x, y, was }) => (ground[y][x] = was));
            rebuildProps();

            return;
        }

        result.ground.forEach((row, y) => {
            ground[y] = row.split('');
        });

        rebuildProps();
        toast(laid.length === 1 ? 'Laid.' : laid.length + ' squares laid.', 'good');
    }

    /**
     * Take another block of ground into the compound.
     *
     * The block may be past the compound's current edge, in which case the
     * compound is now bigger than it was a moment ago — so the answer carries
     * the new size and the ground to fill it, and grow() moves the edge out
     * without a reload. The camera is deliberately left where it is: somebody
     * who has just pressed a padlock is looking at that padlock, and moving the
     * view out from under them to show off the extra field would lose them the
     * thing they were looking at.
     */
    /**
     * One block, and the one thing you can do to it.
     *
     * The confirmation, and the only place either land change is actually
     * committed — pressed from the marker on the map or from the row in the
     * Land tab, so the two cannot come to behave differently. It is a panel
     * rather than a second press on a small icon because both of these are
     * decisions: ground taken in with a building put on it can never be given
     * back, and ground given back takes its paving with it.
     *
     * The block lights up on the map while this is open, so the words and the
     * ground are obviously about each other.
     */
    /**
     * A real block, and not an index into a list of them.
     *
     * These two used to take the position of a block in `land` and were changed
     * to take the block itself, and one caller — the padlock on the map, which
     * is the one most people press — went on passing the number. What came out
     * of it was a request with no coordinates in it and the server quite
     * correctly answering "the dx field is required", which is a true sentence
     * that tells nobody anything. A wrong argument stops here now.
     */
    function isABlock(block) {
        if (block && typeof block.dx === 'number' && typeof block.dy === 'number') return true;

        toast('Something went wrong reading that block. Reload and try again.', 'bad');

        return false;
    }

    function openBlock(mode, block) {
        if (!isABlock(block)) return;

        const name = 'Block ' + (block.dx + 1) + '–' + (block.dy + 1);
        const taking = mode === 'take';

        hoveredLand = taking ? land.findIndex((b) => b.dx === block.dx && b.dy === block.dy) : -1;
        hoveredGiveBack = taking
            ? -1
            : giveBack.findIndex((b) => b.dx === block.dx && b.dy === block.dy);

        openSheet(name, (host) => {
            host.appendChild(
                el(
                    'p',
                    'compound-note',
                    (taking
                        ? block.x1 > COLS || block.y1 > ROWS
                            ? 'Taking this block in makes the compound bigger. '
                            : 'Taking this block in fills in the edge of the compound. '
                        : block.x1 >= COLS || block.y1 >= ROWS
                          ? 'Giving this block back makes the compound smaller. '
                          : 'Giving this block back trims the edge of the compound. ') +
                        'It covers squares ' +
                        block.x0 +
                        ',' +
                        block.y0 +
                        ' to ' +
                        (block.x1 - 1) +
                        ',' +
                        (block.y1 - 1) +
                        '.',
                ),
            );

            host.appendChild(
                el(
                    'p',
                    'compound-note',
                    taking
                        ? 'You can build on it straight away, and give it back later as long as ' +
                          'nothing is standing on it.'
                        : 'Nothing is standing on it. Its paving goes back with it, and you can ' +
                          'take it in again whenever you like.',
                ),
            );

            const go = el(
                'button',
                taking ? 'compound-go' : 'compound-danger',
                taking ? 'Take this block in' : 'Give this block back',
            );

            go.type = 'button';
            go.addEventListener('click', async () => {
                go.disabled = true;
                closeSheet();

                await (taking ? takeTheLand(block) : giveTheLandBack(block));
            });

            const not = el('button', 'compound-quiet', 'Not now');
            not.type = 'button';
            not.addEventListener('click', closeSheet);

            host.append(go, not);
        });
    }

    async function takeTheLand(block) {
        if (!isABlock(block)) return;

        const result = await send(addBtn?.dataset.landUrl, 'POST', {
            dx: block.dx,
            dy: block.dy,
        });

        if (!result) return;

        theLandIsNow(result);
        toast('That ground is part of the compound now.', 'good');
    }

    /**
     * Give a block back.
     *
     * The same route the other way round, and the same handling of the answer:
     * the compound may now be smaller than it was, and everything that walks
     * the ground has to be told before the next frame reads a square that is no
     * longer there.
     */
    async function giveTheLandBack(block) {
        if (!isABlock(block)) return;

        const result = await send(addBtn?.dataset.landUrl, 'DELETE', {
            dx: block.dx,
            dy: block.dy,
        });

        if (!result) return;

        theLandIsNow(result);
        toast('That ground is out of the compound. Its paving went with it.', 'good');
    }

    /**
     * Adopt the compound's new boundary, whichever way it moved.
     *
     * The camera is deliberately left where it is. Somebody who has just
     * pressed a block is looking at that block, and moving the view out from
     * under them to show off the result would lose them the thing they were
     * looking at — clampCam only pulls it back if the compound shrank away
     * from where they were looking.
     */
    function theLandIsNow(result) {
        unlocked = new Set(result.unlocked);
        land = result.land || [];
        giveBack = result.giveBack || [];
        hoveredLand = -1;
        hoveredGiveBack = -1;

        moveTheEdge(result.cols, result.rows, result.ground);

        rebuildProps();
        refreshAnchors();
        clampCam();
    }

    /**
     * Move the compound's edge, in place.
     *
     * The ground is a live array the brush writes into a cell at a time, so it
     * is filled in rather than replaced — a wider compound needs longer rows,
     * and a deeper one needs rows that did not exist a moment ago. PHP has
     * already worked out what every square holds; this only has to make room
     * for the answer, and throw away the rows a shrunken compound no longer
     * reaches.
     */
    function moveTheEdge(cols, rows, rowsOfGround) {
        COLS = cols || COLS;
        ROWS = rows || ROWS;

        ground.length = 0;

        (rowsOfGround || []).forEach((row) => ground.push(row.split('')));

        /* Belt and braces: if the compound is somehow taller than the ground it
           was handed, the missing rows are grass rather than undefined — which
           tileAt would otherwise read a character out of. */
        for (let y = 0; y < ROWS; y++) {
            if (!ground[y]) ground[y] = new Array(COLS).fill('g');
        }
    }

    /**
     * A building somebody pressed while arranging.
     *
     * Redesign it, repaint it, or take it down. Taking it down was all this
     * offered for a while, which made the arrange mode a place where the only
     * fix for a building that had come out wrong was to demolish it and start
     * again — losing the office it was for and the spot it was standing on in
     * the process.
     *
     * Only the designs of its own kind are offered. An office building can be
     * rebuilt as any other office building and a bit of scenery as any other
     * scenery, but the two do not cross: which office a building is for is a
     * decision made once when it goes up, not something to change by picking a
     * different picture. The server enforces the same rule.
     */
    function openBuilding(place) {
        openSheet(place.name, (host) => {
            host.appendChild(el('p', 'compound-note', place.say || ''));

            const templates = (data.templates || []).filter((t) => t.kind === place.kind);
            const palette = data.palette || [];

            /* What it is now, so the panel opens on the building in front of
               you rather than on the first design in the list. Matched on the
               shape as well as the sprite, because six office designs share one
               sprite and differ in style and footprint. */
            let template =
                templates.find(
                    (t) =>
                        t.sprite === place.sprite &&
                        t.style === place.style &&
                        t.w === place.w &&
                        t.h === place.h,
                ) ||
                templates.find((t) => t.sprite === place.sprite) ||
                templates[0];

            let colour =
                palette.find((p) => p.wall === place.wall && p.roof === place.roof) || palette[0];

            if (templates.length && colour) {
                host.appendChild(el('h3', 'compound-sheet-head-3', 'Design'));

                const grid = el('div', 'compound-templates');

                templates.forEach((t) => {
                    const button = el('button', 'compound-template');
                    button.type = 'button';
                    button.dataset.on = String(t === template);
                    button.title = t.blurb;

                    const preview = document.createElement('canvas');
                    preview.width = 78;
                    preview.height = 58;
                    preview.setAttribute('aria-hidden', 'true');
                    drawPreview(preview, t, colour);

                    button.append(preview, el('b', null, t.name));

                    button.addEventListener('click', () => {
                        template = t;
                        grid.querySelectorAll('button').forEach((b) => (b.dataset.on = 'false'));
                        button.dataset.on = 'true';
                        paintFields.hidden = !t.paint;
                    });

                    grid.appendChild(button);
                });

                host.appendChild(grid);

                const paintFields = el('div', 'compound-field');
                paintFields.hidden = !template.paint;
                paintFields.appendChild(el('h3', 'compound-sheet-head-3', 'Colour'));

                const swatches = el('div', 'compound-swatches');

                palette.forEach((p) => {
                    const button = el('button', 'compound-swatch');
                    button.type = 'button';
                    button.title = p.name;
                    button.setAttribute('aria-label', p.name);
                    button.style.background = p.wall;
                    button.style.borderBottom = '10px solid ' + p.roof;
                    button.dataset.on = String(p === colour);

                    button.addEventListener('click', () => {
                        colour = p;
                        swatches
                            .querySelectorAll('button')
                            .forEach((b) => (b.dataset.on = 'false'));
                        button.dataset.on = 'true';

                        grid.querySelectorAll('button').forEach((b, i) => {
                            drawPreview(b.querySelector('canvas'), templates[i], colour);
                        });
                    });

                    swatches.appendChild(button);
                });

                paintFields.appendChild(swatches);
                host.appendChild(paintFields);

                const save = el('button', 'compound-go', 'Save the changes');
                save.type = 'button';
                save.addEventListener('click', async () => {
                    save.disabled = true;

                    const result = await send(
                        addBtn.dataset.url + '/' + place.building,
                        'PATCH',
                        {
                            template: template.id,
                            wall: colour.wall,
                            roof: colour.roof,
                        },
                    );

                    save.disabled = false;

                    if (!result) return;

                    closeSheet();
                    adopt(result);
                    toast('Changed.', 'good');
                });

                host.appendChild(save);
            }

            const remove = el('button', 'compound-danger', 'Take this building down');
            remove.type = 'button';
            remove.addEventListener('click', async () => {
                if (remove.dataset.sure !== 'true') {
                    remove.dataset.sure = 'true';
                    remove.textContent =
                        place.kind === 'office'
                            ? 'Sure? The office itself is untouched.'
                            : 'Sure? Press again to take it down.';

                    return;
                }

                closeSheet();

                const result = await send(
                    addBtn.dataset.url + '/' + place.building,
                    'DELETE',
                    null,
                );

                if (!result) return;

                adopt(result);
                toast(
                    place.kind === 'office'
                        ? 'Taken down. The office is still there.'
                        : 'Taken down.',
                    'good',
                );
            });

            host.appendChild(remove);
        });
    }

    /**
     * Replace the compound with what the server just said it is.
     *
     * Everything derived from the building list has to be rebuilt together —
     * the depth order, the scattered scenery, the labels and the keyboard
     * route — or one of them goes on describing a compound that no longer
     * exists.
     */
    function adopt(result) {
        places.length = 0;
        places.push(...result.places);
        data.vacant = result.vacant;

        sortByDepth();
        rebuildProps();
        refreshAnchors();
        drawLabels();
        buildKeynav();
    }

    /** One place for the fetch, the token and the failure message. */
    async function send(url, method, body) {
        if (!url) return null;

        try {
            const response = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: body === null ? undefined : JSON.stringify(body),
            });

            if (response.ok) return await response.json();

            const said = await response.json().catch(() => null);
            toast(said?.message || 'The server refused that.', 'bad');
        } catch {
            toast('Could not reach the server.', 'bad');
        }

        return null;
    }

    if (addBtn) addBtn.addEventListener('click', openAdd);

    document.getElementById('compoundFind')?.addEventListener('click', openFind);

    if (arrangeBtn) {
        arrangeBtn.addEventListener('click', () => {
            arranging = !arranging;
            arrangeBtn.setAttribute('aria-pressed', String(arranging));
            arrangeBtn.querySelector('span').textContent = arranging ? 'Done' : 'Arrange';
            stage.dataset.arranging = String(arranging);

            if (arranging) {
                panel.close();
                npc.say(
                    'Drag a building onto any square inside the track. I will remember where you put it.',
                );
            } else {
                /* Done arranging is done with all of it. Leaving a loaded brush
                   behind is how the next click on the map lays a path somebody
                   stopped meaning to lay. */
                putTheBrushDown();
                clearTimeout(saveTimer);
                save();
            }
        });
    }

    /*
     * Take me there.
     *
     * Centres the building, goes in a step closer if there is room, and opens
     * it. The zoom is the part that makes it feel like being walked over rather
     * than told where to look.
     */
    function focusOn(i) {
        const place = places[i];
        if (!place) return;

        if (zoom < maxZoom) zoom = Math.min(maxZoom, zoom + 1);

        LW = Math.ceil(stage.clientWidth / zoom);
        LH = Math.ceil(stage.clientHeight / zoom);

        const [cx, cy] = iso(place.gx + place.w / 2, place.gy + place.h / 2);
        camX = cx - LW / 2;
        camY = cy - LH / 2;

        resize();
        visit(place);
    }

    const takeMe = document.getElementById('compoundTakeMe');

    if (takeMe) {
        if (mine >= 0) {
            takeMe.querySelector('span').textContent = places[mine].name;
            takeMe.title = 'Take me to ' + places[mine].name;
            takeMe.hidden = false;
            takeMe.addEventListener('click', () => focusOn(mine));
        } else {
            takeMe.remove();
        }
    }

    /* Arriving from the gate with an office in mind. */
    const wanted = new URLSearchParams(window.location.search).get('goto');

    if (wanted) {
        const i = places.findIndex((p) => p.id === 'office:' + wanted);
        if (i >= 0) setTimeout(() => focusOn(i), 400);
    }

    /*
     * Every building, as a focusable control.
     *
     * A canvas is not reachable by keyboard and a drawn compound is not
     * readable by a screen reader, so the same list is rendered as real
     * buttons — invisible until focused, then ordinary readable ones. Focusing
     * pans the map to that building, so the drawing and the keyboard's idea of
     * where you are never disagree.
     */
    function buildKeynav() {
        keynavEl.textContent = '';

        places.forEach((place, i) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = place.name + ' — ' + place.blurb;

            button.addEventListener('focus', () => {
                hovered = i;
                showTag(i);
            });

            button.addEventListener('click', () => visit(place));

            keynavEl.appendChild(button);
        });
    }

    buildKeynav();

    /* ------------------------------------------------------------ 7. boot */

    function applyMotion() {
        root.dataset.motion = motion ? 'on' : 'off';
        if (motion) started = performance.now() - elapsed;
    }

    applyMotion();

    const npc = createGuide({
        el: document.getElementById('worldNpc'),
        bubble: document.getElementById('worldBubble'),
        textEl: document.getElementById('worldNpcText'),
        intro: data.intro,
        tips: data.tips,
        motion: () => motion,
    });

    startSplash(
        data.title,
        data.subtitle,
        () => npc.begin(),
        () => motion,
    );

    drawGuideSprite(document.getElementById('worldNpcCanvas'));

    document
        .querySelectorAll('.world-dock canvas[data-icon], .compound-sheet canvas[data-icon]')
        .forEach(drawDockIcon);

    /*
     * The keyboard, over the whole screen.
     *
     * Escape gets out of whatever is open, innermost first. A slash opens the
     * search the way it does in every other map and every other list, and it is
     * ignored while somebody is typing — otherwise the search box could not
     * contain a slash.
     */
    document.addEventListener('keydown', (ev) => {
        const typing = ev.target instanceof Element && ev.target.closest('input, textarea, select');

        if (ev.key === 'Escape') {
            if (placing) {
                placing = null;
                toast('Left where it was.', 'pending');
            } else if (!sheetEl.hidden) {
                closeSheet();
            } else if (brush) {
                /* After the panel, not before it: innermost first. Picking a
                   surface closes the panel anyway, so by the time a brush is
                   the only thing open, one Escape puts it down. */
                putTheBrushDown();
                toast('Brush down.', 'pending');
            }

            return;
        }

        if (typing) return;

        if (ev.key === '/' || ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'k')) {
            ev.preventDefault();
            openFind();
        }
    });

    /* The way around, said once. The dock underneath carries everything else,
       including the way down to the list. */
    function updateHint() {
        hintEl.textContent = canRoam()
            ? 'Drag to look around · scroll to zoom · click a building'
            : 'Click a building to see what that office does';
    }

    new ResizeObserver(() => {
        resize();
        updateHint();
    }).observe(stage);

    resize();
    updateHint();

    requestAnimationFrame(frame);
}

/*
 * Last, not first. No stage on the page means this is not the compound, in
 * which case the file does nothing rather than complaining about it.
 */
if (stage) {
    boot();
}
