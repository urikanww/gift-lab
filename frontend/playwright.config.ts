import { defineConfig, devices } from '@playwright/test';

/**
 * Layer 1 E2E: full-stack journeys through the real SPA (5173) against the real
 * Laravel API (8000). See e2e/README.md for the DB-isolation recipe - these
 * specs mutate data (they place quotes), so point them at an e2e database, not
 * your working dev DB.
 *
 * globalSetup seeds the deterministic fixtures (E2eSeeder) before any spec runs.
 */
export default defineConfig({
  testDir: './e2e/journeys',
  // Fixtures are shared mutable state (one seeded buyer/product); run serially
  // so parallel checkouts can't race on the same cart/quote assumptions.
  fullyParallel: false,
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',
  globalSetup: './e2e/global-setup.ts',

  // `php artisan serve` is a single-threaded dev server: the SPA's concurrent
  // request bursts (mount /user probe + csrf + login + nav data) serialize on
  // one worker, so assertions need more headroom than the 5s default. On CI
  // (Linux) PHP_CLI_SERVER_WORKERS below adds real concurrency; on Windows fork
  // is unavailable, so this timeout is what keeps the suite green.
  timeout: 60_000,
  expect: { timeout: 15_000 },

  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:5173',
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
  },

  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],

  // The API server inherits APP_ENV=e2e so it loads .env.e2e (isolated sqlite).
  // reuseExistingServer is FALSE for the API on purpose: reusing whatever is on
  // :8000 risks silently testing against your dev MySQL DB (a stale server there
  // is why early runs failed). Playwright starts its own known-good e2e server
  // and errors loudly if the port is busy. Kill stray dev servers first.
  webServer: [
    {
      command: 'php artisan serve --port=8000',
      cwd: '..',
      port: 8000,
      reuseExistingServer: false,
      // PHP_CLI_SERVER_WORKERS gives the dev server real concurrency where fork
      // is available (Linux/CI); it is a no-op on Windows but harmless.
      env: { APP_ENV: 'e2e', PHP_CLI_SERVER_WORKERS: '4' },
      timeout: 60_000,
    },
    {
      command: 'npm run dev -- --port 5173 --strictPort',
      port: 5173,
      reuseExistingServer: !process.env.CI,
      timeout: 60_000,
    },
  ],
});
