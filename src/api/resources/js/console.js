// The Console theme (resources/views/themes/console): tmux's keys, the
// session's panes, and the shell's prompt. Everything here also works with
// the mouse; the keys are a shortcut, never the only way.

// ⌃b then a key, like tmux. Window-level keys go to the session as a `tmux`
// event; the layout's own (windows, the tree, the key list) are handled here.
export const tmuxKeys = (windows) => ({
    prefix: false,
    overlay: null,
    timer: null,

    key(event) {
        if (event.ctrlKey && !event.metaKey && !event.altKey && event.key === 'b') {
            event.preventDefault();
            this.prefix = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => (this.prefix = false), 1500);
            return;
        }
        // F1–F6 go straight to a window, no prefix (on a Mac keyboard that may take fn).
        const fkey = /^F(\d)$/.exec(event.key);
        if (fkey && !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey && windows[Number(fkey[1]) - 1]) {
            event.preventDefault();
            this.prefix = false;
            this.overlay = null;
            window.Livewire.navigate(windows[Number(fkey[1]) - 1]);
            return;
        }
        if (event.key === 'Escape' && this.overlay) {
            this.overlay = null;
            return;
        }
        if (!this.prefix) return;

        this.prefix = false;
        // The physical key decides for the shifted ones, so % and " work on any layout.
        let key = event.key;
        if (event.shiftKey && event.code === 'Digit5') key = '%';
        if (event.shiftKey && event.code === 'Quote') key = '"';
        if (event.shiftKey && /^Digit\d$/.test(event.code) && key !== '%') return;
        const send = (action, extra = {}) => window.dispatchEvent(new CustomEvent('tmux', { detail: { action, ...extra } }));

        if (/^[0-9]$/.test(key) && windows[Number(key)]) {
            event.preventDefault();
            window.Livewire.navigate(windows[Number(key)]);
        } else if (key === 'n' || key === 'p') {
            event.preventDefault();
            const here = windows.indexOf(location.origin + location.pathname);
            const next = (Math.max(here, 0) + (key === 'n' ? 1 : windows.length - 1)) % windows.length;
            window.Livewire.navigate(windows[next]);
        } else if (key === 'w' || key === '?') {
            event.preventDefault();
            this.overlay = key === 'w' ? 'tree' : 'keys';
        } else if (key === '%' || key === '"') {
            event.preventDefault();
            send('split', { dir: key === '%' ? 'row' : 'col' });
        } else if (['z', 'o', 'x', 'L', '/'].includes(key)) {
            event.preventDefault();
            send({ z: 'zoom', o: 'next', x: 'close', L: 'layout', '/': 'shell' }[key]);
        }
    },
});

// The session: which pane is active and zoomed, and dragging the borders.
export const tmuxSession = () => ({
    active: null,
    zoomed: null,
    layoutIndex: 0,

    init() {
        this.active = this.panes()[0] ?? null;
    },

    panes() {
        return [...this.$root.querySelectorAll('[data-pane]')].map((pane) => pane.dataset.pane);
    },

    zoom(id) {
        this.zoomed = this.zoomed ? null : id;
        this.$root.closest('.tmux')?.classList.toggle('is-zoomed', this.zoomed !== null);
        this.$root.querySelectorAll('[data-pane]').forEach((pane) => pane.classList.toggle('is-zoomed', pane.dataset.pane === this.zoomed));
    },

    handle({ action, dir }) {
        const panes = this.panes();
        if (!panes.includes(this.active)) this.active = panes[0] ?? null;

        if (action === 'split') this.$wire.split(this.active, dir);
        if (action === 'close' && panes.length > 1) {
            const closing = this.active;
            this.active = panes.find((pane) => pane !== closing);
            this.$wire.close(closing);
        }
        if (action === 'zoom') this.zoom(this.active);
        if (action === 'next') this.active = panes[(panes.indexOf(this.active) + 1) % panes.length];
        if (action === 'layout') this.$wire.load(['ops', 'deploy', 'watch'][++this.layoutIndex % 3]);
        if (action === 'shell') {
            const shell = this.$root.querySelector('[data-pane] .term-input');
            shell?.focus();
            this.active = shell?.closest('[data-pane]')?.dataset.pane ?? this.active;
        }
    },

    // Drag a border: the two panes beside it share their space; the new sizes are saved on release.
    drag(event, path, index) {
        const handle = event.currentTarget;
        const split = handle.parentElement;
        const row = split.dataset.dir === 'row';
        const children = [...split.children].filter((child) => !child.classList.contains('tmux-handle'));
        const total = row ? split.clientWidth : split.clientHeight;
        const sizes = children.map((child) => ((row ? child.offsetWidth : child.offsetHeight) / total) * 100);
        const start = row ? event.clientX : event.clientY;
        const before = sizes[index - 1];
        const after = sizes[index];

        const move = (e) => {
            const delta = (((row ? e.clientX : e.clientY) - start) / total) * 100;
            sizes[index - 1] = Math.max(5, Math.min(before + after - 5, before + delta));
            sizes[index] = before + after - sizes[index - 1];
            children[index - 1].style.flexGrow = sizes[index - 1];
            children[index].style.flexGrow = sizes[index];
        };
        const up = () => {
            window.removeEventListener('mousemove', move);
            window.removeEventListener('mouseup', up);
            this.$wire.resize(path, sizes);
        };
        window.addEventListener('mousemove', move);
        window.addEventListener('mouseup', up);
    },
});

// A shell pane's prompt: history, Tab completion, and picking an answer. What
// the command waits on comes from the component ($wire.ask), so it follows
// every round trip.
export const consoleShell = () => ({
    input: '',
    history: (() => {
        try {
            return JSON.parse(sessionStorage.getItem('deployme-shell-history') || '[]');
        } catch {
            return [];
        }
    })(),
    cursor: -1,
    hints: [],
    pick: 0,

    get choosing() {
        return this.$wire.ask?.type === 'choice';
    },

    get options() {
        return this.$wire.ask?.options ?? [];
    },

    init() {
        this.$nextTick(() => this.settle());
        window.Livewire.hook('commit', ({ component, succeed }) => {
            if (component.id === this.$wire.$id) succeed(() => this.$nextTick(() => this.settle()));
        });
    },

    // After each answer: scroll to the end, and put the focus where the next key goes.
    settle() {
        const screen = this.$refs.screen;
        if (screen) screen.scrollTop = screen.scrollHeight;
        if (this.choosing) {
            this.pick = 0;
            this.$root.focus();
        } else if (this.$root.contains(document.activeElement)) {
            this.$refs.input?.focus();
        }
    },

    focus() {
        if (window.getSelection()?.toString()) return;
        if (this.choosing) this.$root.focus();
        else this.$refs.input?.focus();
    },

    async keydown(event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            const line = this.input;
            if (line.trim() && !this.$wire.ask) {
                this.history = [...this.history.filter((entry) => entry !== line), line].slice(-100);
                try {
                    sessionStorage.setItem('deployme-shell-history', JSON.stringify(this.history));
                } catch {}
            }
            this.input = '';
            this.hints = [];
            this.cursor = -1;
            await this.$wire.submit(line);
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            if (!this.history.length) return;
            event.preventDefault();
            this.cursor = event.key === 'ArrowUp' ? Math.min(this.cursor + 1, this.history.length - 1) : Math.max(this.cursor - 1, -1);
            this.input = this.cursor === -1 ? '' : this.history[this.history.length - 1 - this.cursor];
        } else if (event.key === 'Tab') {
            event.preventDefault();
            const found = await this.$wire.complete(this.input);
            if (found.length === 1) {
                this.input = found[0] + ' ';
                this.hints = [];
            } else {
                this.hints = found.map((entry) => entry.split(' ').pop());
            }
        } else if (event.key === 'c' && event.ctrlKey) {
            event.preventDefault();
            this.input = '';
            this.$wire.cancelAsk();
        }
    },

    // ↑↓ and Enter while a command waits on a pick; Esc cancels it.
    pickKey(event) {
        if (!this.choosing) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.pick = (this.pick + (event.key === 'ArrowDown' ? 1 : this.options.length - 1)) % this.options.length;
        } else if (event.key === 'Enter') {
            event.preventDefault();
            this.choose(this.pick);
        } else if (event.key === 'Escape' || (event.key === 'c' && event.ctrlKey)) {
            event.preventDefault();
            this.$wire.cancelAsk();
        }
    },

    choose(index) {
        const option = this.options[index];
        if (option) this.$wire.answer(option.value);
    },
});
