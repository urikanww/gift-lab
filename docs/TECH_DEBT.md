# Tech debt register

Tracked debt items referenced from code comments. Each entry names the code
site, the debt, and the exit criteria.

## scraped-images

- **Code site:** `app/Services/Catalogue/ScrapedCatalogueService.php` (`applyData`)
- **Debt:** Scraped listings serve the source marketplace image URL as-is
  (spec 6.4 v1 decision, audit B8). No re-hosting, no background cleanup, no
  licence review of the photograph itself. Hotlinked images can die or change
  under us (the daily re-sync marks `source_dead`, but only on a full 404).
- **Exit criteria:** re-host images to our own storage at ingest; generate a
  clean product render (or at minimum a cropped/whitened thumbnail); then drop
  the `referrerPolicy="no-referrer"` workaround in the storefront.
- **Note:** the designer no longer uses scraped photos as a design surface for
  MODEL_3D items (they render the STL face directly - audit G1/G2); this entry
  covers catalogue/browse imagery and any future SCRAPED_UV designer surface.
