import { useEffect, useState } from 'react';
import { fetchBrandKit, saveBrandKit } from '../lib/brandKit';
import { apiError } from '../lib/api';
import { Badge, Button, Card, Skeleton, useToast, cn } from '../ui';
import { Motion, fadeInUp } from '../motion';

const MAX_COLORS = 8;

/**
 * Buyer-managed company brand kit: a saved logo + brand colours that the
 * designer applies in one click across any product.
 */
export default function BrandKitPage() {
  const { toast } = useToast();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [logo, setLogo] = useState<string | null>(null);
  const [colors, setColors] = useState<string[]>([]);
  const [draft, setDraft] = useState('#2563eb');

  useEffect(() => {
    let active = true;
    fetchBrandKit()
      .then((kit) => {
        if (!active) return;
        setLogo(kit.logo);
        setColors(kit.colors);
      })
      .catch(() => {
        /* no kit yet — start blank */
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, []);

  const onLogoFile = (file: File) => {
    const reader = new FileReader();
    reader.onload = () => setLogo(reader.result as string);
    reader.readAsDataURL(file);
  };

  const addColor = () => {
    if (colors.length >= MAX_COLORS || colors.includes(draft)) return;
    setColors((c) => [...c, draft]);
  };

  const save = async () => {
    setSaving(true);
    try {
      const kit = await saveBrandKit({ colors, logo });
      setLogo(kit.logo);
      setColors(kit.colors);
      toast({ title: 'Brand kit saved', tone: 'success' });
    } catch (err) {
      toast({ title: 'Could not save', description: apiError(err), tone: 'danger' });
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="mx-auto flex w-full max-w-2xl flex-col gap-4">
        <Skeleton height="2rem" width="12rem" />
        <Skeleton height="10rem" />
      </div>
    );
  }

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mx-auto flex w-full max-w-2xl flex-col gap-6">
      <header className="flex flex-col gap-2">
        <h1 className="font-display text-3xl leading-tight text-fg sm:text-4xl">Brand kit</h1>
        <p className="text-fg-muted">
          Save your logo and colours once — apply them to any product in the design studio with one click.
        </p>
      </header>

      {/* Logo */}
      <Card padding="lg" className="flex flex-col gap-4">
        <h2 className="font-display text-lg text-fg">Logo</h2>
        <div className="flex items-center gap-4">
          <div className="flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border bg-[repeating-conic-gradient(var(--color-surface-2)_0%_25%,var(--color-surface)_0%_50%)] bg-[length:16px_16px]">
            {logo ? (
              <img src={logo} alt="Brand logo" className="max-h-full max-w-full object-contain" />
            ) : (
              <span className="text-2xs text-fg-subtle">No logo</span>
            )}
          </div>
          <div className="flex flex-col gap-2">
            <label className="inline-flex cursor-pointer items-center gap-2 rounded-md border border-border-strong bg-surface-2/50 px-3 py-2 text-sm font-medium text-fg hover:border-primary hover:bg-surface-2 focus-within:ring-2 focus-within:ring-ring">
              Choose image (PNG/JPEG)
              <input
                type="file"
                accept="image/png,image/jpeg"
                className="sr-only"
                onChange={(e) => {
                  const f = e.target.files?.[0];
                  if (f) onLogoFile(f);
                  e.target.value = '';
                }}
              />
            </label>
            {logo && (
              <Button variant="ghost" size="sm" onClick={() => setLogo(null)}>
                Remove logo
              </Button>
            )}
          </div>
        </div>
      </Card>

      {/* Colours */}
      <Card padding="lg" className="flex flex-col gap-4">
        <h2 className="font-display text-lg text-fg">Brand colours</h2>
        <div className="flex flex-wrap items-center gap-2">
          {colors.length === 0 && <span className="text-sm text-fg-muted">No colours yet.</span>}
          {colors.map((c) => (
            <span key={c} className="inline-flex items-center gap-1.5 rounded-full border border-border py-1 pl-1.5 pr-2 text-sm">
              <span className="h-4 w-4 rounded-full border border-border" style={{ backgroundColor: c }} aria-hidden="true" />
              <span className="font-mono text-xs text-fg-muted">{c}</span>
              <button
                type="button"
                aria-label={`Remove ${c}`}
                onClick={() => setColors((list) => list.filter((x) => x !== c))}
                className="text-fg-subtle hover:text-danger focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              >
                ×
              </button>
            </span>
          ))}
        </div>
        <div className="flex items-center gap-2">
          <input
            type="color"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            aria-label="Pick a colour"
            className="h-9 w-12 cursor-pointer rounded border border-border bg-surface p-0.5"
          />
          <Button
            variant="outline"
            size="sm"
            onClick={addColor}
            disabled={colors.length >= MAX_COLORS || colors.includes(draft)}
          >
            Add colour
          </Button>
          <Badge tone="neutral" size="sm">
            {colors.length}/{MAX_COLORS}
          </Badge>
        </div>
      </Card>

      <div className={cn('flex justify-end')}>
        <Button size="lg" onClick={() => void save()} loading={saving} disabled={saving}>
          Save brand kit
        </Button>
      </div>
    </Motion>
  );
}
