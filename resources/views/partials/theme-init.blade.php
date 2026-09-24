<script>
(() => {
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const key = 'avtoglass.theme';
    const valid = value => ['light', 'dark', 'system'].includes(value) ? value : 'system';
    let preference = 'system';
    try { preference = valid(localStorage.getItem(key)); } catch {}
    const sync = () => document.querySelectorAll('[data-theme-select]').forEach(select => {
        select.value = preference;
        select.disabled = false;
    });
    const apply = () => {
        root.dataset.theme = preference === 'system' ? (media.matches ? 'dark' : 'light') : preference;
        root.dataset.themePreference = preference;
        document.querySelectorAll('meta[name="theme-color"]').forEach(meta => {
            meta.removeAttribute('media');
            meta.content = root.dataset.theme === 'dark' ? '#1a1d24' : '#f5f6f8';
        });
        sync();
        window.dispatchEvent(new CustomEvent('theme-changed', {detail: {preference}}));
    };
    window.AvtoGlassTheme = {
        get preference() { return preference; },
        set(value) {
            preference = valid(value);
            try { localStorage.setItem(key, preference); } catch {}
            apply();
        },
    };
    media.addEventListener('change', () => { if (preference === 'system') apply(); });
    window.addEventListener('storage', event => {
        if (event.key === key || event.key === null) {
            preference = valid(event.newValue);
            apply();
        }
    });
    document.addEventListener('change', event => {
        if (event.target instanceof HTMLSelectElement && event.target.matches('[data-theme-select]')) {
            window.AvtoGlassTheme.set(event.target.value);
        }
    });
    document.addEventListener('DOMContentLoaded', () => {
        sync();
        new MutationObserver(sync).observe(document.body, {childList: true, subtree: true});
    });
    apply();
})();
</script>
