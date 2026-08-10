# Floor plans

Source images of the Bongabong Municipal Hall, kept so a floor can be redrawn
without going back to ask the LGU for the original again.

| File | Floor | Traced to |
|---|---|---|
| `Mayor's_Office.png` | Municipal Hall, 2nd floor | `resources/svg/floors/hall-second-floor.svg` |

The ground floor has not been surveyed. It exists in the database with
`has_map = false`, so the building screen lists its rooms and says plainly that
there is no plan drawn for it yet.

## How a plan becomes a map

These are **source material only**. They are traced by hand into an SVG at
`resources/svg/floors/`, where each room is a `<path>` carrying a stable `id`:

```html
<path id="room-mayors-office" d="M890,560 H1280 V995 H890 Z"><title>Mayor's Office</title></path>
```

The database stores only that element id (`rooms.svg_shape_id`) plus a centroid
for badge placement — never the geometry. A room can be redrawn, or a whole
floor re-traced, without a migration, and the application never parses SVG.

Door state is applied by a stylesheet generated at render time that targets
those ids. So the drawing has to obey three rules, which are repeated at the top
of the SVG itself:

1. **Every room shape keeps its `id`.** Rename one and that room stops lighting
   up. Keep it and you may move, reshape or restyle anything you like.
2. **Room fills are presentation attributes** (`fill="…"`), never inline
   `style`. An inline style beats the generated stylesheet and would freeze the
   room's colour forever.
3. **No script, no external references, no images.** The file is inlined into an
   authenticated page; anything it can fetch, it fetches as the signed-in user.

`FloorMapTest` enforces all three, plus that the drawing is well-formed XML and
that its shapes and the seeded rooms agree exactly about which rooms exist.

## The current trace is schematic

The furniture in the original is deliberately left out. This drawing is read as
a status board, and a room tinted amber for "work waiting" is harder to read
with a conference table drawn across it. Walls, room outlines and labels are
what the map needs; the PNG remains the reference for anyone who wants the rest.

If the LGU later supplies a CAD export, replacing the trace is a drop-in: keep
the ids, and nothing else changes.

## Adding another floor

1. Drop the source image here and note it in the table above.
2. Trace it to `resources/svg/floors/{building}-{floor}.svg`.
3. Add the floor and its rooms to `database/seeders/BuildingSeeder.php`, with
   `svg_shape_id` matching the drawing and `centroid_x` / `centroid_y` as
   percentages of the viewBox.
4. Map the rooms to offices on the **Rooms** screen rather than guessing codes
   in the seeder — that screen exists precisely because a floor plan and an org
   chart are drawn by different people.
