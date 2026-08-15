# Motion brief — the welcome page

A brief for [Motion](https://claude.ai), the video tool, to produce a ~60-second
landscape launch video showing off the public welcome page.

Motion does its own research and shot-planning, so what follows is a **brief**,
not a storyboard — goal, material, audience, tone, constraints. Handing it a
shot-by-shot script fights the tool and produces worse video.

Everything factual below is drawn from `app/Support/World.php` (what the
landmarks are and what they say) and `resources/js/world.js` (how they are
drawn). If the town changes, this file is stale — the place list in
`World::publicPlaces()` is the source of truth.

---

## The brief

Copy everything between the rules into Motion.

---

Make a ~60-second landscape (16:9) launch video for eFileManager, the document
management system of the Municipality of Bongabong, Oriental Mindoro,
Philippines. The video's job is to show off the site's welcome page, which is
unlike any other government website in the country.

**What the welcome page is**

Instead of a login form or a stock photo of a municipal building, the front page
of this .gov.ph site is a hand-drawn pixel-art town, rendered live in the
browser. You see Bongabong side-on, like a 2D game — you drag sideways to walk
through it, and each landmark is a real part of the system:

- **Aling Nena's Store** — "Closed for merienda." Just town. Not clickable.
- **The Treehouse** — what the system is, in plain words. Rope swing, nipa roof.
- **Municipal Hall** — two storeys, columns, a rippling flag on the roof. Staff
  sign in here.
- **Plaza Fountain** — this is the Full Disclosure Policy board. Budgets,
  procurement, financial statements, as DILG requires.
- **Notice Board** — public notices from the offices, with a red badge showing
  how many are waiting to be read.
- **Covered Court** — "Game at 5, as usual." A ball arcs through the air forever.
- **Citizen Kiosk** — forms residents can download and print at home. No account,
  no queue, no fee.
- **Bongabong Shore** — the sea, a bangka drawn up on the sand, facing east.

A guide called Mayor Mike stands in the corner and types his lines out one
character at a time: "Mabuhay! Welcome to the Municipality of Bongabong. I'm
Mayor Mike — think of me as your guide around town." Arriving at the site plays
a splash: a pixel municipal seal, then the word BONGABONG held large with
"Municipality" under it, then a loading bar. Clicking a landmark closes two
ragged cloud panels over the screen before the page changes.

Scroll below the town and the tone changes deliberately: pinned notices, latest
notices, and the Full Disclosure shelves — ordinary, readable, serious
government content, framed in the same pixel furniture.

**Audience**

Two at once. Municipal officials and LGU staff who need to see that this is a
real, compliant system — and Bongabong residents, who need to see that a
government website can be worth visiting.

**Tone**

Warm, playful, a little proud — but never cute at the expense of credibility.
The joke is that it looks like a Game Boy game; the point is that it's a working
DILG-compliant public disclosure portal. Land both. Filipino municipal texture is
welcome (sari-sari store, merienda, liga at five) — it's what makes the town read
as Bongabong and not a generic village.

**Visual direction**

Everything is chunky low-resolution pixel art, integer-scaled, hard edges, no
gradients or blur — treat that as a hard constraint, not a style suggestion. Warm
tropical daylight: blue sky, green grass, the sea to the east. Motion should feel
like a side-scrolling game camera walking through town, not like a slideshow of
screenshots.

**Must include**

- The splash and the BONGABONG title card
- A left-to-right walk through the town, so the panorama reads as one place
- Mayor Mike speaking at least one line
- The Full Disclosure Policy / notices content below the town — proof it's a
  real government portal, not a toy
- Ending on the system name: eFileManager, Municipality of Bongabong

**Avoid**

- Corporate stock footage, real photography, 3D, or anything anti-aliased
- Calling it an "app" or a "platform" — it's the municipality's website
- Overselling with buzzwords. The town does the selling.

---

## Before you run it

**Motion cannot see the site.** It generates from this text, so what comes back
is its interpretation of the town — a lookalike, not a recording. To get the
actual artwork on screen, capture real frames or footage of the welcome page
first and attach them; Motion accepts image and video attachments alongside the
brief. That is more work and the right answer if this video is going anywhere
public.

**Other formats.** The brief is written for 16:9 at ~60 seconds. For Facebook
Reels — realistically how an LGU reaches residents here — ask for 9:16 at ~20
seconds and cut the walk down to the Notice Board, the Kiosk and the fountain.
Everything else in the brief still holds.
