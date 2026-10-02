import { test, expect, openReport, reloadReport, rowsWhere, token } from './support';

// SearchQueryLogger: the ?Search= value of a front-end page request is logged (trimmed,
// lower-cased) with a hit count, and the "Search words report" lists it.

test('a front-end search query is logged and counted in the report', async ({ page, visitor }) => {
    const t = token();
    const query = `LgB Query ${t}`;

    const res = await visitor.get('/', { params: { Search: query } });
    expect(res.status()).toBe(200);

    let grid = await openReport(page, 'search');
    // Stored lower-cased.
    const stored = query.toLowerCase();
    let row = rowsWhere(grid, 'Query', stored);
    await expect(row).toHaveCount(1);
    await expect(row.locator('td.col-Count')).toHaveText('1');

    // Different case and surrounding whitespace: the same query, counted on the same row.
    expect((await visitor.get('/', { params: { Search: `  ${query.toUpperCase()} ` } })).status()).toBe(200);
    grid = await reloadReport(page, 'search');
    row = rowsWhere(grid, 'Query', stored);
    await expect(row).toHaveCount(1);
    await expect(row.locator('td.col-Count')).toHaveText('2');
});

test('an array or empty search value is ignored and does not break the page', async ({ page, visitor }) => {
    const t = token();
    // ?Search[]=... arrives as an array; it used to reach strtolower() and fatal the page.
    const arr = await visitor.get(`/?Search[]=lgb-array-${t}`);
    expect(arr.status(), 'the page still renders').toBe(200);
    const empty = await visitor.get('/?Search=%20%20');
    expect(empty.status()).toBe(200);

    const grid = await openReport(page, 'search');
    await expect(grid.locator('tr.ss-gridfield-item', { hasText: `lgb-array-${t}` })).toHaveCount(0);
    await expect(rowsWhere(grid, 'Query', '')).toHaveCount(0);
});
