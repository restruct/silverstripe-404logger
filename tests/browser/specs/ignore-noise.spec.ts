import { test, expect, openReport, rowsWhere, token } from './support';

// 3.1: scanner and device-probe 404s, and noise search queries, are dropped before they reach the
// database (FourOhFourLog.ignore_categories, SearchLog.ignore_noise), so they never show in the
// reports; an ordinary missing page from the same referrer still does (the control).

test('scanner and probe 404s are not listed; a missing page from the same referrer is', async ({ page, visitor }) => {
    const t = token();
    const referrer = `https://external.example/ignore/${t}`;

    // A .php probe and a device probe, as they reach PHP behind typical web-server rules.
    for (const path of [`/lgb-${t}/wp-login.php`, `/.well-known/passkey-endpoints?lgb=${t}`]) {
        expect((await visitor.get(path, { headers: { Referer: referrer } })).status(), path).toBe(404);
    }
    // The control: logged as before.
    const missing = `lgb-page-${t}`;
    expect((await visitor.get(`/${missing}`, { headers: { Referer: referrer } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    const rows = rowsWhere(grid, 'Referrer', referrer);
    await expect(rows).toHaveCount(1);
    await expect(rows.locator('td.col-Link')).toHaveText(missing);
});

test('a noise search query is not listed; a real one is', async ({ page, visitor }) => {
    const t = token();
    // A SQL-injection probe's first step: a value with a stray quote.
    const noise = `65'${t}`;
    const real = `LgB Real ${t}`;

    expect((await visitor.get('/', { params: { Search: noise } })).status()).toBe(200);
    expect((await visitor.get('/', { params: { Search: real } })).status()).toBe(200);

    const grid = await openReport(page, 'search');
    await expect(rowsWhere(grid, 'Query', real.toLowerCase())).toHaveCount(1);
    await expect(rowsWhere(grid, 'Query', noise.toLowerCase())).toHaveCount(0);
});
