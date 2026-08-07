import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Reset + seed the e2e database once before the suite. Runs against the Laravel
 * app in the repo root (one level up from frontend/), under APP_ENV=e2e so it
 * targets the isolated .env.e2e database - never your working dev DB.
 *
 * migrate:fresh guarantees every run starts from the same known state, so the
 * journeys are deterministic and re-runnable. Set E2E_SKIP_SEED=1 to skip (e.g.
 * when iterating on specs against an already-seeded DB).
 */
async function globalSetup(): Promise<void> {
  if (process.env.E2E_SKIP_SEED === '1') {
    console.log('[e2e] E2E_SKIP_SEED=1 - skipping DB reset/seed');
    return;
  }

  const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
  console.log('[e2e] migrate:fresh + E2eSeeder (APP_ENV=e2e)…');

  execSync('php artisan migrate:fresh --seed --seeder=Database\\Seeders\\E2eSeeder --force', {
    cwd: repoRoot,
    stdio: 'inherit',
    env: { ...process.env, APP_ENV: 'e2e' },
  });
}

export default globalSetup;
