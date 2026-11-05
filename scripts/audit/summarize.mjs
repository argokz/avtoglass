import { readFileSync, writeFileSync, existsSync } from 'node:fs';
const root = 'output/playwright/audit-2026-10-09';
const read = name => JSON.parse(readFileSync(`${root}/${name}`, 'utf8').replace(/^\uFEFF/, ''));
const percentile = (values, p) => { const list = values.filter(Number.isFinite).sort((a, b) => a - b); return list.length ? list[Math.max(0, Math.ceil(list.length * p) - 1)] : null; };
const stats = values => ({ median: percentile(values, .5), p75: percentile(values, .75), p95: percentile(values, .95), min: Math.min(...values.filter(Number.isFinite)), max: Math.max(...values.filter(Number.isFinite)) });
const browser = read('browser-results.json');
if (existsSync(`${root}/browser-checkout.json`)) {
    browser.measurements = [...browser.measurements.filter(x => x.name !== 'checkout'), ...read('browser-checkout.json').measurements];
}
writeFileSync(`${root}/browser-merged.json`, JSON.stringify(browser, null, 2));
const result = { generatedAt: new Date().toISOString(), navigation: [], lighthouse: [], load: [], regression: null };
for (const profile of ['desktop', 'mobile']) for (const name of [...new Set(browser.measurements.map(x => x.name))]) for (const cache of ['cold', 'warm']) {
    const rows = browser.measurements.filter(x => x.profile === profile && x.name === name && x.cache === cache);
    result.navigation.push({ profile, name, cache, count: rows.length, errors: rows.filter(x => x.error).length,
        ...Object.fromEntries(['lcp', 'cls', 'ttfb', 'load', 'transferredBytes'].map(key => [key, stats(rows.map(x => x[key]))])) });
}
if (existsSync(`${root}/lighthouse/summary.json`)) {
    const lh = read('lighthouse/summary.json');
    for (const profile of ['desktop', 'mobile']) for (const name of [...new Set(lh.map(x => x.name))]) {
        const rows = lh.filter(x => x.profile === profile && x.name === name);
        result.lighthouse.push({ profile, name, count: rows.length, errors: rows.filter(x => x.error || x.runtimeError).length,
            ...Object.fromEntries(['performance', 'accessibility', 'lcp', 'cls', 'tbt', 'ttfb', 'bytes'].map(key => [key, stats(rows.map(x => x[key]))])) });
    }
}
for (const vus of [1, 10, 20, 50]) if (existsSync(`${root}/load-${vus}.json`)) {
    const data = read(`load-${vus}.json`);
    result.load.push({ vus, p95: data.metrics.page_duration?.['p(95)'], average: data.metrics.page_duration?.avg,
        errors: data.metrics.http_req_failed?.value ?? data.metrics.http_req_failed?.rate, requests: data.metrics.http_reqs?.count, metrics: data.metrics });
}
if (existsSync(`${root}/browser-regression.json`) && readFileSync(`${root}/browser-regression.json`, 'utf8').trim()) {
    const data = read('browser-regression.json'); const specs = [];
    const collect = suite => { for (const spec of suite.specs || []) for (const test of spec.tests) specs.push({ title: spec.title, project: test.projectName, status: test.status,
        results: test.results.map(x => ({ status: x.status, error: x.error?.message, attachments: x.attachments?.map(a => ({ name: a.name, path: a.path })) })) }); for (const child of suite.suites || []) collect(child); };
    data.suites.forEach(collect); result.regression = { stats: data.stats, specs };
}
writeFileSync(`${root}/summary.json`, JSON.stringify(result, null, 2));
const ms = value => value === null || !Number.isFinite(value) ? '—' : (value / 1000).toFixed(3);
let markdown = '# Лабораторные результаты 9 октября 2026\n\nПять навигаций на группу; p75 здесь лабораторный, а не полевая оценка CWV. Холодный кеш означает очистку HTTP-кеша браузера, без очистки серверного кеша.\n\n| Профиль | Экран | Кеш | n / ошибки | LCP медиана / диапазон, с | TTFB медиана, с | CLS max | Передано медиана, KiB |\n|---|---|---|---|---|---|---|---|\n';
for (const r of result.navigation) markdown += `| ${r.profile} | ${r.name} | ${r.cache} | ${r.count}/${r.errors} | ${ms(r.lcp.median)} / ${ms(r.lcp.min)}–${ms(r.lcp.max)} | ${ms(r.ttfb.median)} | ${r.cls.max} | ${Math.round(r.transferredBytes.median / 1024)} |\n`;
markdown += '\n| Lighthouse | Экран | n / ошибки | Performance медиана | LCP медиана / диапазон, с | TBT медиана, мс | CLS max | Accessibility медиана |\n|---|---|---|---|---|---|---|---|\n';
for (const r of result.lighthouse) markdown += `| ${r.profile} | ${r.name} | ${r.count}/${r.errors} | ${r.performance.median} | ${ms(r.lcp.median)} / ${ms(r.lcp.min)}–${ms(r.lcp.max)} | ${r.tbt.median} | ${r.cls.max} | ${r.accessibility.median} |\n`;
markdown += '\n| VU | HTTP p95, мс | HTTP ошибки | Запросы |\n|---|---|---|---|\n';
for (const r of result.load) markdown += `| ${r.vus} | ${r.p95?.toFixed(1)} | ${((r.errors ?? 0) * 100).toFixed(2)}% | ${r.requests} |\n`;
writeFileSync(`${root}/summary.md`, markdown);
console.log(JSON.stringify({ navigationGroups: result.navigation.length, lighthouseGroups: result.lighthouse.length, loadRuns: result.load.length, regression: result.regression?.stats }));
