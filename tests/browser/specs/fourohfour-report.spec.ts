import { test, expect, openReport, reloadReport, rowsWhere, token } from './support';

// FourOhFourLogger: a front-end request that ends in a 404 is logged with its external referrer
// and counted, and the "(External) broken links report" lists it.

test('a 404 with an external referrer is logged, counted and listed in the report', async ({ page, visitor }) => {
    const t = token();
    const link = `lgb-missing-${t}?from=spec`;
    const referrer = `https://external.example/links/${t}`;

    const first = await visitor.get(`/${link}`, { headers: { Referer: referrer } });
    expect(first.status(), 'the page does not exist').toBe(404);

    let grid = await openReport(page, 'fourOhFour');
    // The link is stored relative to the site root, query string included.
    let row = rowsWhere(grid, 'Link', link);
    await expect(row).toHaveCount(1);
    await expect(row.locator('td.col-Referrer')).toHaveText(referrer);
    await expect(row.locator('td.col-Count')).toHaveText('1');
    // Most recent hit is filled in (Datetime->Full).
    await expect(row.locator('td.col-LastEdited')).not.toBeEmpty();

    // The same link from the same referrer again: the same row, counted up.
    expect((await visitor.get(`/${link}`, { headers: { Referer: referrer } })).status()).toBe(404);
    grid = await reloadReport(page, 'fourOhFour');
    row = rowsWhere(grid, 'Link', link);
    await expect(row).toHaveCount(1);
    await expect(row.locator('td.col-Count')).toHaveText('2');

    // From another referrer: a row of its own.
    const other = `https://other.example/${t}`;
    expect((await visitor.get(`/${link}`, { headers: { Referer: other } })).status()).toBe(404);
    grid = await reloadReport(page, 'fourOhFour');
    await expect(rowsWhere(grid, 'Link', link)).toHaveCount(2);
    await expect(rowsWhere(grid, 'Referrer', other).locator('td.col-Count')).toHaveText('1');
});

test('a 404 without a referrer is logged as "unknown"; one from the site itself is not logged', async ({ page, visitor, baseURL }) => {
    const t = token();
    const noRef = `lgb-noref-${t}`;
    const internal = `lgb-internal-${t}`;

    expect((await visitor.get(`/${noRef}`)).status()).toBe(404);
    // An internal broken link (referrer on the host the request was made on) belongs in a link
    // checker, not in this report.
    expect((await visitor.get(`/${internal}`, { headers: { Referer: `${baseURL}/some-page` } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    await expect(rowsWhere(grid, 'Link', noRef).locator('td.col-Referrer')).toHaveText('unknown');
    await expect(rowsWhere(grid, 'Link', internal)).toHaveCount(0);
});

test('an existing page is not logged', async ({ page, visitor }) => {
    const t = token();
    // The home page answers 200; the query string makes the link unique to this run.
    const res = await visitor.get(`/?lgb=${t}`, { headers: { Referer: `https://external.example/${t}` } });
    expect(res.status()).toBe(200);

    const grid = await openReport(page, 'fourOhFour');
    await expect(rowsWhere(grid, 'Referrer', `https://external.example/${t}`)).toHaveCount(0);
});

test('a long URL is shortened in the report, with the full URL on hover', async ({ page, visitor }) => {
    const t = token();
    // Over link_display_length (120): 200+ characters.
    const link = `lgb-long-${t}-${'x'.repeat(200)}`;
    expect((await visitor.get(`/${link}`, { headers: { Referer: `https://external.example/${t}` } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    const row = rowsWhere(grid, 'Referrer', `https://external.example/${t}`);
    await expect(row).toHaveCount(1);
    const cell = row.locator('td.col-Link span[title]');
    await expect(cell).toHaveAttribute('title', link);
    const shown = ((await cell.textContent()) ?? '').trim();
    expect(shown.length, 'shortened to about 120 characters').toBeLessThanOrEqual(121);
    expect(shown.length).toBeGreaterThan(100);
    expect(link.startsWith(shown.replace(/(\.\.\.|…)$/, '')), 'the shown text is the start of the URL').toBe(true);
    expect(shown, 'an ellipsis marks the cut').toMatch(/(\.\.\.|…)$/);
});
