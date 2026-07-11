# OrcaSlicer + Bambu H2S — installation & setup

Wire OrcaSlicer so Gift Lab **auto-slices** model files into an H2S-ready print
file and reads real filament grams + print minutes from the slice.

## Do I need this?

**Optional.** Without it, everything still works — the floor downloads the model
(STL for Thingiverse, `.3mf` for MakerWorld) from the production queue and slices
it in their own OrcaSlicer/Bambu Studio by hand, and staff enter estimates
manually.

Wire it when you want the app to do that automatically:

| | Without OrcaSlicer | With OrcaSlicer |
|---|---|---|
| Thingiverse STL | floor slices the STL themselves | app slices → an H2S `.gcode.3mf` is saved as `production_file_ref` |
| MakerWorld `.3mf` | already print-ready (downloaded as O1S) — untouched | untouched (never re-sliced) |
| Estimates (grams/min) | staff verify by hand | read from the slice, auto-verified |

So it mainly benefits **Thingiverse / raw-STL** items and removes the manual
estimate step.

## Where does it run? (Windows)

Gift Lab calls OrcaSlicer as a **local process** — so it must run on the **same
Windows machine that runs the slicing command**, and that machine needs three
things:

1. **OrcaSlicer** installed,
2. the **Gift Lab app code** (to run `php artisan catalogue:slice-pending`),
3. access to the **database** and, if you want prod to serve the results, the
   **production S3 (Spaces) creds**.

You cannot have the Ubuntu prod server call OrcaSlicer sitting on a separate
Windows box — there's no remote invocation; it's a local `Process::run`.

### The "slicing workstation" pattern (recommended for a Ubuntu prod)

Run a Windows machine as a slicing workstation pointed at production:

- `.env` on that Windows box → **prod database** + `MODEL3D_PRODUCTION_DISK=spaces_models`
  + prod `AWS_*` creds.
- Run `php artisan catalogue:slice-pending` there.
- `measure()` slices each STL, **writes `production_file_ref` to the prod DB**, and
  **uploads the `.gcode.3mf` straight to prod Spaces** — so the Ubuntu web server
  serves it with no extra step.

Windows is the easy case: **no `xvfb`, no display juggling** — just the `.exe`.

> Alternative: install OrcaSlicer on the Ubuntu droplet itself (headless → needs
> `xvfb`). Only do this if you'd rather not run a Windows workstation; see the
> collapsed Ubuntu note in step 1.

---

## 1. Install OrcaSlicer (Windows)

1. Download the Windows installer from the releases page:
   https://github.com/SoftFever/OrcaSlicer/releases
2. Install it. The binary is typically at:
   `C:\Program Files\OrcaSlicer\orca-slicer.exe`
   (older/portable builds may be `orca-slicer-console.exe` — use whichever exists).
3. Smoke test in PowerShell — confirm the CLI runs and note its flag names:
   ```powershell
   & 'C:\Program Files\OrcaSlicer\orca-slicer.exe' --help
   ```

If `--help` prints usage, you're good. Note the exact flags it lists (step 3 —
they can differ by version).

<details>
<summary>Alternative: Ubuntu server (headless)</summary>

```bash
cd /opt
sudo wget -O OrcaSlicer.AppImage \
  https://github.com/SoftFever/OrcaSlicer/releases/download/<version>/OrcaSlicer_Linux_<version>.AppImage
sudo chmod +x OrcaSlicer.AppImage
sudo apt-get update
sudo apt-get install -y libfuse2 xvfb libgtk-3-0 libwebkit2gtk-4.1-0 libgl1-mesa-glx
xvfb-run -a /opt/OrcaSlicer.AppImage --help   # needs a virtual display
```
Then wrap the binary in `xvfb-run` (see the env note). Everything else is the same.
</details>

---

## 2. Export the Bambu H2S profile bundle

The slicer needs to know it's printing for the **H2S** with your filament/process.

1. Open OrcaSlicer (GUI, on any machine).
2. Select printer **Bambu Lab H2S**, a filament (e.g. Bambu PLA), and a process
   preset (layer height / infill you want as the default).
3. Export the config: **File → Export → Export Config Bundle** (or the
   printer/process preset's "export" ), saving a `.json` / `.orca_printer`
   bundle.
4. Copy that file onto the server, e.g. `/opt/giftlab/h2s-profile.json`. This
   path becomes `ORCA_H2S_PROFILE`.

---

## 3. ⚠️ Verify the CLI flags for YOUR version FIRST

Gift Lab calls OrcaSlicer like this (`app/Services/Model3d/SlicerService.php`,
`sliceOrca()`):

```
orca-slicer --load-settings <H2S_PROFILE> --slice 0 --export-3mf <out.gcode.3mf> <input.stl>
```

OrcaSlicer's CLI flag names **drift between versions**. Confirm yours (PowerShell):

```powershell
& 'C:\Program Files\OrcaSlicer\orca-slicer.exe' --help | Select-String -Pattern 'load-settings|slice|export-3mf|export-gcode|outputdir'
```

If the names differ (e.g. `--load` instead of `--load-settings`, or
`--export-slicedata` / `--outputdir`), edit the `$cmd` array in
`SlicerService::sliceOrca()` to match — it's the only place the flags live.

The estimates parser (`parseOrca3mf()`) reads `Metadata/slice_info.config` from
the exported `.gcode.3mf` for `weight` (grams) and `prediction` (seconds). If
your version's slice info uses different keys, adjust that method's regexes —
otherwise slicing still saves the production file, estimates just stay manual.

---

## 4. Configure Gift Lab

In the `.env` on the Windows slicing machine:

```
# Single quotes so backslashes aren't treated as escapes (dotenv rule).
ORCA_SLICER_BINARY='C:\Program Files\OrcaSlicer\orca-slicer.exe'
ORCA_H2S_PROFILE='C:\giftlab\h2s-profile.json'
# Optional: max seconds per slice (default 300).
# SLICER_TIMEOUT=600
```

> ⚠️ On Windows dotenv, a **double-quoted** path treats `\` as an escape and the
> file fails to parse. Use **single quotes** (as above), matching how
> `SLICER_BINARY` is documented in `.env.example`.

If this machine is a slicing workstation for a Ubuntu prod, also point it at the
prod database + Spaces in the same `.env`:

```
MODEL3D_DISK=spaces_models
MODEL3D_PRODUCTION_DISK=spaces_models
DO_STORAGE_FOLDER=GIFT_LAB
AWS_ACCESS_KEY_ID=...  AWS_SECRET_ACCESS_KEY=...  AWS_BUCKET=giftlab
AWS_ENDPOINT=https://sgp1.digitaloceanspaces.com  AWS_DEFAULT_REGION=sgp1
# ...and the prod DB connection (DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD).
```

Then clear the config cache so the new env is read:

```powershell
php artisan config:clear
```

<details>
<summary>Ubuntu (headless) env instead</summary>

Point `ORCA_SLICER_BINARY` at an `xvfb` wrapper (the app runs the binary
directly, so the wrapper is the clean way to attach a display):

```bash
sudo tee /opt/giftlab/orca-slice >/dev/null <<'SH'
#!/usr/bin/env bash
exec xvfb-run -a /opt/OrcaSlicer.AppImage "$@"
SH
sudo chmod +x /opt/giftlab/orca-slice
# ORCA_SLICER_BINARY=/opt/giftlab/orca-slice
```
</details>

---

## 5. Wire it in — when does slicing happen?

`SlicerService::measure()` runs automatically:

- **During a pull** (`php artisan catalogue:pull-3d …`) — new Thingiverse items
  are sliced inline.
- **On demand** for already-imported items that have a model file but no verified
  estimate:

  ```bash
  php artisan catalogue:slice-pending --limit=50
  ```

A queue worker must be running for enrichment/import jobs (`php artisan queue:work`).
Slicing is CPU-heavy — expect tens of seconds per model.

---

## 6. Verify end to end

```bash
php artisan tinker
```
```php
// Pick a Thingiverse STL product with no production file yet.
$p = App\Models\Product::whereNotNull('model_file_ref')
    ->where('model_file_ref', 'like', '%.stl')
    ->whereNull('production_file_ref')->first();

app(App\Services\Model3d\SlicerService::class)->measure($p);   // expect: true
$p->refresh();
[$p->production_file_ref, $p->est_grams, $p->est_print_minutes, $p->estimates_verified];
```

**Pass =** `production_file_ref` is a `models3d/*.gcode.3mf`, grams + minutes are
non-null, `estimates_verified = true`.

Then confirm the file is a real H2S print: download it from the production queue
(the button now reads **"Download production file (.3mf)"**) and open it in Bambu
Studio — it should show toolpaths for the H2S.

---

## Troubleshooting

- **`isConfigured()` false / nothing slices** — `ORCA_SLICER_BINARY` empty or
  `php artisan config:clear` not run after editing `.env`.
- **Exit code ≠ 0 / "rejected model file"** — check the flags (step 3). Run the
  exact command by hand: `/opt/giftlab/orca-slice --load-settings <profile>
  --slice 0 --export-3mf /tmp/out.gcode.3mf /tmp/model.stl` and read the error.
- **Slices but no estimates** (`estimates_verified` stays false) — the
  `.gcode.3mf` slice info keys don't match `parseOrca3mf()`; the production file
  is still saved, just enter grams/minutes manually (or fix the regexes).
- **Hangs / display errors on Linux** — missing `xvfb` or a GUI lib; re-run the
  smoke test in step 1.
- **Timeout on big meshes** — raise `SLICER_TIMEOUT`.
- **Don't want to run it on the server** — skip all of this; the floor slices the
  downloaded STL themselves. Nothing breaks.
