<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#1f1a17">
<title>{{ filled($title ?? null) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    // Small Alpine helpers. Defined inline (not in the Vite module) so they exist before Alpine starts.
    window.toastCenter = function () {
        return {
            items: [],
            nextId: 1,
            add(detail) {
                const toast = Array.isArray(detail) ? detail[0] : detail;
                const id = this.nextId++;
                this.items.push({ id, type: toast.type || 'success', message: toast.message || '' });
                setTimeout(() => this.remove(id), toast.type === 'error' ? 8000 : 4000);
            },
            remove(id) {
                this.items = this.items.filter((item) => item.id !== id);
            },
        };
    };

    // Searchable select (x-ui.combobox). The options come from a JSON script on the page:
    // [{value, label, hint, search}], where "search" is the lower-case text to match.
    window.combobox = function (source) {
        const LIMIT = 50;

        return {
            value: '',
            open: false,
            query: '',
            active: 0,
            options: [],
            init() {
                try {
                    this.options = JSON.parse(document.getElementById(source)?.textContent || '[]');
                } catch (e) {
                    this.options = [];
                }
                this.$watch('open', (open) => {
                    if (! open) return;
                    this.active = this.query === '' ? Math.max(0, this.matches.findIndex((o) => String(o.value) === String(this.value))) : 0;
                    this.$nextTick(() => { this.$refs.search.focus(); this.scrollToActive(); });
                });
            },
            get selected() {
                return this.options.find((o) => String(o.value) === String(this.value)) || null;
            },
            get matches() {
                const words = this.query.trim().toLowerCase().split(/\s+/).filter(Boolean);
                return words.length === 0 ? this.options : this.options.filter((o) => words.every((w) => o.search.includes(w)));
            },
            get filtered() {
                return this.matches.slice(0, LIMIT);
            },
            get more() {
                return this.matches.length > LIMIT;
            },
            toggle() {
                this.open ? this.close() : (this.open = true);
            },
            close() {
                this.open = false;
                this.query = '';
            },
            // Letters typed on the closed picker (or before the search box has focus) start the search.
            typeAhead(event) {
                if (event.key.length !== 1 || event.ctrlKey || event.metaKey || event.altKey) return;
                if (event.key === ' ' && this.query === '') return; // Space opens the list as a normal button press.
                event.preventDefault();
                this.query += event.key;
                this.open = true;
            },
            move(step) {
                const count = this.filtered.length;
                if (count === 0) return;
                this.active = (this.active + step + count) % count;
                this.scrollToActive();
            },
            scrollToActive() {
                this.$refs.list?.querySelectorAll('[role=option]')[this.active]?.scrollIntoView({ block: 'nearest' });
            },
            choose(option) {
                if (! option) return;
                this.value = String(option.value);
                this.close();
                this.$nextTick(() => this.$refs.button.focus());
            },
        };
    };

    // Livewire request failures:
    // - 401: account deactivated or kiln access removed while the page was open -> go to the login page.
    // - 419: session expired -> reload (which leads to the login page) instead of an English-only confirm box.
    // - 403: permission removed -> show a toast instead of an error page inside a dialog.
    document.addEventListener('livewire:init', () => {
        Livewire.interceptRequest(({ onError }) => {
            onError(({ response, preventDefault }) => {
                if (response.status === 401) {
                    preventDefault();
                    window.location.href = @js(route('login'));
                } else if (response.status === 419) {
                    preventDefault();
                    window.location.reload();
                } else if (response.status === 403) {
                    preventDefault();
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'error', message: @js(__('You do not have permission to do this.')) } }));
                }
            });
        });
    });
</script>
