/**
 * Loads your MakerWorld auth for downloading gated files.
 *
 * The f3mf endpoint validates a real JWT. Provide it any of these ways
 * (checked in order):
 *
 *   1. env  MW_TOKEN   = the `token` cookie's JWT value
 *   2. file token.txt  = same JWT, one line
 *   3. env  MW_COOKIE  = full Cookie header string (copy from a request)
 *   4. file cookie.txt = same full Cookie header
 *
 * Easiest: DevTools -> Application -> Cookies -> makerworld.com -> copy the
 * value of the `token` cookie into token.txt (or set MW_TOKEN).
 *
 * These files hold a secret — they are gitignored. Do not commit them.
 */

import { readFile } from 'node:fs/promises';

/** @returns {Promise<{headers: Record<string,string>, source: string}>} */
export async function loadAuth() {
  const token =
    process.env.MW_TOKEN?.trim() || (await tryFile('token.txt'));
  if (token) {
    // Bearer ONLY. Adding a `Cookie: token=` header alongside makes MakerWorld
    // fall back to cookie-session auth and reject with 403 — send just Bearer.
    return {
      source: process.env.MW_TOKEN ? 'env MW_TOKEN' : 'token.txt',
      headers: { Authorization: `Bearer ${token}` },
    };
  }

  const cookie =
    process.env.MW_COOKIE?.trim() || (await tryFile('cookie.txt'));
  if (cookie) {
    // If the cookie header carries a token=..., mirror it into a Bearer header.
    const m = /(?:^|;\s*)token=([^;]+)/.exec(cookie);
    return {
      source: process.env.MW_COOKIE ? 'env MW_COOKIE' : 'cookie.txt',
      headers: {
        Cookie: cookie,
        ...(m ? { Authorization: `Bearer ${decodeURIComponent(m[1])}` } : {}),
      },
    };
  }

  throw new Error(
    'No MakerWorld auth found. Put your `token` cookie JWT in token.txt ' +
      '(or set MW_TOKEN). See auth.mjs header for details.',
  );
}

async function tryFile(path) {
  try {
    return (await readFile(path, 'utf8')).trim();
  } catch {
    return '';
  }
}
