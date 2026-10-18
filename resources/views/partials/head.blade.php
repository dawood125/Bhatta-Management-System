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
