// Нагрузочный сценарий витрины: главная, каталог, поиск, карточка.
//
// Запуск против изолированного preview (синтетические данные):
//   k6 run -e BASE_URL=http://127.0.0.1:8015 scripts/load/storefront.js
//
// Пороги — из бюджетов Core Web Vitals для серверной части: если TTFB
// проседает под нагрузкой, никакая оптимизация фронта LCP не спасёт.
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    stages: [
        { duration: '30s', target: 20 },   // разгон
        { duration: '2m', target: 50 },    // рабочая нагрузка
        { duration: '30s', target: 0 },    // спад
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<800'],
    },
};

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8015';

const QUERIES = ['camry', 'лобовое королла', '4521', 'land cruiser 200', 'молдинг'];

export default function () {
    let res = http.get(`${BASE}/`);
    check(res, { 'главная 200': (r) => r.status === 200 });

    sleep(1);

    res = http.get(`${BASE}/catalog`);
    check(res, { 'каталог 200': (r) => r.status === 200 });

    sleep(1);

    const q = QUERIES[Math.floor(Math.random() * QUERIES.length)];
    res = http.get(`${BASE}/search?q=${encodeURIComponent(q)}`);
    check(res, { 'поиск 200': (r) => r.status === 200 });

    sleep(2);
}
