import './bootstrap';
import Alpine from 'alpinejs';

const getJson = async (url) => {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || data.message || `HTTP ${res.status}`);
    return data;
};

/** Live-tail a server task's output until it finishes. */
Alpine.data('taskLog', (url, initial = {}) => ({
    output: initial.output ?? '',
    status: initial.status ?? 'pending',
    duration: initial.duration ?? null,
    running: initial.running ?? true,
    follow: true,
    timer: null,

    init() {
        this.$nextTick(() => this.scroll());
        if (this.running) this.poll();
    },
    async poll() {
        try {
            const data = await getJson(url);
            const changed = data.output !== this.output;
            Object.assign(this, { output: data.output, status: data.status, duration: data.duration, running: data.running });
            if (changed) this.$nextTick(() => this.scroll());
            if (!data.running) {
                // Let the page refresh deployment/site state once the task is done.
                if (this.$el.dataset.reloadOnFinish !== undefined) setTimeout(() => window.location.reload(), 800);
                return;
            }
        } catch (e) {
            console.warn(e);
        }
        this.timer = setTimeout(() => this.poll(), 1500);
    },
    scroll() {
        if (this.follow && this.$refs.log) this.$refs.log.scrollTop = this.$refs.log.scrollHeight;
    },
    destroy() {
        clearTimeout(this.timer);
    },
}));

/** Poll provisioning progress; reload the page when the server becomes active or fails. */
Alpine.data('serverStatus', (url, initial) => ({
    ...initial,
    init() {
        this.poll();
    },
    async poll() {
        try {
            const data = await getJson(url);
            const statusChanged = data.status !== this.status;
            Object.assign(this, data);
            if (statusChanged && ['active', 'failed'].includes(data.status)) {
                return window.location.reload();
            }
        } catch (e) {
            console.warn(e);
        }
        setTimeout(() => this.poll(), 3000);
    },
}));

/** Load-on-demand server stats (load, memory, disk). */
Alpine.data('serverStats', (url) => ({
    stats: null,
    error: null,
    loading: true,
    async init() {
        try {
            this.stats = await getJson(url);
        } catch (e) {
            this.error = e.message;
        }
        this.loading = false;
    },
    pct(used, total) {
        return total ? Math.round((used / total) * 100) : 0;
    },
    gb(kb) {
        return (kb / 1024 / 1024).toFixed(1) + ' GB';
    },
}));

/** Create-server form: provider → regions → sizes. */
Alpine.data('serverForm', (providers, routes, old = {}) => ({
    provider: old.provider ?? (providers.length ? String(providers[0].id) : 'custom'),
    type: old.type ?? 'server',
    region: old.region ?? '',
    size: old.size ?? '',
    regions: {},
    sizes: {},
    loadingRegions: false,
    loadingSizes: false,
    error: null,
    providers,
    name: old.name ?? '',
    ip: old.ip_address ?? '',
    // Import an existing server, optionally picked from a provider account
    importAccount: old.server_provider_id ? String(old.server_provider_id) : '',
    existing: [],
    loadingExisting: false,
    existingError: null,
    picked: old.provider_server_id ?? '',

    init() {
        this.$watch('provider', () => { this.region = ''; this.size = ''; this.loadRegions(); });
        this.$watch('region', () => { this.size = ''; this.loadSizes(); });
        this.$watch('importAccount', () => this.loadExisting());
        this.loadRegions();
        if (this.importAccount) this.loadExisting();
    },
    get isCustom() {
        return this.provider === 'custom';
    },
    get isImport() {
        return this.provider === 'import';
    },
    get isCloud() {
        return !this.isCustom && !this.isImport;
    },
    async loadExisting() {
        this.existing = []; this.existingError = null; this.picked = '';
        if (!this.importAccount) return;
        this.loadingExisting = true;
        try {
            this.existing = await getJson(routes.existing.replace('__ID__', this.importAccount));
        } catch (e) {
            this.existingError = e.message;
        }
        this.loadingExisting = false;
    },
    pick(machine) {
        this.picked = machine.id;
        this.name = machine.name.replace(/[^A-Za-z0-9._-]/g, '-');
        this.ip = machine.ip ?? '';
    },
    get hasPhp() {
        return ['server', 'worker'].includes(this.type);
    },
    get hasDb() {
        return ['server', 'database'].includes(this.type);
    },
    async loadRegions() {
        this.regions = {}; this.sizes = {}; this.error = null;
        if (!this.isCloud) return;
        this.loadingRegions = true;
        try {
            this.regions = await getJson(routes.regions.replace('__ID__', this.provider));
        } catch (e) {
            this.error = e.message;
        }
        this.loadingRegions = false;
        if (this.region) this.loadSizes();
    },
    async loadSizes() {
        this.sizes = {};
        if (!this.isCloud || !this.region) return;
        this.loadingSizes = true;
        try {
            const url = routes.sizes.replace('__ID__', this.provider) + '?region=' + encodeURIComponent(this.region);
            this.sizes = await getJson(url);
        } catch (e) {
            this.error = e.message;
        }
        this.loadingSizes = false;
    },
}));

/** Install-repository form with repository autocomplete from the connected provider. */
Alpine.data('repoForm', (sources, route, old = {}) => ({
    provider: old.repository_provider ?? (sources[0]?.provider ?? 'github'),
    sourceId: old.source_provider_id ?? (sources[0] ? String(sources[0].id) : ''),
    repos: [],
    init() {
        this.$watch('sourceId', () => this.load());
        this.$watch('provider', () => {
            const match = sources.find((s) => s.provider === this.provider);
            this.sourceId = match ? String(match.id) : '';
        });
        this.load();
    },
    get sourcesForProvider() {
        return sources.filter((s) => s.provider === this.provider);
    },
    async load() {
        this.repos = [];
        if (!this.sourceId) return;
        try {
            this.repos = await getJson(route.replace('__ID__', this.sourceId));
        } catch (e) {
            console.warn(e);
        }
    },
}));

Alpine.data('reveal', (url) => ({
    value: null,
    async show() {
        const data = await getJson(url);
        this.value = data.password;
    },
}));

window.copyText = (text, el) => {
    navigator.clipboard?.writeText(text).then(() => {
        if (!el) return;
        const original = el.textContent;
        el.textContent = 'Copied!';
        setTimeout(() => (el.textContent = original), 1200);
    });
};

window.Alpine = Alpine;
Alpine.start();
