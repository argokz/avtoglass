// Preview layout behavior. Business commands and totals stay in Livewire.
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.purchase-prototype');
    if (!root) return;
    let submission = 0;
    let pendingSubmission = null;
    let bar = null;
    let frame = null;
    const mobile = window.matchMedia('(max-width: 1023px)');
    const visible = element => element && element.getClientRects().length > 0;
    const updateBar = () => {
        frame = null;
        const next = root.querySelector('[data-purchase-action]');
        if (bar !== next) {
            if (bar) sizeObserver.unobserve(bar);
            bar = next;
            if (bar) sizeObserver.observe(bar);
        }
        const editing = document.activeElement?.matches('input:not([type=checkbox]):not([type=radio]),textarea');
        const keyboard = mobile.matches && editing && window.visualViewport && innerHeight - visualViewport.height > 120;
        if (bar) bar.toggleAttribute('data-keyboard-hidden', Boolean(keyboard));
        const height = bar && mobile.matches && !keyboard ? Math.ceil(bar.getBoundingClientRect().height) : 0;
        document.documentElement.style.setProperty('--purchase-action-space', `${height}px`);
    };
    const schedule = () => { if (frame === null) frame = requestAnimationFrame(updateBar); };
    const sizeObserver = new ResizeObserver(schedule);
    new MutationObserver(schedule).observe(root, {childList: true, subtree: true});
    window.addEventListener('resize', schedule);
    window.visualViewport?.addEventListener('resize', schedule);
    root.addEventListener('focusin', schedule);
    root.addEventListener('focusout', schedule);
    root.addEventListener('submit', () => { pendingSubmission = ++submission; }, true);
    const install = () => window.Livewire.hook('commit', ({component, commit, succeed, fail}) => {
        if (component.el !== root || pendingSubmission === null || !commit.calls.some(call => !call.method.startsWith('$'))) return;
        const attempt = pendingSubmission;
        const clear = () => { if (attempt === pendingSubmission) pendingSubmission = null; };
        fail(clear);
        succeed(({effects}) => {
            clear();
            if (!effects.dispatches?.some(event => event.name === 'preview-validation-failed')) return;
            requestAnimationFrame(() => {
                const summary = root.querySelector('[data-validation-summary]');
                if (visible(summary)) {
                    summary.focus();
                    summary.scrollIntoView({block: 'center', behavior: 'instant'});
                }
            });
        });
    });
    if (window.Livewire) install();
    else document.addEventListener('livewire:init', install, {once: true});
    root.addEventListener('click', event => {
        if (!event.target.closest('[data-focus-error]')) return;
        const target = [...root.querySelectorAll('[aria-invalid="true"], [data-checkout-error], [role="alert"]:not([data-validation-summary])')].find(visible);
        if (target) {
            if (!target.matches('input,select,textarea,button,a[href],[tabindex]')) target.tabIndex = -1;
            target.focus();
            target.scrollIntoView({block: 'center', behavior: 'instant'});
        }
    });
    schedule();
});
