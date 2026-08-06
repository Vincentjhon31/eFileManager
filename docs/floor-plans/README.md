# Floor plans

Drop source images or PDFs of the Bongabong Municipal Hall floor plans here.

**Needed now:** the 2nd floor (Mayor's Office). Any filename is fine — a photo of a printed plan, a scan, or a CAD export all work.

These are **source material only**. They are traced by hand into layered SVGs at `resources/svg/floors/`, where each room is a `<path>` carrying a stable `id`:

```html
<path id="room-mayor" d="..." />
<path id="room-mayor-reception" d="..." />
```

The database stores only that element id (`rooms.svg_shape_id`) plus a centroid for badge placement — never the geometry itself. A room can therefore be redrawn, or a whole floor re-traced, without a migration, and the application never parses SVG.

Naming convention for the SVGs: `floor-{level}.svg` (e.g. `floor-2.svg`).
