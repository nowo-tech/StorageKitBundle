import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { ADMIN, ALICE, PNG, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe.configure({ mode: 'serial' });

test.describe('Storage Kit screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 } });

  test('notes — an image uploaded into a note, served by the application', async ({ browser }) => {
    const context = await browser.newContext({ httpCredentials: ALICE, viewport: { width: 1280, height: 1000 } });
    const page = await context.newPage();
    await page.goto('/notes');
    await page.getByTestId('demo-note-body').fill('Release checklist photo');
    await page.getByTestId('demo-note-image').setInputFiles({ name: 'checklist.png', mimeType: 'image/png', buffer: PNG });
    await page.getByTestId('demo-note-save').click();
    await expect(page.getByTestId('demo-note-image-view').first()).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'notes.png'), fullPage: true });
    await context.close();
  });

  test('storage-settings — the instance bucket after a successful test', async ({ browser }) => {
    const context = await browser.newContext({ httpCredentials: ADMIN, viewport: { width: 1280, height: 1000 } });
    const page = await context.newPage();
    await page.goto('/admin/storage');
    await page.getByTestId('storage-kit-test').click();
    await expect(page.getByText('The connection works')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'storage-settings.png'), fullPage: true });
    await context.close();
  });

  test('storage-move — a verified copy to another bucket', async ({ browser }) => {
    const context = await browser.newContext({ httpCredentials: ADMIN, viewport: { width: 1280, height: 1100 } });
    const page = await context.newPage();
    await page.goto('/admin/storage/move');
    await page.locator('#storage_target_endpoint').fill('http://rustfs:9000');
    await page.locator('#storage_target_bucket').fill('storage-kit-archive');
    await page.locator('#storage_target_access_key').fill('demo-access-key');
    await page.locator('#storage_target_secret').fill('demo-secret-key-not-real');
    await page.locator('#storage_target_path_style').check();
    await page.getByRole('button', { name: 'Save the new bucket' }).click();
    await page.getByTestId('storage-kit-move-sync').click();
    await expect(page.getByTestId('storage-kit-sync')).toContainText('Completed and verified');
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'storage-move.png'), fullPage: true });
    await context.close();
  });
});
