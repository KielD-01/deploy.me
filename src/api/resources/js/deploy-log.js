// The run page's log. Lines arrive over Reverb as the runner writes them;
// anything missed (the first load, a batch too big to broadcast, a reconnect)
// is fetched from the Livewire component. Text only ever goes in via
// textContent, and the pane keeps the last 5000 lines. Each line comes with the
// UTC instant it was logged at, shown on this browser's clock.
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
    count: 0,
    lastSeq: 0,
    follow: true,
    loading: false,
    reloadAgain: false,

    init() {
        this.fetchMissing();
        this.$watch('step', () => this.applyFilter());

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
            row.hidden = this.step !== '' && row.dataset.step !== this.step;

            const stamp = document.createElement('span');
            stamp.className = 'log-time';
            stamp.textContent = clock(time);

            const body = document.createElement('span');
            body.textContent = text;

            row.append(stamp, body);
            fragment.append(row);
            this.lastSeq = Math.max(this.lastSeq, seq);
        }

        const pane = this.$refs.lines;
        pane.append(fragment);
        this.count += lines.length;
        while (pane.childElementCount > MAX_LINES) pane.firstElementChild.remove();

        if (this.follow) this.$nextTick(() => (this.$refs.pane.scrollTop = this.$refs.pane.scrollHeight));
    },

    applyFilter() {
        for (const row of this.$refs.lines.children) {
            row.hidden = this.step !== '' && row.dataset.step !== this.step;
        }
    },

    atBottom() {
        const pane = this.$refs.pane;
        return pane.scrollHeight - pane.scrollTop - pane.clientHeight < 24;
    },
});
