import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { ADMIN, ALICE } from './helpers';

/** Accessibility (axe, WCAG 2.1 A/AA) of every demo page that shows a bundle screen. Independent of the other specs (the seeded demo data is enough). */
const pages: { path: string; user: { username: string; password: string } }[] = [
  { path: '/notes', user: ALICE },
  { path: '/admin/storage', user: ADMIN },
  { path: '/admin/storage/move', user: ADMIN },
  { path: '/admin/settings/sections/storage', user: ADMIN },
];

for (const { path, user } of pages) {
  test(`axe: ${path}`, async ({ browser }) => {
    const context = await browser.newContext({ httpCredentials: user });
    const page = await context.newPage();
    await page.goto(path);
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      // The Symfony web debug toolbar (dev only) is not part of the bundle UI.
      .exclude('.sf-toolbar')
      .exclude('.sf-minitoolbar')
      // TODO(UiKit): remove once UiKit fixes the contrast of the settings-card intro (`.nowo-ui-card__intro`, muted text
      // on the card header with the bootstrap5 theme). Upstream (UiKit 2.23 settings scaffold): the section intro
      // fails color-contrast on every Instance Settings page; tracked for UiKit, not part of this bundle's markup.
      .exclude('.nowo-ui-card__intro')
      .analyze();
    expect(results.violations.map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).join(', ')}`)).toEqual([]);
    await context.close();
  });
}
