import { test, expect, openReport, rowsWhere, token } from './support';

// 3.1: scanner and device-probe 404s, and noise search queries, are dropped before they reach the
// database (FourOhFourLog.ignore_categories, SearchLog.ignore_noise), so they never show in the
// reports; an ordinary missing page still does (the control). A scanner-looking URL WITH a referrer
// is a real inbound link to an old URL and is logged (FourOhFourLog.ignore_only_without_referrer).

test('scanner hits without a referrer and probes are not listed; a missing page is', async ({ page, visitor }) => {
    const t = token();
    const referrer = `https://external.example/ignore/${t}`;

    // A .php scanner probe as scanners send it: no referrer.
    const scanner = `lgb-${t}/wp-login.php`;
    expect((await visitor.get(`/${scanner}`)).status()).toBe(404);
    // A device probe, dropped with or without a referrer.
    const probe = `.well-known/passkey-endpoints?lgb=${t}`;
    expect((await visitor.get(`/${probe}`, { headers: { Referer: referrer } })).status()).toBe(404);
    // The control: logged as before.
    const missing = `lgb-page-${t}`;
    expect((await visitor.get(`/${missing}`, { headers: { Referer: referrer } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    const rows = rowsWhere(grid, 'Referrer', referrer);
    await expect(rows).toHaveCount(1);
    await expect(rows.locator('td.col-Link')).toHaveText(missing);
    await expect(rowsWhere(grid, 'Link', scanner)).toHaveCount(0);
});

test('a scanner-looking URL linked from another site is listed', async ({ page, visitor }) => {
    const t = token();
    const referrer = `https://external.example/old-links/${t}`;
    const oldUrl = `lgb-old-${t}/contact.php`;

    expect((await visitor.get(`/${oldUrl}`, { headers: { Referer: referrer } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    const rows = rowsWhere(grid, 'Referrer', referrer);
    await expect(rows).toHaveCount(1);
    await expect(rows.locator('td.col-Link')).toHaveText(oldUrl);
});

test('a noise search query is not listed; a real one is', async ({ page, visitor }) => {
    const t = token();
    // A SQL-injection probe's first step: a quote followed by a digit.
    const noise = `65'1${t}`;
    const real = `LgB Real ${t}`;

    expect((await visitor.get('/', { params: { Search: noise } })).status()).toBe(200);
    expect((await visitor.get('/', { params: { Search: real } })).status()).toBe(200);

    const grid = await openReport(page, 'search');
    await expect(rowsWhere(grid, 'Query', real.toLowerCase())).toHaveCount(1);
    await expect(rowsWhere(grid, 'Query', noise.toLowerCase())).toHaveCount(0);
});
