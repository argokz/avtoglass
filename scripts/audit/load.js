import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const base = __ENV.BASE_URL || 'http://127.0.0.1:18016';
if (!/^http:\/\/127\.0\.0\.1:18016$/.test(base)) throw new Error('Dedicated local nginx preview required');
const requests = new Trend('page_duration', true);
const credentials = JSON.parse(open('../../.codex/modernization/preview-access.json'));
export const options = {
    scenarios: { storefront: { executor: 'constant-vus', vus: Number(__ENV.VUS || 1), duration: '60s', gracefulStop: '30s' } },
    thresholds: { http_req_failed: ['rate<0.01'], page_duration: ['p(95)<800'], checks: ['rate>0.99'],
        'page_duration{page:home}': ['p(95)<800'], 'page_duration{page:catalog}': ['p(95)<800'],
        'page_duration{page:search}': ['p(95)<800'], 'page_duration{page:product}': ['p(95)<800'],
        'page_duration{page:wholesale}': ['p(95)<800'] },
    summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'max'],
};
export function setup() {
    const response = http.get(`${base}/preview/health`);
    if (response.status !== 200 || response.json().preview !== 'avtoglass-isolated-v1') throw new Error('Preview identity failed');
    const login = http.get(`${base}/vhod`);
    const csrf = /name="_token"[^>]*value="([^"]+)"/.exec(login.body)?.[1];
    const auth = http.post(`${base}/vhod`, { _token: csrf, phone: credentials.wholesale_phone, password: credentials.password });
    if (auth.status !== 200 || !auth.url.includes('/kabinet')) throw new Error('Synthetic wholesale authentication failed');
    return { cookies: http.cookieJar().cookiesForURL(base) };
}
export default function (data) {
    for (const [name, values] of Object.entries(data.cookies)) for (const value of values) http.cookieJar().set(base, name, value);
    for (const [page, path] of [['home', '/'], ['catalog', '/catalog'], ['search', '/search?q=Camry'], ['product', '/tovar/preview-camry-70'], ['wholesale', '/opt']]) {
        const response = http.get(`${base}${path}`, { tags: { page } });
        requests.add(response.timings.duration, { page });
        check(response, { 'page is HTTP 200': r => r.status === 200 }, { page });
        sleep(0.2);
    }
    sleep(1);
}
