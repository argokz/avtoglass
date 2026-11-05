import { readFileSync, writeFileSync } from 'node:fs';

const root = 'output/playwright/audit-2026-10-09';
const read = file => JSON.parse(readFileSync(`${root}/${file}`, 'utf8').replace(/^\uFEFF/, ''));
const percentile = (values, p) => {
    const ordered = values.filter(Number.isFinite).sort((a, b) => a - b);
    return ordered[Math.max(0, Math.ceil(ordered.length * p) - 1)];
};
const interactions = read('interactions.json');
let markdown = readFileSync(`${root}/summary.md`, 'utf8');
markdown += '\n## Методика и ограничения\n\n';
markdown += 'Навигации: 160 успешных загрузок, восемь экранов × desktop/mobile × cold/warm × пять повторов. Desktop без ограничения CPU/сети; mobile CPU ×4, RTT 150 мс, 200000 байт/с (1,6 Мбит/с). Lighthouse: 80 cold-прогонов, отдельные согласованные simulated desktop/mobile профили. Эти методики дают разные оценки LCP и не усредняются между собой. Проверялся малый синтетический каталог: 3 товара / 5 предложений / 1 модель. Lighthouse корзины и оформления измеряет один документ /korzina.\n\n';
markdown += 'Нагрузка выполнялась позже, на отдельном PostgreSQL 5441 с синтетическими 6030 товарами / 6643 предложениями / 1414 моделями, nginx 18016 и одним активным FastCGI. Добавленные предложения одного поставщика и модели одного бренда не воспроизводят рабочую селективность. Статика не входит в сценарий. Каждый уровень: 60 с + gracefulStop 30 с; таблица p95 использует page_duration (HTTP duration пяти целевых страниц, без setup). Доля HTTP-ошибок включает setup. Это локальная диагностика сопоставимого количества CGI, а не производственный SLO.\n\n';
const load50 = read('load-50.json');
markdown += `Повтор 50 VU: ${load50.metrics.http_reqs.count} завершённых HTTP-ответов с 0% HTTP-ошибок, **0 полных / 50 прерванных итераций**. Дошли только до главной, каталога и поиска. Значения 0 для товара и /opt означают отсутствие выборки, а не мгновенный ответ или выполненный бюджет. p95 завершённых ответов не включает незавершённый хвост и не описывает полный пятистраничный путь. Первый запуск с завершившимся CGI архивирован отдельно: load-50-worker-exit.*, 53,15% HTTP-ошибок. После прерывания 50 VU исходный legacy-сценарий сначала захватил остаточную очередь; этот замер сохранён как load-legacy-after-50.*, окончательный повтор выполнен после разгрузки.\n\n`;
markdown += '| VU | Главная p95, мс | Каталог p95, мс | Поиск p95, мс | Товар p95, мс | /opt p95, мс |\n|---|---|---|---|---|---|\n';
for (const vus of [1, 10, 20, 50]) {
    const metrics = read(`load-${vus}.json`).metrics;
    markdown += `| ${vus} | ${['home', 'catalog', 'search', 'product', 'wholesale'].map(page => {
        const value = metrics[`page_duration{page:${page}}`]?.['p(95)'];
        return Number.isFinite(value) && value > 0 ? value.toFixed(1) : 'нет выборки';
    }).join(' | ')} |\n`;
}
const legacy = read('load-legacy.json').metrics;
markdown += `\nИсходный scripts/load/storefront.js: дополнительный ограниченный 60-секундный прогон с 1 VU, ${legacy.http_reqs.count} HTTP-ответов, p95 ${legacy.http_req_duration['p(95)'].toFixed(1)} мс, ошибки ${((legacy.http_req_failed.value ?? legacy.http_req_failed.rate) * 100).toFixed(2)}%. Его три маршрута и think time отличаются от расширенного сценария; результаты не объединяются.\n`;
markdown += '\n## Отзывчивость\n\nChromium 390×844, CPU ×4 / RTT 150 мс / 1,6 Мбит/с. actionMs включает ожидание серверного обновления там, где оно есть; Event Timing измеряет задержку события до следующей отрисовки и не называется полевым INP.\n\n| Действие | n | actionMs медиана / p95, мс | Event Timing max, мс |\n|---|---|---|---|\n';
for (const name of [...new Set(interactions.events.map(event => event.name))]) {
    const rows = interactions.events.filter(event => event.name === name);
    const durations = rows.flatMap(event => event.eventTimings || []).map(event => event.duration);
    markdown += `| ${name} | ${rows.length} | ${percentile(rows.map(row => row.actionMs), .5).toFixed(1)} / ${percentile(rows.map(row => row.actionMs), .95).toFixed(1)} | ${durations.length ? Math.max(...durations) : 'нет записи'} |\n`;
}
markdown += `\nВсего ${interactions.events.length} действий, ${interactions.errors.length} ошибок финального измерителя. Пробные ошибки локаторов сохранены отдельно. UI-проверка чата на 5441 измеряет отправку и отмену без worker; живое ожидание очереди/генерации измерено отдельно в [ИИ-оценке](AI-EVALUATION.md).\n\n`;
markdown += 'Короткое окно 390×450: телефон и submit действительно сфокусированы, но центр каждого закрывает HEADER. Открытие контактов мышью тоже блокируется шапкой; для дальнейшего измерения блок открыт клавиатурой. На 1365×900 при CSS root font-size 200% горизонтального переполнения нет; это увеличение текста, а не native browser zoom. Реальная мобильная клавиатура не проверена.\n\n';
markdown += 'Файлы с разбросом и исходными измерениями: summary.json, browser-merged.json, lighthouse/*.json и *.html, interactions.json, short-viewport.json, load-{1,10,20,50}.json/log. Они находятся в локальном output/playwright/audit-2026-10-09/. Полевого массива и CrUX нет: пользовательские p75 LCP/CLS/INP не подтверждены.\n';
writeFileSync('docs/audit-2026-10-09/MEASUREMENTS.md', markdown);
console.log(JSON.stringify({ interactions: interactions.events.length, errors: interactions.errors.length, load50Requests: load50.metrics.http_reqs.count }));
