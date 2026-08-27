/**
 * Зависимый выбор «марка -> модель» на главной.
 * Список моделей грузим по требованию: у Toyota их три сотни, а марок под сотню —
 * отдавать всё дерево на каждой загрузке страницы незачем.
 */
document.querySelectorAll('[data-car-picker]').forEach((picker) => {
    const brand = picker.querySelector('[data-picker-brand]');
    const model = picker.querySelector('[data-picker-model]');

    if (!brand || !model) return;

    const reset = (label, disabled = true) => {
        model.innerHTML = `<option value="">${label}</option>`;
        model.disabled = disabled;
    };

    brand.addEventListener('change', async () => {
        const slug = brand.selectedOptions[0]?.dataset.slug;

        if (!slug) {
            reset('Сначала выберите марку');
            return;
        }

        reset('Загружаем...');

        try {
            const response = await fetch(`/api/brands/${slug}/models`, {
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
        } catch {
            // Не блокируем покупателя: без модели форма ведёт на страницу марки,
            // где он выберет её руками.
            reset('Продолжить без модели', false);
        }
    });
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
 * Адрес нужен только курьеру, поэтому поле показывается лишь при выборе доставки.
 * Форма работает и без этого скрипта: сервер сам требует адрес для доставки.
 */
document.querySelectorAll('[data-order-form]').forEach((form) => {
    const group = form.querySelector('[data-fulfillment]');
    const address = form.querySelector('[data-address-field]');

    if (!group || !address) return;

    group.addEventListener('change', (event) => {
        const isDelivery = event.target.value === 'delivery';

        address.classList.toggle('hidden', !isDelivery);

        if (isDelivery) address.querySelector('input')?.focus();
    });
});

function escapeHtml(value) {
    return String(value).replace(
        /[&<>"']/g,
        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]
    );
}
