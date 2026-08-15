/*
|------------------------------------------------------------------------------
| The paint box
|------------------------------------------------------------------------------
|
| The palette and the four primitives every drawn screen in this system is made
| of. Extracted from world.js when the compound gained its own renderer, because
| the two scenes are drawn in different projections but with the same paint —
| and a second copy of these colours is how the town and the compound end up
| almost matching.
|
| Everything is drawn at a low logical resolution and scaled up by an integer
| factor with image-rendering:pixelated. That is the whole technique: a 4px-wide
| rectangle is a 4px-wide rectangle, and at 3x it is a crisp 12px block rather
| than a blurry 12px smear.
|
*/

export const C = {
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
export function r(c, x, y, w, h, fill) {
    c.fillStyle = fill;
    c.fillRect(Math.round(x), Math.round(y), Math.round(w), Math.round(h));
}

/* A filled circle in whole pixels: for each row, work out the half-width of the
   chord and draw it as one rectangle. Cheap, and every edge lands on the grid. */
export function disc(c, cx, cy, rad, fill) {
    c.fillStyle = fill;
    for (let dy = -rad; dy <= rad; dy++) {
        const half = Math.floor(Math.sqrt(rad * rad - dy * dy));
        c.fillRect(Math.round(cx - half), Math.round(cy + dy), half * 2 + 1, 1);
    }
}

export function shade(hex, amt) {
    const n = parseInt(hex.slice(1), 16);
    const clamp = (v) => Math.max(0, Math.min(255, Math.round(v)));
    return (
        '#' +
        [16, 8, 0]
            .map((s) => clamp(((n >> s) & 255) + amt).toString(16).padStart(2, '0'))
            .join('')
    );
}

/*
 * An arbitrary polygon, filled flat.
 *
 * The isometric compound's whole vocabulary: a building is four of these, and
 * so is every tile of ground under it. The town does not use it — a side-on
 * view has no diagonals in it — which is why this lives here rather than in
 * either scene.
 *
 * No rounding, deliberately, unlike r(). An isometric edge is a diagonal, and
 * rounding both ends of one leaves a one-pixel seam between two faces that are
 * meant to meet.
 */
export function poly(c, points, fill) {
    c.beginPath();
    c.moveTo(points[0][0], points[0][1]);
    for (let i = 1; i < points.length; i++) c.lineTo(points[i][0], points[i][1]);
    c.closePath();
    c.fillStyle = fill;
    c.fill();
}

/* A deterministic pseudo-random in [0,1) from an integer. Used for scattering
   grass tufts and stars so the same seed always gives the same town — the world
   must look identical after a resize, and Math.random() would reshuffle every
   tuft on every reflow. */
export function noise(i) {
    const x = Math.sin(i * 12.9898) * 43758.5453;
    return x - Math.floor(x);
}
