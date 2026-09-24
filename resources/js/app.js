import './preview-ux';

/**
 * Toast-уведомления. Контейнер #toast-root лежит в layout.
 * Вызов: window.toast('Добавлено в корзину') или window.toast('Ошибка', 'error')
 */
(function () {
    const root = () => document.getElementById('toast-root');

    window.toast = function (message, type = 'ok', duration = 3500) {
        const container = root();
        if (!container || !message) return;

        const el = document.createElement('div');
        el.className = `toast toast-${type === 'error' ? 'error' : 'ok'}`;
        el.setAttribute('role', 'status');

        const icon = type === 'error'
            ? '<svg class="toast-icon h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18Z"/></svg>'
            : '<svg class="toast-icon h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>';

        el.innerHTML = `${icon}<span class="flex-1">${escapeHtml(message)}</span>
            <button type="button" class="shrink-0 text-faint hover:text-ink" aria-label="Закрыть">&times;</button>`;

        el.querySelector('button').addEventListener('click', () => dismiss(el));
        container.appendChild(el);

        setTimeout(() => dismiss(el), duration);
    };

    function dismiss(el) {
        if (!el.isConnected) return;
        el.classList.add('toast-out');
        el.addEventListener('animationend', () => el.remove(), { once: true });
    }
})();

/**
 * Показать flash-сообщения из data-атрибутов на #toast-root.
 */
document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('toast-root');
    if (!root) return;

    const success = root.dataset.flashSuccess;
    const error = root.dataset.flashError;

    if (success) window.toast(success, 'ok');
    if (error) window.toast(error, 'error');
});

/**
 * Защита от двойной отправки: на submit вешаем is-loading на кнопку,
 * которую нажали (в т.ч. кнопку вне формы через атрибут form=).
 */
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.noLoading !== undefined) return;
    if (form.hasAttribute('wire:submit') || form.closest('[wire\\:id]')) return;

    const btn = event.submitter instanceof HTMLButtonElement
        ? event.submitter
        : form.querySelector('[type="submit"], button:not([type])');
    if (!btn || btn.classList.contains('is-loading')) return;

    btn.classList.add('is-loading');
    btn.disabled = true;

    // Если валидация HTML5 не прошла — submit не сработает, но disabled уже стоит.
    // На всякий случай снимаем через 12 с (медленный 3G / долгий ответ).
    setTimeout(() => {
        btn.classList.remove('is-loading');
        btn.disabled = false;
    }, 12000);
});

/** Быстрый заказ / ошибка валидации — сразу к блоку оформления. */
(function () {
    const hash = window.location.hash;
    if (hash !== '#oformlenie') return;

    const target = document.getElementById('oformlenie');
    if (!target) return;

    requestAnimationFrame(() => {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        target.querySelector('input:not([type=hidden])')?.focus({ preventScroll: true });
    });
})();

/**
 * Reveal при скролле.
 */
(function () {
    const els = document.querySelectorAll('.reveal');
    if (!els.length) return;

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        els.forEach((el) => el.classList.add('is-visible'));
        return;
    }

    const io = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    io.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.12, rootMargin: '0px 0px -40px 0px' }
    );

    els.forEach((el) => io.observe(el));
})();

/**
 * Сворачиваемый мобильный поиск.
 */
document.querySelectorAll('[data-search-toggle]').forEach((btn) => {
    const panel = document.querySelector(btn.dataset.searchToggle);
    if (!panel) return;

    btn.addEventListener('click', () => {
        const open = panel.classList.toggle('hidden') === false;
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) panel.querySelector('input')?.focus();
    });
});

/**
 * Show/hide password toggle.
 */
document.querySelectorAll('[data-password-toggle]').forEach((btn) => {
    const input = document.querySelector(btn.dataset.passwordToggle);
    if (!input) return;

    btn.addEventListener('click', () => {
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        btn.setAttribute('aria-label', showing ? 'Показать пароль' : 'Скрыть пароль');
        btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
    });
});

/**
 * Степпер количества в корзине.
 */
document.querySelectorAll('[data-qty-stepper]').forEach((stepper) => {
    const input = stepper.querySelector('input[type="number"], input[name="qty"]');
    const form = stepper.closest('form');
    if (!input) return;

    stepper.querySelectorAll('[data-qty]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const delta = Number(btn.dataset.qty);
            const next = Math.max(1, (Number(input.value) || 1) + delta);
            input.value = next;
            if (form && form.dataset.autoSubmit !== undefined) {
                form.requestSubmit();
            }
        });
    });
});

/**
 * Зависимый выбор «марка -> модель» на главной.
 * Список моделей грузим по требованию: у Toyota их три сотни, а марок под сотню —
 * отдавать всё дерево на каждой загрузке страницы незачем.
 */
document.querySelectorAll('[data-car-picker]').forEach((picker) => {
    const brand = picker.querySelector('[data-picker-brand]');
    const model = picker.querySelector('[data-picker-model]');
    const selectedModel = picker.dataset.selectedModel || '';

    if (!brand || !model) return;

    const reset = (label, disabled = true) => {
        model.innerHTML = `<option value="">${label}</option>`;
        model.disabled = disabled;
    };

    const loadModels = async () => {
        const slug = brand.selectedOptions[0]?.dataset.slug;
        const base = (picker.dataset.modelsBase || '/api/brands').replace(/\/$/, '');

        if (!slug) {
            reset('Сначала выберите марку');
            return;
        }

        reset('Загружаем...');

        try {
            const response = await fetch(`${base}/${slug}/models`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) throw new Error(response.statusText);

            const models = await response.json();

            model.innerHTML =
                '<option value="">Все модели марки</option>' +
                models
                    .map((m) => `<option value="${m.id}">${escapeHtml(m.name)}</option>`)
                    .join('');
            model.disabled = false;

            if (selectedModel && [...model.options].some((option) => option.value === selectedModel)) {
                model.value = selectedModel;
            }
        } catch {
            // Не блокируем покупателя: без модели форма ведёт на страницу марки,
            // где он выберет её руками.
            reset('Продолжить без модели', false);
        }
    };

    brand.addEventListener('change', loadModels);

    if (brand.value) {
        loadModels();
    }
});

/**
 * Живой фильтр длинных списков (модели марки).
 * Список уже отрисован на сервере — фильтруем на месте, без запросов.
 */
document.querySelectorAll('[data-filter-input]').forEach((input) => {
    const list = document.querySelector(input.dataset.filterTarget);
    const empty = document.querySelector('[data-filter-empty]');

    if (!list) return;

    const items = [...list.querySelectorAll('[data-filter-item]')];

    input.addEventListener('input', () => {
        const query = input.value.trim().toLowerCase();
        let visible = 0;

        items.forEach((item) => {
            const match = !query || item.dataset.filterItem.includes(query);
            item.hidden = !match;
            if (match) visible++;
        });

        if (empty) empty.hidden = visible > 0;
    });
});

/**
 * Адрес — для доставки и выезда, дата/время — для установки в сервисе и с выездом.
 * Без JS форма тоже валидна: сервер сам требует нужные поля.
 */
document.querySelectorAll('[data-order-form]').forEach((form) => {
    const group = form.querySelector('[data-fulfillment]');
    const address = form.querySelector('[data-address-field]');
    const installation = form.querySelector('[data-installation-field]');

    if (!group || (!address && !installation)) return;

    const sync = (radio) => {
        const needsAddress = radio?.dataset.needsAddress === '1';
        const needsSlot = radio?.dataset.needsSlot === '1';

        address?.classList.toggle('hidden', !needsAddress);
        installation?.classList.toggle('hidden', !needsSlot);
    };

    group.addEventListener('change', (event) => {
        if (event.target.name !== 'fulfillment') return;

        sync(event.target);
        applyCartQuote(event.target.value);

        if (event.target.dataset.needsAddress === '1') {
            address?.querySelector('input')?.focus();
        }
    });

    sync(group.querySelector('input[name="fulfillment"]:checked'));
    applyCartQuote(group.querySelector('input[name="fulfillment"]:checked')?.value);
});

function applyCartQuote(fulfillment) {
    const root = document.querySelector('[data-cart-quotes]');
    if (!root || !fulfillment) return;

    let quotes = {};
    try {
        quotes = JSON.parse(root.getAttribute('data-cart-quotes') || '{}');
    } catch {
        return;
    }

    const quote = quotes[fulfillment];
    if (!quote) return;

    const format = (value) =>
        `${Math.round(Number(value) || 0).toLocaleString('ru-RU').replace(/\u00a0/g, ' ')} ₸`;

    document.querySelectorAll('[data-cart-summary]').forEach((el) => {
        const glass = el.querySelector('[data-quote-glass]');
        const works = el.querySelector('[data-quote-works]');
        const visit = el.querySelector('[data-quote-visit]');
        const total = el.querySelector('[data-quote-total]');
        const worksRow = el.querySelector('[data-quote-works-row]');
        const visitRow = el.querySelector('[data-quote-visit-row]');

        if (glass) glass.textContent = format(quote.glass);
        if (works) works.textContent = format(quote.works);
        if (visit) visit.textContent = format(quote.visit);
        if (total) total.textContent = format(quote.total);
        worksRow?.classList.toggle('hidden', !(quote.works > 0));
        visitRow?.classList.toggle('hidden', !(quote.visit > 0));
    });
}

document.querySelector('[data-offer-list]')?.addEventListener('change', (event) => {
    if (event.target.name !== 'picked_offer') return;

    document.querySelectorAll('[data-selected-offer]').forEach((input) => {
        input.value = event.target.value;
    });

    const price = document.querySelector('[data-product-price]');
    if (price && event.target.dataset.priceLabel) {
        price.textContent = event.target.dataset.priceLabel;
    }
});

document.addEventListener('livewire:init', () => {
    const scrollChat = () => {
        document.querySelectorAll('[data-ai-chat-scroll]').forEach((el) => {
            el.scrollTop = el.scrollHeight;
        });
    };

    document.addEventListener('livewire:morph', scrollChat);
    queueMicrotask(scrollChat);
});

function escapeHtml(value) {
    return String(value).replace(
        /[&<>"']/g,
        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
    );
}
