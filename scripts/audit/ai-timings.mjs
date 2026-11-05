import { readFileSync, writeFileSync } from 'node:fs';
const root = 'output/playwright/audit-2026-10-09';
const budget = JSON.parse(readFileSync(`${root}/ai-budget.json`, 'utf8'));
const candidates = readFileSync('storage/logs/laravel.log', 'utf8').split('\n').flatMap(line => {
    const match = line.match(/^\[([^\]]+)\].*?ai.provider (\{.*\})/);
    if (!match) return [];
    try { return [{ endSecond: Date.parse(match[1].replace(' ', 'T') + '+05:00') / 1000, ...JSON.parse(match[2]) }]; } catch { return []; }
});
const rows = [], unmatched = [];
for (const request of budget.requests) {
    const model = request.model_path.split('/').at(-1).split(':')[0];
    // Provider logger rounds its finish timestamp to seconds. Match the physical start
    // with logged elapsed duration and model, never with a model-name-only time window.
    const match = candidates.map((row, index) => ({ row, index, distance: Math.abs(row.endSecond + .5 - row.duration_ms / 1000 - request.at) }))
        .filter(x => x.row.model === model && x.distance < .8 && !x.row.used).sort((a, b) => a.distance - b.distance)[0];
    if (!match) { unmatched.push({ at: request.at, model }); continue; }
    candidates[match.index].used = true;
    rows.push({ model, result: match.row.result, duration_ms: match.row.duration_ms, matchingErrorSeconds: match.distance });
}
const values = rows.map(x => x.duration_ms).sort((a, b) => a - b);
const result = { method: 'Physical request starts matched to provider log finish/elapsed/model; log timestamp resolution one second', physicalRequests: budget.calls,
    matched: rows.length, unmatched, p50: values[Math.ceil(values.length * .5) - 1], p95: values[Math.ceil(values.length * .95) - 1], rows };
writeFileSync(`${root}/ai-provider-timings.json`, JSON.stringify(result, null, 2));
console.log(JSON.stringify({ matched: result.matched, unmatched: unmatched.length, p50: result.p50, p95: result.p95 }));
