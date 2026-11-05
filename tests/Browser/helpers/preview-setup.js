import { request } from '@playwright/test';

export default async function setup(config) {
    const baseURL = config.projects[0].use.baseURL;
    const url = new URL(baseURL);
    if (url.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(url.hostname)
        || !['8015', '18015'].includes(url.port)) {
        throw new Error('Browser tests require the isolated local preview on port 8015 or 18015.');
    }
    const api = await request.newContext({ baseURL });
    try {
        const response = await api.get('/preview/health', { timeout: 15000, maxRedirects: 0 });
        if (!response.ok() || (await response.json()).preview !== 'avtoglass-isolated-v1') {
            throw new Error('The target is not the isolated AvtoGlass preview.');
        }
    } finally {
        await api.dispose();
    }
}
