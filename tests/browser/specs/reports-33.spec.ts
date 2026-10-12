import { test, expect, openReport, rowsWhere, token } from './support';

// 3.3: the "Link contains" filter of the broken links report (issue #13), and the bulk-action
// controls laid out on one row by the module's stylesheet (issue #12).

test('"Link contains" narrows the list, treats % and _ literally, and is kept in the URL', async ({ page, visitor }) => {
    const t = token();
    const wanted = `lgb-find_${t}`;
    // '_' as a LIKE wildcard would match this one too.
    const lookalike = `lgb-findx${t}`;
    const referrer = `https://external.example/find/${t}`;
    expect((await visitor.get(`/${wanted}`, { headers: { Referer: referrer } })).status()).toBe(404);
    expect((await visitor.get(`/${lookalike}`, { headers: { Referer: referrer } })).status()).toBe(404);

    let grid = await openReport(page, 'fourOhFour');
    await expect(rowsWhere(grid, 'Referrer', referrer)).toHaveCount(2);

    await page.locator('input[name="filters[LinkContains]"]').fill(`FIND_${t}`);
    await page.locator('[name="action_updatereport"]').click();
    grid = page.locator('#Form_EditForm_Report');
    await expect(rowsWhere(grid, 'Link', wanted)).toHaveCount(1);
    await expect(rowsWhere(grid, 'Link', lookalike)).toHaveCount(0);
    await expect(page.locator('input[name="filters[LinkContains]"]')).toHaveValue(`FIND_${t}`);

    // A shared filter URL opens the same filtered view.
    await page.goto(`/admin/reports/show/FourOhFourReport?filters[LinkContains]=${encodeURIComponent(`find_${t}`)}`);
    grid = page.locator('#Form_EditForm_Report');
    await expect(rowsWhere(grid, 'Link', wanted)).toHaveCount(1);
    await expect(rowsWhere(grid, 'Link', lookalike)).toHaveCount(0);
});

test('the bulk-action controls sit on one row in the grid toolbar', async ({ page, visitor }) => {
    const t = token();
    expect((await visitor.get(`/lgb-layout-${t}`, { headers: { Referer: `https://external.example/l/${t}` } })).status()).toBe(404);

    const grid = await openReport(page, 'fourOhFour');
    const bulk = grid.locator('.fourohfour-bulk');
    await expect(bulk).toBeVisible();
    await expect(bulk).toHaveCSS('display', 'flex');

    // Every control's vertical centre on the same line (they stacked three high before 3.3).
    const controls = bulk.locator('button, input');
    const count = await controls.count();
    expect(count, 'Ignore, and with redirectedurls the target input and Redirect').toBeGreaterThanOrEqual(1);
    const centres: number[] = [];
    for (let i = 0; i < count; i++) {
        const box = await controls.nth(i).boundingBox();
        expect(box).not.toBeNull();
        centres.push(box!.y + box!.height / 2);
    }
    for (const centre of centres) {
        expect(Math.abs(centre - centres[0])).toBeLessThan(4);
    }
});
