import { test, expect } from '@playwright/test';
import { ADMIN, ALICE, PNG } from './helpers';

/** REQ-DEMO-013 — happy path and the main refusals against the running demo (RustFS bucket, MySQL, FrankenPHP). */
test.describe.configure({ mode: 'serial' });

test.describe('Storage Kit demo', () => {
  test('home is public; the storage pages need the admin role', async ({ page, browser }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'S3-compatible storage for Symfony' })).toBeVisible();

    const alice = await browser.newContext({ httpCredentials: ALICE });
    const response = await (await alice.newPage()).goto('/admin/storage');
    expect(response?.status()).toBe(403);
    await alice.close();
  });

  test.describe('as the administrator', () => {
    test.use({ httpCredentials: ADMIN });

    test('the storage section shows only the selected provider\'s fields, keeps the secret and tests the connection', async ({ page }) => {
      await page.goto('/admin/settings/sections/storage');
      const provider = page.locator('#settings_section_provider');
      const endpointRow = page.locator('[data-nowo-ui-visible-when-field="settings_section[provider]"]').filter({ has: page.locator('#settings_section_endpoint') });
      await expect(provider).toHaveValue('s3_compatible');
      await expect(endpointRow).toBeVisible();

      // Amazon S3: no endpoint, public endpoint or path style.
      await provider.selectOption('aws_s3');
      await expect(endpointRow).toBeHidden();
      await expect(page.locator('#settings_section_bucket')).toBeVisible();
      // No provider: every bucket field is hidden (uploads off).
      await provider.selectOption('');
      await expect(page.locator('#settings_section_bucket')).toBeHidden();
      await provider.selectOption('s3_compatible');
      await expect(endpointRow).toBeVisible();

      // The secret is never rendered back; blank keeps it.
      await expect(page.locator('#settings_section_secret_value')).toHaveValue('');
      expect(await page.content()).not.toContain('demo-secret-key-not-real');
      await page.locator('#settings_section_prefix').fill('demo');
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(page).toHaveURL(/\/admin\/settings\/sections\/storage$/);

      await page.getByTestId('storage-kit-section-test').click();
      await expect(page).toHaveURL(/\/admin\/settings\/sections\/storage$/);
      await expect(page.getByText('The connection works')).toBeVisible();

      await page.goto('/admin/storage');
      await expect(page.getByTestId('storage-kit-settings-managed')).toBeVisible();
      await expect(page.getByTestId('storage-kit-edit-link')).toHaveAttribute('href', '/admin/settings/sections/storage');
    });

    test('refuses a private or metadata endpoint (SSRF guard)', async ({ page }) => {
      await page.goto('/admin/settings/sections/storage');
      await page.locator('#settings_section_endpoint').fill('http://169.254.169.254');
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(page.getByText('This address is not allowed')).toBeVisible();
    });

    test('offers the AWS access-key CSV for Amazon S3 only and refuses a wrong file on its row', async ({ page }) => {
      await page.goto('/admin/settings/sections/storage');
      const provider = page.locator('#settings_section_provider');
      const csv = page.locator('#settings_section_access_key_csv');
      await expect(csv).toBeHidden();
      await provider.selectOption('aws_s3');
      await expect(csv).toBeVisible();
      await expect(csv).toHaveAttribute('type', 'file');
      await expect(page.getByText('Import from AWS CSV')).toBeVisible();

      // Wrong header: a form error on the row, nothing saved, the content never echoed.
      await csv.setInputFiles({ name: 'bob_accessKeys.csv', mimeType: 'text/csv', buffer: Buffer.from('Key,Secret\r\nAKIAIOSFODNN7EXAMPLE,wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY\r\n') });
      await page.getByRole('button', { name: 'Save' }).click();
      await expect(page.getByText('The file is not an AWS access key CSV')).toBeVisible();
      expect(await page.content()).not.toContain('wJalrXUtnFEMI');

      // Still the demo's RustFS connection.
      await page.goto('/admin/settings/sections/storage');
      await expect(provider).toHaveValue('s3_compatible');
    });
  });

  test.describe('as a member', () => {
    test.use({ httpCredentials: ALICE });

    test('uploads an image into a note and downloads it through the application', async ({ page }) => {
      await page.goto('/notes');
      await expect(page.getByTestId('demo-upload-config')).toHaveAttribute('data-state', 'on');
      await page.getByTestId('demo-note-body').fill('Whiteboard photo from the planning session');
      await page.getByTestId('demo-note-image').setInputFiles({ name: 'whiteboard.png', mimeType: 'image/png', buffer: PNG });
      await page.getByTestId('demo-note-save').click();
      await expect(page.getByText('Note saved.')).toBeVisible();

      const image = page.getByTestId('demo-note-image-view').first();
      await expect(image).toBeVisible();
      await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);

      const src = await image.getAttribute('src');
      expect(src).toMatch(/^\/storage\/files\/[0-9a-f-]{36}\/preview$/);
      const download = await page.request.get(src as string);
      expect(download.status()).toBe(200);
      expect(download.headers()['content-type']).toBe('image/png');
      expect(download.headers()['x-content-type-options']).toBe('nosniff');
      // The dev web profiler strips CSP headers from every response; without it the bundle's sandbox CSP is sent
      // (asserted by the PHPUnit UploadDownloadTest).
      if (!download.headers()['x-debug-token']) {
        expect(download.headers()['content-security-policy']).toContain('sandbox');
      }
      expect(download.headers()['content-disposition']).toContain('attachment');
    });

    test('the JSON upload endpoint refuses SVG and a forged token', async ({ page }) => {
      await page.goto('/notes');
      const config = page.getByTestId('demo-upload-config');
      const url = (await config.getAttribute('data-url')) as string;
      const token = (await config.getAttribute('data-token')) as string;
      const svg = { name: 'x.svg', mimeType: 'image/png', buffer: Buffer.from('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') };

      const refused = await page.request.post(url, { multipart: { file: svg }, headers: { 'X-CSRF-Token': token } });
      expect(refused.status()).toBe(422);
      expect((await refused.json()).error).toBe('type');

      const forged = await page.request.post(url, { multipart: { file: { name: 'a.png', mimeType: 'image/png', buffer: PNG } }, headers: { 'X-CSRF-Token': 'forged' } });
      expect(forged.status()).toBe(403);

      const ok = await page.request.post(url, { multipart: { file: { name: 'a.png', mimeType: 'image/png', buffer: PNG } }, headers: { 'X-CSRF-Token': token } });
      expect(ok.status()).toBe(201);
      const body = await ok.json();
      expect(body.markdown).toMatch(/^!\[a\]\(storage-file:[0-9a-f-]{36}\)$/);
    });
  });

  test.describe('moving the files', () => {
    test.use({ httpCredentials: ADMIN });

    test('copies every file to the archive bucket, verifies it and switches', async ({ page }) => {
      await page.goto('/admin/storage/move');
      await page.locator('#storage_target_endpoint').fill('http://rustfs:9000');
      await page.locator('#storage_target_bucket').fill('storage-kit-archive');
      await page.locator('#storage_target_access_key').fill('demo-access-key');
      await page.locator('#storage_target_secret').fill('demo-secret-key-not-real');
      await page.locator('#storage_target_path_style').check();
      await page.getByRole('button', { name: 'Save the new bucket' }).click();
      await expect(page.getByText('The new bucket was saved.')).toBeVisible();

      await page.getByTestId('storage-kit-move-test').click();
      await expect(page.getByText('The connection works')).toBeVisible();
      await page.getByTestId('storage-kit-move-sync').click();
      await expect(page.getByTestId('storage-kit-sync')).toContainText('Completed and verified');

      page.once('dialog', (dialog) => dialog.accept());
      await page.getByTestId('storage-kit-move-switch').click();
      // UiKit confirm runtime: an in-page dialog (or the native confirm() fallback accepted above).
      const confirm = page.getByRole('dialog').getByRole('button').filter({ hasText: /confirm|switch|ok/i });
      await confirm.first().waitFor({ state: 'visible', timeout: 3000 }).catch(() => undefined);
      if (await confirm.count()) {
        await confirm.first().click();
      }
      await expect(page.getByText('Every file now comes from the new bucket.')).toBeVisible();
      await expect(page.getByTestId('storage-kit-retired')).toBeVisible();
    });
  });
});
