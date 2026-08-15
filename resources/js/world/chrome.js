/*
|------------------------------------------------------------------------------
| The furniture around a drawn world
|------------------------------------------------------------------------------
|
| Two screens draw a place you can walk through — the public town, side on, and
| the staff compound, isometric — and they share every part of the interface
| that is not the drawing itself: the guide, the splash, the cloud wipe, the
| movement toggle, the landmark panel and the small canvas icons.
|
| All of it works against the markup in resources/views/components/world.blade.php,
| which is one component for the same reason: what stops the keyboard route or
| the reduced-motion switch quietly working on one screen and not the other is
| that there is only one of each.
|
| What is *not* here is anything that knows where things are. Projection,
| layout, hit testing, labels and the camera differ between a row of buildings
| and a grid of them, and each scene keeps its own.
|
*/

import { C, disc, r, shade } from './paint.js';

/*
|------------------------------------------------------------------------------
| The guide
|------------------------------------------------------------------------------
*/

/*
 * Mayor Mike.
 *
 * Types his lines rather than showing them, which is the difference between a
 * tooltip and somebody talking. The intro runs once per browser session for the
 * same reason the splash does — it is a greeting, and being greeted five times
 * is being nagged.
 */
export function createGuide({ el, bubble, textEl, intro, tips, motion }) {
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

/*
|------------------------------------------------------------------------------
| The splash
|------------------------------------------------------------------------------
*/

export function startSplash(title, subtitle, onDone, motion) {
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
            s.textContent = ch === ' ' ? ' ' : ch;
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

/*
|------------------------------------------------------------------------------
| Movement
|------------------------------------------------------------------------------
|
| Two ways to arrive at stillness: the operating system's own preference,
| honoured without being asked, and the gear button for somebody whose machine
| says nothing but who has had enough of the waves. Both land on one attribute,
| so there is one thing to check in CSS and in either renderer.
|
| Each scene keeps its own `motion` flag rather than reading one from here,
| because both use it inside their frame loop where a function call per frame
| buys nothing. This wires the controls and reports changes.
|
*/

export function readMotion() {
    const saved = localStorage.getItem('world:motion');
    if (saved !== null) return saved === 'on';

    return prefersMotion();
}

/*
 * The machine's own answer, with nobody's saved preference on top.
 *
 * For a screen with no movement toggle on it — the compound — where the right
 * default is on, because a compound with nothing moving in it looks like a
 * photograph of one. Somebody whose operating system asks for reduced motion
 * still gets it; that is not a preference this screen may override.
 */
export function prefersMotion() {
    return !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function wireMotionControls({ initial, onChange }) {
    const toggle = document.getElementById('worldMotion');

    /* A screen may simply not offer the choice. */
    if (!toggle) return;

    toggle.checked = initial;
    toggle.addEventListener('change', (ev) => {
        localStorage.setItem('world:motion', ev.target.checked ? 'on' : 'off');
        onChange(ev.target.checked);
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
}

/*
|------------------------------------------------------------------------------
| The cloud wipe
|------------------------------------------------------------------------------
*/

/*
 * Two ragged panels close over the page before a navigation. Skipped outright
 * when movement is off — a wipe nobody can see is only a delay.
 *
 * The wipe is deliberately never re-opened after it has closed: the page is
 * leaving, and uncovering it for the last few milliseconds of its life would
 * show a flash of the screen somebody has already left. That is right going
 * forward and wrong coming back, which is what the listener below is for.
 */
export function createWipe(motion) {
    const el = document.getElementById('worldWipe');

    /*
     * Coming back to a page that was covered when it left.
     *
     * The browser's back/forward cache does not reload a page — it freezes the
     * whole document, JavaScript and all, and thaws it on the way back. So a
     * page left mid-wipe is restored mid-wipe: two cream panels over the entire
     * viewport, no script running to take them away, and a visitor looking at a
     * blank screen that a refresh mysteriously fixes.
     *
     * pageshow is the one event that fires in that case (with persisted=true).
     * It also fires on an ordinary load, where removing classes nothing has set
     * yet costs nothing — so there is no need to check which kind it was.
     */
    window.addEventListener('pageshow', () => {
        el.classList.remove('active', 'cover');
    });

    return function wipe(then) {
        if (!motion()) {
            then();
            return;
        }

        el.classList.add('active');
        requestAnimationFrame(() => el.classList.add('cover'));
        setTimeout(then, 420);
    };
}

/*
|------------------------------------------------------------------------------
| The landmark panel
|------------------------------------------------------------------------------
|
| What a place looks like, and the way in. Clicking anything opens this before
| it goes anywhere: the drawing is a drawing, and the photographs are the actual
| place, put there by somebody in the hall. A landmark that leads somewhere
| keeps its destination as a button at the foot rather than losing it.
|
| The photographs are the only images either renderer loads, and they are
| deliberately <img> elements over the canvas rather than drawImage into it. The
| canvas stays a thing composed out of rectangles, and the browser does the
| decoding, the caching and the alt text.
|
| State is one index into one array. Everything else is read from the payload
| each time the panel opens, so nothing has to be kept in step.
|
*/

export function createPanel({ wipe, onOpen, extra }) {
    const panelEl = document.getElementById('worldPanel');
    const panelName = document.getElementById('worldPanelName');
    const panelBlurb = document.getElementById('worldPanelBlurb');
    const panelSay = document.getElementById('worldPanelSay');
    const panelGo = document.getElementById('worldPanelGo');
    const panelExtra = document.getElementById('worldPanelExtra');
    const panelClose = document.getElementById('worldPanelClose');
    const frameEl = document.getElementById('worldFrame');
    const zoomEl = document.getElementById('worldPhotoZoom');
    const photoEl = document.getElementById('worldPhoto');
    const photoEmpty = document.getElementById('worldPhotoEmpty');
    const captionEl = document.getElementById('worldPhotoCaption');
    const dotsEl = document.getElementById('worldPhotoDots');

    const lightbox = document.getElementById('worldLightbox');
    const lightboxImg = document.getElementById('worldLightboxImg');
    const lightboxCap = document.getElementById('worldLightboxCap');

    let openPlace = null;
    let photos = [];
    let shown = 0;
    let cameFrom = null;

    function open(place) {
        /* Where to put focus back. Only worth remembering when it was
           somewhere — a mouse click on the canvas leaves it on the body, and
           returning focus to the body is what the browser does anyway. */
        cameFrom =
            document.activeElement instanceof HTMLElement && document.activeElement !== document.body
                ? document.activeElement
                : null;

        openPlace = place;
        photos = Array.isArray(place.photos) ? place.photos : [];
        shown = 0;

        panelName.textContent = place.name;
        panelBlurb.textContent = place.blurb;
        panelSay.textContent = place.say || '';

        if (place.kind === 'link' && place.url) {
            panelGo.href = place.url;
            panelGo.textContent = (place.go || 'Go to ' + place.name) + ' →';
            panelGo.hidden = false;
        } else {
            panelGo.hidden = true;
        }

        /* Anything the scene wants under the picture — an office's head, its
           notices, the shortcuts a signed-in member of it may follow. The town
           passes nothing and the block stays empty. */
        panelExtra.textContent = '';
        if (extra) extra(panelExtra, place);
        panelExtra.hidden = panelExtra.childElementCount === 0;

        frameEl.dataset.single = photos.length < 2 ? 'true' : 'false';
        showPhoto(0);

        /*
         * hidden comes off first, then a forced layout, then the attribute that
         * slides it up. The sheet has to have been laid out in its closed
         * position before the transition is asked for, or the browser has
         * nothing to animate from and it simply appears.
         *
         * Reading offsetHeight rather than waiting a frame, because a frame is
         * not guaranteed to arrive: requestAnimationFrame is throttled in a
         * background tab, and a panel that opens only once the tab is looked at
         * again is a bug nobody can reproduce.
         */
        panelEl.hidden = false;
        void panelEl.offsetHeight;
        panelEl.dataset.open = 'true';

        /* Somebody who arrived here on the keyboard has to arrive in the panel
           too, or their next Tab would walk off into the page behind it. Mouse
           users get no visible ring from this: :focus-visible does not fire for
           a programmatic focus that followed a click. */
        panelClose.focus({ preventScroll: true });

        if (onOpen) onOpen(place);
    }

    function close() {
        if (!openPlace) return;

        closeLightbox(false);

        openPlace = null;
        panelEl.dataset.open = 'false';

        if (cameFrom && cameFrom.isConnected) cameFrom.focus({ preventScroll: true });
        cameFrom = null;

        /* Left in the tree until it has finished leaving, so it slides out
           rather than vanishing. Long enough for the transition; harmless if
           movement is off and there was none. */
        setTimeout(() => {
            if (!openPlace) panelEl.hidden = true;
        }, 260);
    }

    function showPhoto(i) {
        if (photos.length === 0) {
            zoomEl.hidden = true;
            photoEl.removeAttribute('src');
            photoEmpty.hidden = false;
            captionEl.textContent = '';
            dotsEl.textContent = '';
            closeLightbox();
            return;
        }

        /* Wraps in both directions: at the last photograph, "next" is the first
           one. A carousel that dead-ends leaves somebody pressing a button that
           does nothing and wondering which of them is broken. */
        shown = (i + photos.length) % photos.length;
        const photo = photos[shown];

        photoEmpty.hidden = true;
        zoomEl.hidden = false;
        photoEl.src = photo.url;
        photoEl.alt = photo.alt || '';
        captionEl.textContent = photo.caption || '';

        /* The enlarged copy follows the carousel rather than being a separate
           idea of which photograph is current — so the arrows work the same
           whichever of the two somebody is looking at. */
        if (!lightbox.hidden) {
            lightboxImg.src = photo.url;
            lightboxImg.alt = photo.alt || '';
            lightboxCap.textContent = photo.caption || '';
        }

        drawDots();
    }

    /*
     * The photograph on its own.
     *
     * A panel wide enough to hold a photograph and everything that is written
     * about a place is not wide enough to look at the photograph, so there are
     * two sizes and this is the second. It sits above the panel rather than
     * replacing it: closing it puts somebody back where they were, mid-sentence.
     */
    function openLightbox() {
        if (photos.length === 0) return;

        const photo = photos[shown];
        lightboxImg.src = photo.url;
        lightboxImg.alt = photo.alt || '';
        lightboxCap.textContent = photo.caption || '';
        lightbox.dataset.single = photos.length < 2 ? 'true' : 'false';

        lightbox.hidden = false;
        void lightbox.offsetHeight;
        lightbox.dataset.open = 'true';

        document.getElementById('worldLightboxClose').focus({ preventScroll: true });
    }

    function closeLightbox(returnFocus = true) {
        if (lightbox.hidden) return;

        lightbox.dataset.open = 'false';
        lightbox.hidden = true;
        lightboxImg.removeAttribute('src');

        /* Not when the panel underneath is closing too — it is about to take
           focus back to whatever opened it, and two claims on it in the same
           tick is how focus ends up on the body. */
        if (returnFocus && !zoomEl.hidden) zoomEl.focus({ preventScroll: true });
    }

    function drawDots() {
        dotsEl.textContent = '';

        if (photos.length < 2) return;

        photos.forEach((photo, i) => {
            const dot = document.createElement('button');
            dot.type = 'button';
            dot.setAttribute('aria-label', 'Photograph ' + (i + 1) + ' of ' + photos.length);
            if (i === shown) dot.setAttribute('aria-current', 'true');
            dot.addEventListener('click', () => showPhoto(i));
            dotsEl.appendChild(dot);
        });
    }

    panelClose.addEventListener('click', close);
    document.getElementById('worldPhotoPrev').addEventListener('click', () => showPhoto(shown - 1));
    document.getElementById('worldPhotoNext').addEventListener('click', () => showPhoto(shown + 1));

    zoomEl.addEventListener('click', openLightbox);

    document.getElementById('worldLightboxClose').addEventListener('click', closeLightbox);
    document.getElementById('worldLightboxPrev').addEventListener('click', () => showPhoto(shown - 1));
    document.getElementById('worldLightboxNext').addEventListener('click', () => showPhoto(shown + 1));

    /* Anywhere off the photograph closes it — including the photograph's own
       backdrop, which is the whole screen. */
    lightbox.addEventListener('click', (ev) => {
        if (ev.target === lightbox || ev.target === lightboxImg) closeLightbox();
    });

    /* And anywhere off the panel closes that. A modal with no way out but a
       small × in a corner is a modal people feel trapped by. */
    document.getElementById('worldPanelVeil').addEventListener('click', close);

    /* The Go button is an ordinary anchor, so it works with the keyboard, opens
       in a new tab on a middle click, and can be copied. The wipe is the only
       thing added to it, and only for a plain left click. */
    panelGo.addEventListener('click', (ev) => {
        if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.button !== 0) return;

        ev.preventDefault();
        const url = panelGo.href;
        wipe(() => {
            window.location.href = url;
        });
    });

    document.addEventListener(
        'keydown',
        (ev) => {
            if (!openPlace) return;

            if (ev.key === 'Escape') {
                /* One layer at a time. Escape out of the enlarged photograph
                   lands back in the panel it was opened from, not on the map. */
                if (!lightbox.hidden) {
                    closeLightbox();
                } else {
                    close();
                }

                return;
            }

            /* Only while the panel is open, and only when nothing is being
               typed into — the same two conditions each scene's own arrow keys
               use, so the two never both act on one press. */
            if (ev.key !== 'ArrowLeft' && ev.key !== 'ArrowRight') return;
            if (ev.target instanceof Element && ev.target.closest('input, textarea, select')) return;

            ev.preventDefault();
            ev.stopPropagation();
            showPhoto(shown + (ev.key === 'ArrowRight' ? 1 : -1));
        },
        true,
    );

    [panelEl, lightbox].forEach((host) =>
        host.querySelectorAll('canvas[data-icon]').forEach(drawPanelIcon),
    );

    return {
        open,
        close,
        isOpen: () => openPlace !== null,
        place: () => openPlace,
    };
}

/*
|------------------------------------------------------------------------------
| Icons
|------------------------------------------------------------------------------
*/

/* The seal on the splash: a rust square with an amber cross, the same shape as
   the one on the hall's signage band, drawn large. */
export function drawSeal(canvas) {
    const c = canvas.getContext('2d');
    c.imageSmoothingEnabled = false;
    r(c, 0, 0, 32, 32, C.ink);
    r(c, 2, 2, 28, 28, C.rust);
    r(c, 13, 4, 6, 24, C.amber);
    r(c, 4, 13, 24, 6, C.amber);
    disc(c, 16, 16, 4, C.cream);
    disc(c, 16, 16, 2, C.navy);
}

export function drawGearIcon(canvas) {
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
 * world is embedded in — the mailbox on the public page, the dashboard in the
 * staff compound — and the renderer has no business knowing either.
 */
export function drawCornerIcon(canvas) {
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

    if (canvas.dataset.icon === 'mailbox') {
        /* The post box in the plaza, at sixteen pixels: a domed body on a post
           with its flag up. Same object as the landmark, so the corner button
           and the thing it stands for are recognisably one thing. */
        r(c, 6, 12, 4, 4, C.stoneDark);
        r(c, 2, 5, 10, 8, C.rust);
        r(c, 3, 3, 8, 2, C.rust);
        r(c, 4, 2, 6, 1, shade(C.rust, 20));
        r(c, 4, 6, 6, 2, C.ink);
        r(c, 4, 10, 5, 2, C.cream);
        r(c, 12, 4, 1, 8, C.stoneDark);
        r(c, 13, 4, 3, 3, C.amber);
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
 * The panel's three controls: close, and the two ways through a carousel.
 *
 * Drawn rather than typed. A "×" and a pair of chevrons in the display face
 * would be the obvious thing and would be the one place on this screen where a
 * shape came from a font's idea of a shape — at 16 pixels that reads as a
 * different design system leaking in. Three arrangements of rectangles cost
 * nothing and match the rest of the town exactly.
 */
export function drawPanelIcon(canvas) {
    const c = canvas.getContext('2d');
    c.imageSmoothingEnabled = false;
    c.clearRect(0, 0, 16, 16);

    if (canvas.dataset.icon === 'close') {
        /* Two stepped diagonals. Three pixels thick, so it still reads as a
           cross rather than as grit when the button is 32px wide. */
        for (let i = 0; i < 10; i++) {
            r(c, 3 + i, 3 + i, 3, 1, C.cream);
            r(c, 12 - i, 3 + i, 3, 1, C.cream);
        }
        return;
    }

    /* A solid triangle, as columns shortening by two — the pixel-art way to a
       diagonal edge with no grey anywhere along it. The apex is at the end it
       points to, so the tallest column is the far one. */
    const back = canvas.dataset.icon === 'prev';

    for (let i = 0; i < 7; i++) {
        const h = 13 - i * 2;
        r(c, back ? 11 - i : 4 + i, 8 - Math.floor(h / 2), 1, h, C.cream);
    }
}

/*
 * The dock's icons.
 *
 * One function, one switch, sixteen pixels each — the same vocabulary as the
 * corner buttons and the panel's controls, because a screen where some of the
 * symbols are drawn and some come from a font is a screen with two design
 * systems on it.
 *
 * Which one is decided by the markup's data-icon, so the layout can rearrange
 * the dock without this file being told.
 */
export function drawDockIcon(canvas) {
    const c = canvas.getContext('2d');
    c.imageSmoothingEnabled = false;
    c.clearRect(0, 0, 16, 16);

    switch (canvas.dataset.icon) {
        /* A roof over a door: the drawn town, which is where "out" is. */
        case 'town':
            for (let i = 0; i < 7; i++) r(c, 8 - i, 3 + i, 1 + i * 2, 1, C.rust);
            r(c, 3, 9, 11, 6, C.cream);
            r(c, 3, 9, 11, 1, shade(C.cream, -22));
            r(c, 7, 11, 4, 4, C.navy);
            break;

        /* The same pin the map draws over your own building. */
        case 'mine':
            r(c, 3, 1, 10, 10, C.ink);
            r(c, 4, 2, 8, 8, C.amber);
            r(c, 7, 5, 3, 3, C.ink);
            for (let i = 0; i < 4; i++) r(c, 7 + i, 11 + i, 3 - i, 1, C.ink);
            break;

        /* Four arrows out of a middle: move things. */
        case 'arrange':
            r(c, 7, 2, 3, 12, C.navy);
            r(c, 2, 7, 12, 3, C.navy);
            r(c, 6, 3, 5, 1, C.navy);
            r(c, 6, 12, 5, 1, C.navy);
            r(c, 3, 6, 1, 5, C.navy);
            r(c, 12, 6, 1, 5, C.navy);
            r(c, 7, 7, 3, 3, C.amber);
            break;

        /* A glass, for looking something up. */
        case 'find':
            disc(c, 7, 7, 5, C.navy);
            disc(c, 7, 7, 3, C.cream);
            r(c, 10, 10, 2, 2, C.navy);
            r(c, 11, 11, 4, 4, C.navy);
            break;

        /* A plus over a roofline: put something up. */
        case 'add':
            for (let i = 0; i < 5; i++) r(c, 3 - i + 3, 9 + i, 1 + i * 2, 1, C.rust);
            r(c, 3, 11, 8, 4, C.cream);
            r(c, 3, 11, 8, 1, shade(C.cream, -24));
            r(c, 11, 2, 3, 9, C.navy);
            r(c, 8, 5, 9, 3, C.navy);
            break;

        /* A padlock, for ground the municipality has not taken in. */
        case 'land':
            r(c, 5, 3, 6, 4, C.stoneDark);
            r(c, 7, 3, 2, 4, C.rockLight);
            r(c, 3, 6, 10, 8, C.amber);
            r(c, 3, 6, 10, 2, shade(C.amber, 24));
            r(c, 7, 9, 2, 3, C.ink);
            break;

        /* Four panes: the dashboard's own emblem, at half the size. */
        case 'dashboard':
            r(c, 2, 2, 5, 6, C.navy);
            r(c, 9, 2, 5, 4, C.navy);
            r(c, 2, 10, 5, 4, C.navy);
            r(c, 9, 8, 5, 6, C.navy);
            break;

        /* A door with a way in. */
        case 'signin':
            r(c, 2, 2, 8, 12, C.navy);
            r(c, 4, 4, 4, 10, C.cream);
            r(c, 11, 7, 4, 2, C.navy);
            r(c, 12, 5, 2, 2, C.navy);
            r(c, 12, 9, 2, 2, C.navy);
            break;

        case 'plus':
            r(c, 6, 2, 4, 12, C.ink);
            r(c, 2, 6, 12, 4, C.ink);
            break;

        case 'minus':
            r(c, 2, 6, 12, 4, C.ink);
            break;

        default:
            drawGearIcon(canvas);
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
export function drawGuideSprite(canvas) {
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
