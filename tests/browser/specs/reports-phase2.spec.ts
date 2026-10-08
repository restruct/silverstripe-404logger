import { readFile } from 'node:fs/promises';
import type { Locator, Page } from '@playwright/test';
import { test, expect, openReport, rowsWhere, token } from './support';

// 3.2: the broken links report grouped by link with filters, bulk "ignore" and "redirect" actions,
// the CSV export, and the search terms report.

/** Choose a filter option in the report's filter form and apply it (the Filter button). */
async function applyFilter(page: Page, name: string, label: string): Promise<Locator> {
    const select = page.locator(`select[name="filters[${name}]"]`);
    // The CMS may render a select as a chosen.js widget; set the underlying select either way.
    await select.selectOption({ label }, { force: true });
    await page.locator('[name="action_updatereport"]').click();
    const grid = page.locator('#Form_EditForm_Report');
    await expect(grid).toBeVisible();
    await expect(page.locator(`select[name="filters[${name}]"]`)).toHaveValue(await select.inputValue());
    return grid;
}

/**
 * Click a bulk action button; it must be an XHR/fetch request, not a document navigation.
 * Returns the status message the CMS shows (the response's X-Status header).
 */
async function bulkAction(page: Page, grid: Locator, button: string): Promise<string> {
    const navigations: string[] = [];
    const onNav = (frame: { url(): string; parentFrame(): unknown }) => {
        if (!frame.parentFrame()) navigations.push(frame.url());
    };
    page.on('framenavigated', onNav);
    const response = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/reports'));
    await grid.getByRole('button', { name: button }).click();
    const res = await response;
    expect(['xhr', 'fetch']).toContain(res.request().resourceType());
    expect(res.status()).toBe(200);
    page.off('framenavigated', onNav);
    expect(navigations, 'no document navigation').toEqual([]);
    return decodeURIComponent(res.headers()['x-status'] ?? '');
}

test('one row per link: hits summed over referrers, latest referrer, referrer count', async ({ page, visitor }) => {
    const t = token();
    const link = `lgb-group-${t}`;
    expect((await visitor.get(`/${link}`, { headers: { Referer: `https://a.example/${t}` } })).status()).toBe(404);
    expect((await visitor.get(`/${link}`, { headers: { Referer: `https://a.example/${t}` } })).status()).toBe(404);
    expect((await visitor.get(`/${link}`, { headers: { Referer: `https://b.example/${t}` } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    const row = rowsWhere(grid, 'Link', link);
    await expect(row).toHaveCount(1);
    await expect(row.locator('td.col-Count')).toHaveText('3');
    await expect(row.locator('td.col-Referrers')).toHaveText('2');
    await expect(row.locator('td.col-Referrer')).toHaveText(`https://b.example/${t}`);
    await expect(row.locator('td.col-Category')).toHaveText('page');
    // Hits in period come from the monthly counts: all three hits are from this month.
    await expect(row.locator('td.col-RecentCount')).toHaveText('3');
});

test('the category filter narrows the list', async ({ page, visitor }) => {
    const t = token();
    const pageLink = `lgb-cat-${t}`;
    const assetLink = `lgb-cat-${t}/brochure.pdf`;
    const referrer = `https://external.example/cat/${t}`;
    expect((await visitor.get(`/${pageLink}`, { headers: { Referer: referrer } })).status()).toBe(404);
    expect((await visitor.get(`/${assetLink}`, { headers: { Referer: referrer } })).status()).toBe(404);

    let grid = await openReport(page, 'fourOhFour');
    await expect(rowsWhere(grid, 'Referrer', referrer)).toHaveCount(2);

    grid = await applyFilter(page, 'Category', 'asset');
    await expect(rowsWhere(grid, 'Link', assetLink)).toHaveCount(1);
    await expect(rowsWhere(grid, 'Link', pageLink)).toHaveCount(0);
});

test('"Ignore selected links" hides the link and stops logging it', async ({ page, visitor }) => {
    const t = token();
    const link = `lgb-ignore-${t}`;
    const referrer = `https://external.example/ignore-action/${t}`;
    expect((await visitor.get(`/${link}`, { headers: { Referer: referrer } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    await rowsWhere(grid, 'Link', link).locator('input[name="FourOhFourBulk[]"]').check();
    const message = await bulkAction(page, grid, 'Ignore selected links');

    expect(message).toContain('Ignored 1 link');
    // Shown as a CMS notice (a jQuery notice on 5, a React toast on 6: matched by its text).
    await expect(page.getByText(message)).toBeVisible();
    await expect(rowsWhere(grid, 'Link', link)).toHaveCount(0);

    // A further hit is not logged: in the "all" view the row is still there, marked handled,
    // with its count unchanged.
    expect((await visitor.get(`/${link}`, { headers: { Referer: referrer } })).status()).toBe(404);
    await page.goto('/admin/reports/show/FourOhFourReport?filters[Status]=all');
    const all = page.locator('#Form_EditForm_Report');
    await expect(rowsWhere(all, 'Link', link).locator('td.col-Count')).toHaveText('1');

    // The link is on the ignore list editors can change under Settings.
    await page.goto('/admin/settings');
    await expect(page.locator('textarea[name="FourOhFourIgnoreList"]')).toHaveValue(new RegExp(`(^|\\n)${link}($|\\n)`));
});

test('"Redirect selected links" creates a working redirect', async ({ page, visitor }) => {
    const t = token();
    const link = `lgb-redirect-${t}`;
    const target = `/lgb-target-${t}`;
    expect((await visitor.get(`/${link}`, { headers: { Referer: `https://external.example/r/${t}` } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    await rowsWhere(grid, 'Link', link).locator('input[name="FourOhFourBulk[]"]').check();
    await grid.locator('input[name="FourOhFourRedirectTo"]').fill(target);
    const message = await bulkAction(page, grid, 'Redirect selected links');

    expect(message).toContain('Created 1 redirect');
    await expect(page.getByText(message)).toBeVisible();
    await expect(rowsWhere(grid, 'Link', link)).toHaveCount(0);

    const res = await visitor.get(`/${link}`, { maxRedirects: 0 });
    expect(res.status(), 'redirectedurls answers the old link').toBe(301);
    expect(res.headers()['location']).toMatch(new RegExp(`${target}$`));
});

test('the CSV export has the full URL and follows the filters', async ({ page, visitor }) => {
    const t = token();
    const longLink = `lgb-csv-${t}/${'x'.repeat(200)}-tail.pdf`;
    const pageLink = `lgb-csv-${t}`;
    const referrer = `https://external.example/csv/${t}`;
    expect((await visitor.get(`/${longLink}`, { headers: { Referer: referrer } })).status()).toBe(404);
    expect((await visitor.get(`/${pageLink}`, { headers: { Referer: referrer } })).status()).toBe(404);

    await openReport(page, 'fourOhFour');
    const grid = await applyFilter(page, 'Category', 'asset');
    const download = page.waitForEvent('download');
    await grid.getByRole('button', { name: 'Export to CSV' }).click();
    const file = await (await download).path();
    const csv = await readFile(file, 'utf8');

    expect(csv).toContain('Latest referrer');
    expect(csv, 'the full link, not the shortened grid text').toContain(longLink);
    expect(csv, 'the export follows the category filter').not.toContain(`${pageLink},`);
});

test('the search terms report shows the figures per term and filters on trend', async ({ page, visitor }) => {
    const t = token();
    const term = `lgb term ${t}`;
    expect((await visitor.get('/', { params: { Search: term } })).status()).toBe(200);
    expect((await visitor.get('/', { params: { Search: term } })).status()).toBe(200);

    let grid = await openReport(page, 'search');
    for (const header of ['Hits per active year', 'Hits this year', 'Last searched', 'Trend']) {
        await expect(grid.locator('thead')).toContainText(header);
    }
    const row = rowsWhere(grid, 'Query', term);
    await expect(row.locator('td.col-Count')).toHaveText('2');
    await expect(row.locator('td.col-ThisYear')).toHaveText('2');
    await expect(row.locator('td.col-Band')).toHaveText('This year');
    await expect(row.locator('td.col-Flags')).toHaveText('new');

    // Sorting is an XHR reload of the grid.
    const sorted = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/reports'));
    await grid.locator('thead').getByRole('button', { name: 'Hits per active year' }).click();
    expect(['xhr', 'fetch']).toContain((await sorted).request().resourceType());
    await expect(rowsWhere(grid, 'Query', term)).toHaveCount(1);

    grid = await applyFilter(page, 'Trend', 'Faded (not searched this year)');
    await expect(rowsWhere(grid, 'Query', term)).toHaveCount(0);
});
