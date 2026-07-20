# Homepage Density Rework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the dead `.grid` CSS shim that overrides every Tailwind grid app-wide, then tighten the homepage so products appear roughly 210px higher.

**Architecture:** Four independent changes across two commits. Commit 1 is a single CSS rule deletion whose blast radius is the whole app — isolated so it can be reverted alone. Commit 2 removes the duplicated category band from the homepage shelf and converts two single-item sections (promo, reorder) from multi-column grids into layouts that size to their content.

**Tech Stack:** React 18, TypeScript, Tailwind CSS, Vitest + Testing Library, react-router-dom.

**Spec:** `docs/superpowers/specs/2026-07-20-homepage-density-rework-design.md`

---

## A note on what the tests can and cannot prove

jsdom has no layout engine. A test cannot assert that a promo strip is 60px tall
or that a card fills its row — `getBoundingClientRect()` returns zeros. The
tests below therefore assert **structure** (how many links render, which
element is present, which container classes are applied). Class-name assertions
are weak tests; they are included only where structure is the thing that
changed and there is no better observable.

The real verification for this work is Task 5, the browser pass. Do not treat a
green suite as proof the layout is fixed.

---

## File Structure

| File | Change | Responsibility after |
|---|---|---|
| `frontend/src/index.css` | Modify (delete rule at :293-298) | Tokens + shims, minus the colliding `.grid` |
| `frontend/src/pages/HomePage.tsx` | Modify | Shelf composition, minus the category band |
| `frontend/src/pages/HomePage.test.tsx` | Modify | Adds the no-category-nav assertion |
| `frontend/src/components/home/PromoTiles.tsx` | Modify | Promo strips, stacked, no grid |
| `frontend/src/components/home/PromoTiles.test.tsx` | Modify | Adds the structure assertion |
| `frontend/src/components/home/ReorderRail.tsx` | Modify | Scroll rail, not a 3-column grid |
| `frontend/src/components/home/ReorderRail.test.tsx` | Modify | Adds the rail-container assertion |
| `frontend/src/components/home/CategoryRail.tsx` | **Untouched** | Stays in tree, unused — rollback path |
| `frontend/src/components/home/CategoryRail.test.tsx` | **Untouched** | Must keep passing |

Working branch: `homepage-density-rework` (already checked out).

> **Note on the working tree:** four backend files from an earlier pricing fix
> (`app/Services/PricingService.php`, three test files under `tests/Feature/`)
> are modified and uncommitted on this branch. They are unrelated to this work.
> Every `git add` below names explicit paths — never use `git add -A` or `git
> add .`, or those changes will be swept into a frontend commit.

---

## Task 1: Delete the colliding `.grid` legacy shim

**Files:**
- Modify: `frontend/src/index.css:293-298`

This is the actual bug. A bare `.grid` rule in the legacy shim block shares a
class name with Tailwind's `.grid` utility, has equal specificity, and is
emitted later — so it wins. Every `grid-cols-*` and `gap-*` in the app is
silently discarded.

- [ ] **Step 1: Confirm nothing consumes the legacy class**

Run:
```bash
cd frontend && grep -rnE 'className=("grid"|\{.grid.\})' src/
```

Expected: no matches (grep exits 1). If a match appears, **stop** — that element
relies on the shim for its columns and this task needs revisiting.

- [ ] **Step 2: Delete the rule**

Remove exactly this block from `frontend/src/index.css` (lines 293-298):

```css
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 20px;
  margin-top: 20px;
}
```

Leave every other rule in the legacy shim block (`.app`, `.content`, `.muted`,
`.fineprint`, `.credit`, `.ok`, `.card`, `.card__img`, `.card__body`, `.btn`, …)
in place. None of them collide with a Tailwind utility name.

- [ ] **Step 3: Run the full frontend suite**

Run: `cd frontend && npx vitest run`
Expected: PASS, 216 tests across 46 files. No test asserts on the shim, so this
is a regression check, not a proof of the fix.

- [ ] **Step 4: Commit**

```bash
git add frontend/src/index.css
git commit -m "fix(css): drop the legacy .grid shim that overrode Tailwind grids

The shim block's bare .grid rule shares a class name with Tailwind's .grid
utility. Equal specificity, emitted later, so it won: every grid-cols-* and
gap-* in the app was replaced by auto-fill tracks, a 20px gap and a 20px top
margin nobody asked for.

Most visible on the home page, where PromoTiles' grid-cols-1 rendered as four
columns and left a single tile sitting in 25% of the row.

No code references the class outside Tailwind's own utility, so the rule was
dead weight. Grid gaps across the app now resolve to whatever each element
declares.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Remove the category band from the homepage shelf

**Files:**
- Modify: `frontend/src/pages/HomePage.tsx:6` (import), `:81` (element)
- Test: `frontend/src/pages/HomePage.test.tsx`

All eight category links already render in the desktop dropdown
(`SiteHeader.tsx:172`) and the mobile drawer (`SiteHeader.tsx:436`). The
homepage band is a third copy.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/pages/HomePage.test.tsx`, inside the `describe('HomePage')`
block, after the `'has no search'` test:

```tsx
  it('leaves category navigation to the header - the band was a third copy of the same 8 links', async () => {
    vi.spyOn(catalogue, 'fetchCatalogue').mockResolvedValue(page([1]) as any);
    renderHome();

    await waitFor(() => expect(screen.getAllByText('Product 1').length).toBeGreaterThan(0));
    expect(
      screen.queryByRole('navigation', { name: /shop by category/i }),
    ).not.toBeInTheDocument();
  });
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/pages/HomePage.test.tsx -t "leaves category navigation"`
Expected: FAIL — the nav is still rendered, so `queryByRole` finds it and
`not.toBeInTheDocument()` throws.

- [ ] **Step 3: Remove the import**

In `frontend/src/pages/HomePage.tsx`, delete line 6:

```tsx
import CategoryRail from '../components/home/CategoryRail';
```

- [ ] **Step 4: Remove the element and update the shelf comment**

Replace lines 76-82 of `frontend/src/pages/HomePage.tsx`:

```tsx
      {/* The shelf opens straight into the category band - a marketplace, not a
          pitch page - so nothing here is a visible headline. The outline still
          needs a root, and the h2s below need something to hang under. */}
      <h1 className="sr-only">Personalised gifts for teams, in bulk</h1>

      <CategoryRail />
      <PromoTiles />
```

with:

```tsx
      {/* The shelf opens on merchandising, not navigation - a marketplace, not a
          pitch page - so nothing here is a visible headline. The outline still
          needs a root, and the h2s below need something to hang under.
          Category links live in the header (dropdown + mobile drawer); a third
          copy on the shelf cost the page's best strip and merchandised nothing.
          CategoryRail is still in the tree if that call needs reversing. */}
      <h1 className="sr-only">Personalised gifts for teams, in bulk</h1>

      <PromoTiles />
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd frontend && npx vitest run src/pages/HomePage.test.tsx`
Expected: PASS, all 9 tests.

- [ ] **Step 6: Confirm CategoryRail's own test still passes**

Run: `cd frontend && npx vitest run src/components/home/CategoryRail.test.tsx`
Expected: PASS, 1 test. The component is unused but intact — this is the
rollback path, and it must stay green.

---

## Task 3: Convert `PromoTiles` from a grid to stacked strips

**Files:**
- Modify: `frontend/src/components/home/PromoTiles.tsx:27-46`
- Test: `frontend/src/components/home/PromoTiles.test.tsx`

With Task 1 done the tile now spans full width, but it is still a tall card
(3xl icon stacked above title and blurb, ~127px). A single full-width card that
tall reads as empty. It becomes a one-line strip.

The `tiles` array stays an array — a second promo is plausible and the shape
should survive it. One entry renders one strip; two render two, stacked.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/components/home/PromoTiles.test.tsx`, at the end of the file:

```tsx
it('renders one strip per tile and no grid container', async () => {
  vi.spyOn(catalogue, 'fetchBulkPricing').mockResolvedValue(null);
  const { container } = renderTiles();

  expect(screen.getAllByRole('link')).toHaveLength(1);
  expect(container.querySelector('.grid')).toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/components/home/PromoTiles.test.tsx -t "one strip per tile"`
Expected: FAIL — `container.querySelector('.grid')` finds the `<ul class="grid
grid-cols-1 gap-3">` and returns a node, so `toBeNull()` throws.

- [ ] **Step 3: Replace the render**

Replace lines 27-46 of `frontend/src/components/home/PromoTiles.tsx` (the whole
`return (...)` block) with:

```tsx
  return (
    <div className="flex flex-col gap-3">
      {tiles.map((t) => (
        <Link
          key={t.to}
          to={t.to}
          className="flex min-h-[44px] items-center gap-3 rounded-xl border border-border bg-gradient-to-r from-brand-50 via-surface to-accent-50 px-4 py-3 transition-colors hover:border-primary/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        >
          <span className="text-xl" aria-hidden="true">
            {t.icon}
          </span>
          {/* Title and blurb sit on one line so a single promo reads as a band
              rather than a mostly-empty card. */}
          <span className="flex-1 text-sm">
            <span className="font-display text-fg">{t.title}</span>{' '}
            <span className="text-fg-muted">{t.blurb}</span>
          </span>
          <svg viewBox="0 0 20 20" className="h-4 w-4 shrink-0 text-fg-subtle" fill="none" aria-hidden="true">
            <path
              d="M8 5l5 5-5 5"
              stroke="currentColor"
              strokeWidth="1.6"
              strokeLinecap="round"
              strokeLinejoin="round"
            />
          </svg>
        </Link>
      ))}
    </div>
  );
```

Everything above line 27 (the `BULK_FALLBACK_BLURB` constant, the `useState`,
the `useEffect`, the `tiles` array) is unchanged.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx vitest run src/components/home/PromoTiles.test.tsx`
Expected: PASS, 6 tests. The four pre-existing copy tests must stay green — the
blurb text and the `/products` href are unchanged, only the container is
different.

---

## Task 4: Convert `ReorderRail` from a 3-column grid to a scroll rail

**Files:**
- Modify: `frontend/src/components/home/ReorderRail.tsx:47`
- Test: `frontend/src/components/home/ReorderRail.test.tsx`

`sm:grid-cols-3` with one quote gives a card at a third width beside two empty
columns — the same void as the promo, in a different grid.

Deliberately **not** reusing `ProductRail`: that component flanks its track with
always-rendered prev/next buttons. `MAX_QUOTES` is 3, so at desktop width both
buttons would sit there permanently disabled. Native scroll on overflow is the
honest control for three items.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/components/home/ReorderRail.test.tsx`, inside the
`describe('ReorderRail')` block, at the end:

```tsx
  it('lays the quotes out as a rail so a single quote is not stranded in a 3-column grid', async () => {
    vi.spyOn(quotes, 'fetchRecentQuotes').mockResolvedValue([quote(7)]);
    const { container } = renderRail();

    await waitFor(() =>
      expect(screen.getByRole('link', { name: /quote #7/i })).toBeInTheDocument(),
    );

    const list = container.querySelector('ul');
    expect(list?.className).toContain('overflow-x-auto');
    expect(list?.className).not.toContain('grid-cols');
  });
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd frontend && npx vitest run src/components/home/ReorderRail.test.tsx -t "lays the quotes out as a rail"`
Expected: FAIL — the `<ul>` still carries `grid grid-cols-1 ... sm:grid-cols-3`,
so the `overflow-x-auto` assertion throws first.

- [ ] **Step 3: Replace the list container and card sizing**

In `frontend/src/components/home/ReorderRail.tsx`, replace line 47:

```tsx
      <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
```

with:

```tsx
      {/* A rail, not a grid: with up to MAX_QUOTES cards a 3-column track leaves
          a lone quote stranded beside two empty columns. Fixed-width cards that
          overflow into a scroll read as deliberate at any count. */}
      <ul className="mt-4 flex gap-3 overflow-x-auto pb-1">
```

and replace line 49:

```tsx
          <li key={q.id}>
```

with:

```tsx
          <li key={q.id} className="w-56 shrink-0">
```

The `<Link>` and its contents are unchanged.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd frontend && npx vitest run src/components/home/ReorderRail.test.tsx`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the full frontend suite**

Run: `cd frontend && npx vitest run`
Expected: PASS, 219 tests across 46 files (216 existing + 3 added).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/pages/HomePage.tsx frontend/src/pages/HomePage.test.tsx \
        frontend/src/components/home/PromoTiles.tsx frontend/src/components/home/PromoTiles.test.tsx \
        frontend/src/components/home/ReorderRail.tsx frontend/src/components/home/ReorderRail.test.tsx
git commit -m "feat(home): tighten the shelf - drop category band, slim promo, rail reorders

The home page opened on ~210px of navigation chrome and half-empty rows
before a single product appeared.

The category band was a third copy of links the header already carries in
both the desktop dropdown and the mobile drawer. It was sized as furniture
to avoid competing with them, which left it too small to merchandise and
too large to ignore. Removed from the shelf; CategoryRail stays in the tree
so restoring it is a one-line change.

PromoTiles becomes a one-line strip and ReorderRail a scroll rail, so
neither section reserves columns it cannot fill.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Browser verification pass

**Files:** none — this is verification, not code.

Task 1 changed grid gaps on every page in the app. The suite cannot catch a
spacing regression. This task is the actual proof and must not be skipped.

- [ ] **Step 1: Ensure the dev server is up**

The frontend runs on `:5173` and the API on `:8000`. Both may already be
running outside the harness — check before starting anything:

```bash
curl -s -o /dev/null -w "%{http_code}" http://localhost:5173
```

Expected: `200`. If not, start them with the `frontend` and `api` configs in
`.claude/launch.json`.

- [ ] **Step 2: Verify the homepage**

Load `http://localhost:5173/` and confirm, via `read_page` / `javascript_tool`:
- No `nav[aria-label="Shop by category"]` in the DOM.
- The promo `<a>` computed height is under 70px.
- The first product card's `getBoundingClientRect().top` is at least 150px
  smaller than the pre-change value of ~470px.

Record the actual promo height. The spec estimated ~60px and flagged it as
projected — replace the estimate with the measurement.

- [ ] **Step 3: Check every page the shim deletion touched**

For each of `/products`, `/cart`, `/quotes`, `/gift-ideas`, and one staff page
(`/production` if reachable with the current session), load it and confirm:
- No element overlaps or collides.
- Grid gaps look intentional, not cramped.
- The footer's four columns still read as four columns.

Run this snippet on each page and check that no grid reports a gap it did not
declare:

```js
[...document.querySelectorAll('[class*="grid-cols"]')].map(el => ({
  cls: el.className.toString().slice(0, 70),
  cols: getComputedStyle(el).gridTemplateColumns.split(' ').length,
  gap: getComputedStyle(el).gap,
  mt: getComputedStyle(el).marginTop,
}))
```

Expected: `gap` matches each element's own `gap-*` class (`gap-2` → 8px,
`gap-3` → 12px, `gap-4` → 16px, `gap-8` → 32px) and `mt` is `0px` unless the
element declares a margin.

- [ ] **Step 4: Check mobile width**

Resize to 375px and reload the homepage. Confirm:
- The promo strip wraps without overflowing.
- The reorder rail scrolls horizontally rather than squashing.
- The page has no horizontal scrollbar.

- [ ] **Step 5: Report findings**

If any page regressed, fix it and note the fix. If the shim deletion turns out
to have been load-bearing somewhere, say so plainly rather than patching around
it — that would mean Task 1's premise was wrong and the spec needs updating.

---

## Rollback

**Restoring the category band:** re-add the import and `<CategoryRail />` to
`HomePage.tsx`, and delete the `'leaves category navigation to the header'`
test. The component, its styles and its test are untouched.

**Reverting the shim deletion:** `git revert` the Task 1 commit. It is
deliberately isolated from the layout work for this reason.
