/*
 * Closes the x-cloak window.
 *
 * Until this fires, `html:not([data-alpine-ready]) [x-cloak]` hides anything
 * marked cloaked, which is what stops modals and menus flashing on screen
 * during the gap before Alpine boots. Afterwards the rule stops applying, so a
 * stray x-cloak — and Livewire's morph puts one back on every hidden x-show
 * element it patches — cannot hide something the page has since been asked to
 * show. See the note in app.css for the full mechanism.
 */
document.addEventListener('alpine:initialized', () => {
    document.documentElement.setAttribute('data-alpine-ready', '');
});

document.addEventListener('alpine:init', () => {
    /**
     * The drive's selection layer.
     *
     * Selection is held here rather than on the server: a click that has to
     * make a round trip before the item looks picked feels broken, and a clerk
     * rubber-banding a box over thirty scans would otherwise fire thirty
     * requests. The server is told only when something is actually done, and it
     * re-reads and re-authorises every key it is handed — nothing here is a
     * permission check, only an offer of one.
     *
     * What each item is and what may be done to it is read from data-
     * attributes rendered onto the item itself, so this file never has to hold
     * a second copy of the listing.
     */
    Alpine.data('driveBrowser', (config) => ({
        canWrite: config.canWrite,
        bundleUrl: config.bundleUrl,

        // Upload and drag-to-upload.
        dragging: false,
        progress: null,

        // Selection.
        selected: [],
        anchor: null,
        detailsOpen: false,

        // Transient interaction state.
        marquee: null,
        menu: null,
        dropTarget: null,
        lastOpened: { key: null, at: 0 },

        init() {
            // A listing that has just been replaced — another folder, another
            // view, or the aftermath of a bulk act — cannot keep a selection
            // pointing at rows that are no longer on the page.
            this.$wire.on('drive-cleared', () => this.clear());
        },

        /*
        |----------------------------------------------------------------------
        | Reading the listing
        |----------------------------------------------------------------------
        */

        /**
         * Found from the document, not from a ref or from $el.
         *
         * There is one drive on a page, and its listing is marked with
         * data-canvas. Every other way of reaching these rows has now failed at
         * least once here: an x-ref registers only on Alpine's first walk, and
         * a lookup relative to the component root depends on this method being
         * called with the scope its author assumed. A plain document query has
         * no such assumption to get wrong.
         *
         * Nothing on the critical path — opening a folder or a file — goes
         * through here any more. Both are real links. This is for the selection
         * layer: ranges, select-all, the marquee, and deciding which buttons a
         * selection may offer. If it ever returns nothing, selection stops
         * working and the drive is still usable.
         */
        items() {
            return Array.from(document.querySelectorAll('[data-canvas] [data-key]'));
        },

        keysInOrder() {
            return this.items().map((el) => el.dataset.key);
        },

        meta(key) {
            const el = this.items().find((item) => item.dataset.key === key);

            return el ? el.dataset : null;
        },

        /** Whether every selected item carries a given permission flag. */
        every(flag) {
            return this.selected.length > 0
                && this.selected.every((key) => this.meta(key)?.[flag] === '1');
        },

        get fileKeys() {
            return this.selected.filter((key) => key.startsWith('file:'));
        },

        /*
        |----------------------------------------------------------------------
        | Selecting
        |----------------------------------------------------------------------
        */

        has(key) {
            return this.selected.includes(key);
        },

        selectOnly(key) {
            this.selected = [key];
            this.anchor = key;
            this.syncDetails();
        },

        toggle(key) {
            this.selected = this.has(key)
                ? this.selected.filter((k) => k !== key)
                : [...this.selected, key];
            this.anchor = key;
            this.syncDetails();
        },

        /** Shift-click: everything between the last anchor and this one. */
        range(key) {
            const order = this.keysInOrder();
            const from = order.indexOf(this.anchor ?? key);
            const to = order.indexOf(key);

            if (from === -1 || to === -1) {
                this.selectOnly(key);

                return;
            }

            this.selected = order.slice(Math.min(from, to), Math.max(from, to) + 1);
            this.syncDetails();
        },

        selectAll() {
            this.selected = this.keysInOrder();
            this.anchor = this.selected[0] ?? null;
            this.syncDetails();
        },

        clear() {
            this.selected = [];
            this.anchor = null;
            this.menu = null;
            this.syncDetails();
        },

        /**
         * A plain click opens. Selecting is the tick box, a modifier, or a
         * dragged box.
         *
         * Google Drive selects on one click and opens on two, but a clerk
         * opening a folder should not have to learn that, and this drive
         * opened on one click before any of this existed. Keeping the primary
         * click meaning "open" costs nothing: there are three other ways to
         * pick something, and all of them are visible on the row.
         */
        click(event, key) {
            // preventDefault on the modifier paths: the item's name is a real
            // link now, and ctrl-clicking a link opens a tab. Here the modifier
            // means "select", so the browser must be told to stand down.
            if (event.shiftKey) {
                event.preventDefault();
                this.range(key);

                return;
            }

            if (event.ctrlKey || event.metaKey) {
                event.preventDefault();
                this.toggle(key);

                return;
            }

            // Dropped without syncDetails(): opening is about to re-render the
            // whole listing anyway, and the pane would otherwise cost a second
            // round trip to be told about a selection that no longer exists.
            this.selected = [];
            this.anchor = null;
            this.menu = null;

            /*
             * A plain click that landed on the item's own link is the
             * browser's business, not ours. Calling openItem() here as well
             * would open the file twice.
             */
            if (event.target.closest('[data-open-link]')) {
                return;
            }

            this.openItem(key);
        },

        /*
        |----------------------------------------------------------------------
        | Rubber-band selection
        |----------------------------------------------------------------------
        */

        startMarquee(event) {
            // Left button only, and only on the empty canvas — a press that
            // lands on a row, a button or a link belongs to that thing.
            if (event.button !== 0) {
                return;
            }

            if (event.target.closest('[data-key], a, button, input, select, textarea, [role="dialog"]')) {
                return;
            }

            const additive = event.shiftKey || event.ctrlKey || event.metaKey;
            const base = additive ? [...this.selected] : [];
            const from = { x: event.clientX, y: event.clientY };
            let live = false;

            // Suppress the text selection a drag across the page would other-
            // wise paint over every filename it crosses.
            event.preventDefault();

            const move = (moved) => {
                // A few pixels of slop, so a plain click is still a click.
                if (! live && Math.hypot(moved.clientX - from.x, moved.clientY - from.y) < 5) {
                    return;
                }

                live = true;
                this.marquee = this.box(from, { x: moved.clientX, y: moved.clientY });
                this.selected = [...new Set([...base, ...this.within(this.marquee)])];
            };

            const up = () => {
                window.removeEventListener('mousemove', move);
                window.removeEventListener('mouseup', up);

                // A press and release on empty space with no drag is how you
                // put a selection down.
                if (! live && ! additive) {
                    this.clear();
                }

                this.marquee = null;
                this.syncDetails();
            };

            window.addEventListener('mousemove', move);
            window.addEventListener('mouseup', up);
        },

        box(a, b) {
            return {
                left: Math.min(a.x, b.x),
                top: Math.min(a.y, b.y),
                width: Math.abs(a.x - b.x),
                height: Math.abs(a.y - b.y),
            };
        },

        /** Every item whose rectangle overlaps the box, in viewport terms. */
        within(box) {
            return this.items()
                .filter((el) => {
                    const r = el.getBoundingClientRect();

                    return r.left < box.left + box.width
                        && r.left + r.width > box.left
                        && r.top < box.top + box.height
                        && r.top + r.height > box.top;
                })
                .map((el) => el.dataset.key);
        },

        /** The overlay lives inside the canvas, so viewport coords come back to it. */
        marqueeStyle() {
            // Read from the DOM each time rather than a stored ref, for the
            // same reason items() does.
            const canvasEl = document.querySelector('[data-canvas]');

            if (! this.marquee || ! canvasEl) {
                return 'display:none';
            }

            const canvas = canvasEl.getBoundingClientRect();

            return `left:${this.marquee.left - canvas.left}px;`
                + `top:${this.marquee.top - canvas.top}px;`
                + `width:${this.marquee.width}px;height:${this.marquee.height}px`;
        },

        /*
        |----------------------------------------------------------------------
        | Context menu
        |----------------------------------------------------------------------
        */

        openMenu(event, key = null) {
            event.preventDefault();
            event.stopPropagation();

            if (key && ! this.has(key)) {
                this.selectOnly(key);
            }

            if (! key) {
                this.clear();
            }

            // Kept clear of the edges so the menu never opens partly off-screen.
            this.menu = {
                x: Math.min(event.clientX, window.innerWidth - 216),
                y: Math.min(event.clientY, window.innerHeight - 300),
            };
        },

        /*
        |----------------------------------------------------------------------
        | Dragging items onto folders
        |----------------------------------------------------------------------
        */

        startDrag(event, key) {
            if (! this.has(key)) {
                this.selectOnly(key);
            }

            const movable = this.selected.filter((k) => this.meta(k)?.move === '1');

            if (movable.length === 0) {
                event.preventDefault();

                return;
            }

            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('application/x-drive-keys', JSON.stringify(movable));
            // Some browsers will not start a drag that carries no text payload.
            event.dataTransfer.setData(
                'text/plain',
                movable.map((k) => this.meta(k)?.name ?? '').join(', '),
            );

            this.menu = null;
        },

        /** An item being dragged within the drive, as opposed to a file from the desktop. */
        isInternal(event) {
            return Array.from(event.dataTransfer?.types ?? [])
                .includes('application/x-drive-keys');
        },

        isFromDesktop(event) {
            return Array.from(event.dataTransfer?.types ?? []).includes('Files');
        },

        overFolder(event, key) {
            // A folder shared by another office is readable, so it is listed
            // and looks like a target. It is not one — leaving dropEffect unset
            // shows the "no" cursor rather than promising a move that refuses.
            if (! this.isInternal(event) || this.meta(key)?.store !== '1') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            event.dataTransfer.dropEffect = 'move';
            this.dropTarget = key;
        },

        dropOnFolder(event, key) {
            if (! this.isInternal(event) || this.meta(key)?.store !== '1') {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            this.dropTarget = null;

            let keys = [];

            try {
                keys = JSON.parse(event.dataTransfer.getData('application/x-drive-keys'));
            } catch {
                return;
            }

            // Dropping a folder onto itself is a no-op, not a mistake worth a
            // message; the server would refuse it, but silence reads better.
            keys = keys.filter((dragged) => dragged !== key);

            if (keys.length) {
                this.$wire.bulkMove(keys, Number(key.split(':')[1]));
            }
        },

        /*
        |----------------------------------------------------------------------
        | Acting on the selection
        |----------------------------------------------------------------------
        */

        /**
         * Deliberately NOT called open().
         *
         * This object is the root x-data of the whole drive, and Livewire
         * resolves a wire:click expression against the surrounding Alpine
         * scope before it reaches the component's own methods. An Alpine
         * method named open() silently shadows the Livewire open($panel, $id)
         * behind every "New folder", "Rename", "Sharing" and "New version"
         * button on the page — they stop doing anything at all, with no error.
         * The same warning is written on the dropdown component, for the same
         * reason. DriveNamingTest keeps this honest.
         */
        openItem(key) {
            const item = this.meta(key);

            if (! item) {
                return;
            }

            // A double-click fires click twice before dblclick. Without this a
            // download-only file would be fetched twice.
            const now = Date.now();

            if (this.lastOpened.key === key && now - this.lastOpened.at < 500) {
                return;
            }

            this.lastOpened = { key, at: now };

            if (item.kind === 'folder') {
                this.$wire.openFolder(Number(key.split(':')[1]));

                return;
            }

            if (item.open !== '1' || ! item.url) {
                return;
            }

            /*
             * The same destination the item's link points at.
             *
             * This path is only reached from the keyboard and the context
             * menu; an ordinary click follows the anchor itself. It used to
             * raise an in-page overlay instead, which is what broke: the
             * overlay depended on state that a re-render could leave stale,
             * and a file that would not open had no fallback at all. The
             * browser's own viewer needs no state to work.
             */
            if (item.preview === '1') {
                window.open(item.url, '_blank', 'noopener');
            } else {
                window.location.href = item.url;
            }
        },

        download() {
            const keys = this.fileKeys;

            if (keys.length === 0) {
                return;
            }

            if (keys.length === 1) {
                window.location.href = this.meta(keys[0]).downloadUrl;

                return;
            }

            const ids = keys.map((key) => `ids[]=${encodeURIComponent(key.split(':')[1])}`);

            window.location.href = `${this.bundleUrl}?${ids.join('&')}`;
        },

        /**
         * Files go to the trash, which is undoable, so they go without asking —
         * the same bargain Drive makes. Folders are not soft-deleted:
         * deleteFolder() removes the row outright, so a selection containing
         * one has to be confirmed, and the Delete key must not take a folder
         * away on a single keystroke.
         */
        trash() {
            if (! this.selected.length) {
                return;
            }

            const folders = this.selected.filter((key) => key.startsWith('folder:')).length;

            if (folders > 0) {
                const asked = window.confirm(
                    folders === 1
                        ? 'Delete the selected folder? A folder is removed for good, not moved to the trash, and must already be empty.'
                        : `Delete ${folders} selected folders? Folders are removed for good, not moved to the trash, and must already be empty.`,
                );

                if (! asked) {
                    return;
                }
            }

            this.$wire.bulkTrash(this.selected);
        },

        restore() {
            if (this.selected.length) {
                this.$wire.bulkRestore(this.selected);
            }
        },

        purge() {
            if (! this.selected.length) {
                return;
            }

            const answer = window.confirm(
                `Destroy ${this.selected.length} item(s) and every version? This cannot be undone.`,
            );

            if (answer) {
                this.$wire.bulkPurge(this.selected);
            }
        },

        move() {
            if (this.selected.length) {
                this.$wire.openFor('move', this.selected);
            }
        },

        /** Panels that only ever act on one row still reach them through the menu. */
        single(panel) {
            if (this.selected.length !== 1) {
                return;
            }

            const [kind, id] = this.selected[0].split(':');

            this.$wire.open(
                panel === 'rename' ? (kind === 'folder' ? 'rename-folder' : 'rename-file') : panel,
                Number(id),
            );

            this.menu = null;
        },

        /*
        |----------------------------------------------------------------------
        | Details pane
        |----------------------------------------------------------------------
        */

        toggleDetails() {
            this.detailsOpen = ! this.detailsOpen;
            this.syncDetails();
        },

        /** Only ever describes one thing, and only while the pane is open. */
        syncDetails() {
            if (! this.detailsOpen) {
                return;
            }

            this.$wire.loadDetails(this.selected.length === 1 ? this.selected[0] : null);
        },

        /*
        |----------------------------------------------------------------------
        | Keyboard
        |----------------------------------------------------------------------
        */

        hotkey(event) {
            const tag = (event.target.tagName ?? '').toLowerCase();

            // Never while the user is typing, and never over a dialog that owns
            // the keyboard itself.
            if (['input', 'textarea', 'select'].includes(tag) || event.target.isContentEditable) {
                return;
            }

            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'a') {
                event.preventDefault();
                this.selectAll();

                return;
            }

            if (event.key === 'Escape') {
                if (this.menu) {
                    this.menu = null;
                } else {
                    this.clear();
                }

                return;
            }

            if (event.key === 'Delete' && this.every('delete')) {
                this.trash();

                return;
            }

            if (event.key === 'Enter' && this.selected.length === 1) {
                this.openItem(this.selected[0]);
            }
        },
    }));

    Alpine.store('tour', {
        open: false,
    });

    Alpine.data('tourGuide', (steps, autoStart) => ({
        steps,
        index: 0,
        rect: null,

        init() {
            window.addEventListener('tour:start', () => this.start());

            const reposition = () => this.measure();
            window.addEventListener('resize', reposition);
            window.addEventListener('scroll', reposition, true);

            if (autoStart) {
                this.start();
            }
        },

        get step() {
            return this.steps[this.index] ?? null;
        },

        get isFirst() {
            return this.index === 0;
        },

        get isLast() {
            return this.index === this.steps.length - 1;
        },

        start() {
            this.index = 0;
            this.$store.tour.open = true;
            this.$nextTick(() => this.measure());
        },

        next() {
            if (this.isLast) {
                this.finish();

                return;
            }

            this.index++;
            this.$nextTick(() => this.measure());
        },

        prev() {
            if (this.isFirst) {
                return;
            }

            this.index--;
            this.$nextTick(() => this.measure());
        },

        finish() {
            this.$store.tour.open = false;
            this.rect = null;

            fetch('/tour/complete', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    Accept: 'application/json',
                },
            }).catch(() => {
                // A failed ping just means the tour offers itself again next
                // visit — harmless, so there is nothing to show the user.
            });
        },

        // Where the current step's target sits on screen, or null for the
        // intro/outro cards, which describe the whole system rather than one
        // piece of it and so are centred instead of pinned to anything.
        measure() {
            const step = this.step;

            if (!step || !step.icon) {
                this.rect = null;

                return;
            }

            const el = document.querySelector(`[data-tour="${step.icon}"]`);

            if (!el) {
                this.rect = null;

                return;
            }

            const r = el.getBoundingClientRect();
            this.rect = { top: r.top, left: r.left, width: r.width, height: r.height };
        },

        cardStyle() {
            if (!this.rect) {
                return 'top:50%;left:50%;transform:translate(-50%,-50%);';
            }

            const top = this.rect.top + this.rect.height + 12;
            const maxLeft = window.innerWidth - 336;
            const left = Math.max(12, Math.min(this.rect.left, maxLeft));

            return `top:${top}px;left:${left}px;`;
        },
    }));
});
