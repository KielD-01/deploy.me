// The run page's log. Lines arrive over Reverb as the runner writes them;
// anything missed (the first load, a batch too big to broadcast, a reconnect)
// is fetched from the Livewire component. Text only ever goes in via
// textContent, and the pane keeps the last 5000 lines. Each line comes with the
// UTC instant it was logged at, shown on this browser's clock.
//
// A page can fold the log by step (the Console theme's run page): each step's
// lines then go into the element marked data-log-step="<key>" inside a
// <details data-fold="<key>">, whose summary's data-open says whether the step
// is open by itself (running, failed). A step someone folded or unfolded stays
// that way. Lines of no step, or of one the page has no fold for yet, stay in
// x-ref="lines".
const MAX_LINES = 5000;
const ACTIVE = ['queued', 'waiting_ci', 'running'];

const clock = (at) => {
    const time = new Date(at);
    if (Number.isNaN(time.getTime())) return at;
    return [time.getHours(), time.getMinutes(), time.getSeconds()].map((part) => String(part).padStart(2, '0')).join(':');
};

export default ({ id, live, steps }) => ({
    live,
    steps,
    step: '',
    query: '',
    count: 0,
    rows: 0,
    touched: {},
    lastSeq: 0,
    follow: true,
    loading: false,
    reloadAgain: false,

    init() {
        this.fetchMissing();
        this.$watch('step', () => this.applyFilter());
        this.$watch('query', () => this.applyFilter());

        if (this.$root.querySelector('[data-fold]')) {
            this.sync();
            window.Livewire?.hook('commit', ({ component, succeed }) => {
                if (component.id === this.$wire.$id) succeed(() => this.$nextTick(() => this.sync()));
            });
        }

        const channel = window.Echo?.private(`deployments.${id}`);
        channel?.listen('.log', (event) => this.receive(event));
        channel?.listen('.updated', (event) => {
            this.live = ACTIVE.includes(event.status);
            if (!this.live) this.fetchMissing();
        });
    },

    destroy() {
        // Livewire listens on the same channel, so only drop this component's listener.
        window.Echo?.private(`deployments.${id}`)?.stopListening('.log');
    },

    async fetchMissing() {
        if (this.loading) {
            this.reloadAgain = true;
            return;
        }
        this.loading = true;
        try {
            let batch;
            do {
                batch = await this.$wire.linesAfter(this.lastSeq);
                this.append(batch);
            } while (batch.length === 2000);
        } finally {
            this.loading = false;
        }
        if (this.reloadAgain) {
            this.reloadAgain = false;
            this.fetchMissing();
        }
    },

    receive(event) {
        // A fetch is under way: it runs once more afterwards and picks these up in order.
        if (this.loading) {
            this.reloadAgain = true;
            return;
        }
        if (event.lines.length === 0 || event.from > this.lastSeq + 1) {
            this.fetchMissing();
            return;
        }
        this.append(event.lines);
    },

    append(lines) {
        // Lines that arrive twice (a broadcast overlapping a fetch) count once.
        lines = lines.filter((line) => line[0] > this.lastSeq);
        if (lines.length === 0) return;

        const fragment = document.createDocumentFragment();
        for (const [seq, stream, step, time, text] of lines) {
            if (step && !(step in this.steps)) this.steps[step] = step;

            const row = document.createElement('div');
            row.className = `log-line log-${stream}`;
            row.dataset.step = step ?? '';

            const stamp = document.createElement('span');
            stamp.className = 'log-time';
            stamp.textContent = clock(time);

            const body = document.createElement('span');
            body.textContent = text;

            row.append(stamp, body);
            row.hidden = !this.shows(row);
            (this.foldFor(row.dataset.step) ?? fragment).append(row);
            this.lastSeq = Math.max(this.lastSeq, seq);
        }

        this.$refs.lines.append(fragment);
        this.count += lines.length;
        this.rows += lines.length;
        // The oldest go first, wherever they are.
        while (this.rows > MAX_LINES) {
            this.$root.querySelector('.log-line')?.remove();
            this.rows--;
        }

        if (this.follow) this.$nextTick(() => (this.$refs.pane.scrollTop = this.$refs.pane.scrollHeight));
    },

    shows(row) {
        return (this.step === '' || row.dataset.step === this.step)
            && (this.query === '' || row.textContent.toLowerCase().includes(this.query.toLowerCase()));
    },

    applyFilter() {
        for (const row of this.$root.querySelectorAll('.log-line')) {
            row.hidden = !this.shows(row);
        }
    },

    foldFor(step) {
        return step ? this.$root.querySelector(`[data-log-step="${CSS.escape(step)}"]`) : null;
    },

    // After each render: folds open or close with their step unless someone
    // chose for them, and lines that came before their step's fold move in.
    sync() {
        for (const fold of this.$root.querySelectorAll('details[data-fold]')) {
            const auto = fold.querySelector('summary')?.dataset.open === '1';
            if (!(fold.dataset.fold in this.touched)) fold.open = auto;
        }
        for (const row of [...this.$refs.lines.children]) {
            this.foldFor(row.dataset.step)?.append(row);
        }
    },

    touch(key) {
        this.touched[key] = true;
    },

    // z: fold every step, or unfold them all when they're all folded.
    foldAll() {
        const folds = [...this.$root.querySelectorAll('details[data-fold]')];
        const open = !folds.some((fold) => fold.open);
        for (const fold of folds) {
            fold.open = open;
            this.touched[fold.dataset.fold] = true;
        }
    },

    end() {
        this.follow = true;
        this.$refs.pane.scrollTop = this.$refs.pane.scrollHeight;
    },

    atBottom() {
        const pane = this.$refs.pane;
        return pane.scrollHeight - pane.scrollTop - pane.clientHeight < 24;
    },
});
