import { test as base, expect, type APIRequestContext, type Locator, type Page } from '@playwright/test';

// Shared fixtures and helpers for the 404logger specs.
//
// Visitor traffic (the 404s and search queries the module logs) goes through a separate,
// cookie-less request context: an anonymous visitor, as on a live site, and no 404 document load in
// the admin page (Chromium reports a 404 navigation as a console error). What was logged is then
// read where an editor reads it: the two CMS reports, in the logged-in admin page.
// The fixture LgBReset (tests/browser/fixtures/) empties both log tables on every dev/build.

/**
 * test, extended with
 *  - an automatic console guard: every spec fails if the admin page logs a console error or
 *    throws an uncaught exception at any point (warnings do not count);
 *  - `visitor`: an anonymous HTTP client for the front end, without the admin session.
 */
export const test = base.extend<{ consoleGuard: void; visitor: APIRequestContext }>({
    consoleGuard: [
        async ({ page }, use, testInfo) => {
            const errors: string[] = [];
            page.on('console', (msg) => {
                if (msg.type() === 'error') {
                    errors.push(`console.error: ${msg.text()} (${msg.location().url})`);
                }
            });
            page.on('pageerror', (err) => errors.push(`uncaught: ${err.message}`));

            await use();

            if (errors.length) {
                await testInfo.attach('console-errors', { body: errors.join('\n'), contentType: 'text/plain' });
            }
            expect(errors, 'no console errors or uncaught exceptions').toEqual([]);
        },
        { auto: true },
    ],
    visitor: async ({ playwright, baseURL }, use) => {
        // No storageState: this client never carries the admin login.
        const ctx = await playwright.request.newContext({ baseURL });
        await use(ctx);
        await ctx.dispose();
    },
});

export { expect };

/** A token unique to this spec run, so repeats (--repeat-each) never count each other's hits. */
export function token(): string {
    return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

/** The two reports, by title as listed under Reports, and their class (= URL segment). */
export const REPORTS = {
    fourOhFour: { title: '(External) broken links report', className: 'FourOhFourReport' },
    search: { title: 'Search words report', className: 'SearchQueryReport' },
} as const;
export type ReportName = keyof typeof REPORTS;

/**
 * Open a report the way an editor does: the Reports section, then the report's row (a pjax load).
 * Returns the report's GridField.
 */
export async function openReport(page: Page, report: ReportName): Promise<Locator> {
    await page.goto('/admin/reports');
    const list = page.locator('#Form_EditForm_Reports');
    await list.locator('tr.ss-gridfield-item', { hasText: REPORTS[report].title }).click();
    await expect(page).toHaveURL(new RegExp(`/admin/reports/show/${REPORTS[report].className}`));
    const grid = page.locator('#Form_EditForm_Report');
    await expect(grid).toBeVisible();
    return grid;
}

/** Re-open a report with a full page load (to see hits logged after it was first opened). */
export async function reloadReport(page: Page, report: ReportName): Promise<Locator> {
    await page.goto(`/admin/reports/show/${REPORTS[report].className}`);
    const grid = page.locator('#Form_EditForm_Report');
    await expect(grid).toBeVisible();
    return grid;
}

/** The report rows whose cell in column `col` (Link, Referrer, Query) is exactly `text`. */
export function rowsWhere(grid: Locator, col: string, text: string): Locator {
    const exact = new RegExp(`^\\s*${text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*$`);
    return grid.locator('tr.ss-gridfield-item').filter({ has: grid.page().locator(`td.col-${col}`, { hasText: exact }) });
}
