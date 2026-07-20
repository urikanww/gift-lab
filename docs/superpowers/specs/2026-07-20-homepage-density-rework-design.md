# Homepage Density Rework — Design

**Date:** 2026-07-20
**Status:** Draft for review
**Touches:** `HomePage`, `CategoryRail`, `PromoTiles`, `ReorderRail`, `index.css` legacy shims

## Problem

The marketplace home page reads as mostly empty. A buyer landing on `/` sees a
thin band of category chips, a small promo card marooned in a quarter-width
column, a single quote card occupying a third of its row, and no product until
roughly 210px down the page.

Two independent causes, and they were easy to conflate:

1. **A CSS bug** silently overrides every grid in the app.
2. **A design problem** — sections that hold one item still reserve a
   multi-column track, and the top of the page is spent on navigation chrome
   that the header already carries twice.

Fixing only the bug leaves the page sparse. Fixing only the layout leaves the
bug live on every other page.

## Root cause of the bug

`index.css` closes with a block labelled *"LEGACY SHIMS — transitional only …
SAFE TO DELETE once every page has been migrated."* One of those shims is a bare
`.grid` rule:

```css
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 20px;
  margin-top: 20px;
}
```

That class name collides with Tailwind's `.grid` utility. Same specificity, so
source order decides, and the shim is emitted last — it wins. Every
`grid-cols-*` and `gap-*` on a `.grid` element is discarded in favour of
`auto-fill` tracks, a 20px gap, and an unrequested 20px top margin.

Measured on the live homepage at a 1280px viewport:

| element | declared | computed |
|---|---|---|
| `PromoTiles` — `grid-cols-1 gap-3` | 1 column, 12px | **4 columns**, 20px |
| `CategoryRail` — `grid-cols-4 sm:grid-cols-8 gap-2` | 8px | 20px |
| Product grid — `gap-4 lg:grid-cols-5` | 16px | 20px |
| Footer — `gap-8 md:grid-cols-4` | 32px | 20px |

`PromoTiles` renders a single tile into a four-column track. It occupies 25% of
the row and leaves 75% empty. That is the largest void in the screenshot.

Grep for legacy consumers (`className="grid"` with no Tailwind column classes)
returns zero matches across `frontend/src`. The rule is dead weight.

## Decisions (locked)

| Question | Decision |
|---|---|
| Delete the `.grid` shim | Yes — separate commit, ahead of the layout work |
| Homepage category band | **Remove** from the shelf |
| `CategoryRail` component | Keep in tree, unused — rollback path |
| Promo presentation | Single-line horizontal strip, full width |
| Reorder presentation | Horizontal rail, cards at natural width |
| Category discovery elsewhere | Out of scope |

### Why the category band goes

`CATEGORIES` renders in three places: the desktop dropdown
(`SiteHeader.tsx:172`), the mobile drawer (`SiteHeader.tsx:436`), and the
homepage band (`CategoryRail.tsx:13`). All eight links, all three surfaces. The
drawer covers mobile, so "the header hides it on small screens" is not a
defence.

The component's own docblock records the tension:

> Deliberately sized as furniture, not a headline section — the header dropdown
> carries the same 8 links, so this must not read as a second, competing "Shop
> by category" feature.

The band was shrunk to avoid competing with the header. That compromise is what
now reads as blank space: too small to merchandise, too large to disappear,
sitting on the most valuable strip of the page. Shrinking a redundant element
does not resolve the redundancy.

The counter-argument is real and is being overruled deliberately: a dropdown has
to be opened before it pays off, so the homepage band is the only zero-click
category discovery on the site. The judgement is that 140px chips with 24px
emoji serve that purpose too weakly to justify the position. If category
discovery is worth reinstating, it should return as real merchandising —
category cards carrying product photography — most likely on `/products`, where
browse intent already exists. That is a separate piece of work.

**This decision rests on reasoning, not data.** No analytics on
`/products?category=` traffic split by referrer were available. If those links
carry meaningful traffic from `/`, this decision should be revisited.

## Changes

### 1. Delete the `.grid` legacy shim — `index.css`

Remove the rule. Leave the rest of the shim block intact; the other classes
(`.card`, `.btn`, `.content`, …) do not collide with Tailwind utilities.

App-wide effect: every grid drops to the gap its own classes declare, and loses
the 20px top margin. Pages spacing changes as a result: Catalogue, Cart, Quotes,
Production, Procurement, plus the footer.

### 2. Remove `CategoryRail` from the shelf — `HomePage.tsx`

Delete the `<CategoryRail />` element and its import. Leave
`components/home/CategoryRail.tsx` and its test in place. Restoring the band is
then a one-line change.

### 3. `PromoTiles` → horizontal strip

Currently a `<ul class="grid grid-cols-1">` wrapping one `<li>` whose link is a
tall card with a 3xl icon above stacked title and blurb.

Becomes a single full-width link: icon, title and blurb inline on one line, a
trailing chevron. Target height around 60px, down from 127px.

The `tiles` array stays — a second promo is plausible, and the array shape
should survive it. With one entry it renders one strip; with two it renders two
stacked strips. No grid.

### 4. `ReorderRail` → horizontal rail

Currently `grid-cols-1 sm:grid-cols-3`. With one quote that is a card at a third
width beside two empty columns.

Becomes `flex gap-3 overflow-x-auto` with cards at a fixed width (`w-56
shrink-0`). One quote renders one card at its natural size and reads
deliberate.

Deliberately **not** reusing `ProductRail`'s button-driven carousel: that
component flanks its track with prev/next buttons that are always present.
`MAX_QUOTES` is 3, so at desktop width both buttons would permanently render
disabled. Native scroll on overflow is the honest control here.

## Ordering and commits

Two commits, in this order:

1. **`fix(css): drop the legacy .grid shim that overrode Tailwind grids`** — the
   one-rule deletion. Independently revertable. This is the risky change and
   should not be entangled with layout work.
2. **`feat(home): tighten the shelf — drop the category band, slim promo, rail reorders`**
   — changes 2 through 4.

## Risks

**The shim deletion is app-wide.** Grep finds no code referencing the class
outside Tailwind's own utility, but "nothing references it" and "nothing looks
different" are not the same claim. Any page whose layout currently depends on
the accidental 20px gap or top margin will shift. Requires a visual pass over
Catalogue, Cart, Quotes, Production, Procurement, and the footer — not just the
homepage.

**Removing the band is a product judgement made without usage data.** Stated
plainly above so it can be challenged.

**Vertical saving is partly projected.** The 147px from the category band
(115px height plus a 32px flex gap) is measured from the live DOM. The ~67px
from the promo strip is an estimate from the target height and should be
confirmed after implementation, not quoted as fact beforehand.

> **Measured after implementation (2026-07-20).** Both estimates were low. The
> shelf gap is 40px at desktop width, not 32px, so the category band cost
> 155px. The promo went 127px → **50px**, not the projected 60px. Total chrome
> above the first section: 322px → 90px, a **232px** saving against the ~210px
> predicted.

## Testing

Existing tests that must keep passing:

- `CategoryRail.test.tsx` — the component still exists and still renders; only
  its use by `HomePage` goes.
- `PromoTiles.test.tsx` — asserts copy and the bulk-pricing fetch, both of which
  survive the restructure.
- `ReorderRail.test.tsx` — asserts empty/error render nothing, unaffected by the
  container change.
- `HomePage.test.tsx` — checked: its only `category` reference is a fixture
  field on a product, not an assertion about the band. No edit needed.

New coverage:

- `HomePage` does not render the category navigation.
- `PromoTiles` renders one link per tile and no grid container.
- `ReorderRail` renders a scrollable list rather than a three-column grid.

Verification beyond the suite: load each affected page in the browser after the
shim deletion and compare against the current render. Automated tests will not
catch a gap regression.

## Rollback

Restoring the category band: re-add the import and the `<CategoryRail />`
element to `HomePage.tsx`. The component, its styles, and its test are
untouched, so nothing else needs to be rebuilt.

Reverting the shim deletion: revert commit 1. Kept separate for exactly this
reason.
