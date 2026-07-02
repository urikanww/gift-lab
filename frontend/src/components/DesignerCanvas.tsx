import { useEffect, useRef, useState, type DragEvent, type ReactNode } from 'react';
import { Canvas, FabricImage, IText, type FabricObject } from 'fabric';
import { AnimatePresence, motion } from 'framer-motion';
import type { Customization } from '../types';
import { Button, Select, Input, Tooltip, Badge, Skeleton, cn } from '../ui';
import { Motion, fadeIn, springSoft, useReducedMotionSafe } from '../motion';

export interface CapturedArtwork {
  // Production-grade export (high multiplier) — this is what becomes the proof
  // and, once approved, the print file (spec 7). No separate re-processing.
  dataUrl: string;
  layout: object;
  customization: Customization;
}

interface DesignerCanvasProps {
  width?: number;
  height?: number;
  /**
   * Product photo shown behind the design so the buyer sees placement in
   * context. Display-only: stripped from the print export — the artwork file
   * must contain ONLY the layers to be printed, never the product photo.
   */
  backgroundUrl?: string | null;
  onCapture: (artwork: CapturedArtwork) => void;
}

const LOGO_SIZES = ['S', 'M', 'L'] as const;
type LogoSize = (typeof LOGO_SIZES)[number];

const LOGO_SIZE_LABELS: Record<LogoSize, string> = {
  S: 'Small',
  M: 'Medium',
  L: 'Large',
};

export default function DesignerCanvas({ width = 500, height = 380, backgroundUrl, onCapture }: DesignerCanvasProps) {
  const elRef = useRef<HTMLCanvasElement | null>(null);
  const stageRef = useRef<HTMLDivElement | null>(null);
  const canvasRef = useRef<Canvas | null>(null);
  const [ready, setReady] = useState(false);
  const [hasLogo, setHasLogo] = useState(false);
  const [nameText, setNameText] = useState('');
  const [logoSize, setLogoSize] = useState<LogoSize>('M');
  const [objectCount, setObjectCount] = useState(0);
  const [hasSelection, setHasSelection] = useState(false);
  const [captured, setCaptured] = useState(false);
  const animate = useReducedMotionSafe();

  // Responsive stage: the fabric canvas keeps true pixel dimensions (so pointer
  // math + export resolution stay correct), but on narrow viewports we clamp the
  // width to the available container so it never overflows 360px. Height tracks
  // the same aspect ratio as the requested width/height.
  const aspect = height / width;
  const [dims, setDims] = useState({ w: width, h: height });

  useEffect(() => {
    const el = stageRef.current;
    if (!el) return;
    const measure = () => {
      const available = el.clientWidth;
      const w = available > 0 ? Math.min(width, available) : width;
      setDims({ w: Math.round(w), h: Math.round(w * aspect) });
    };
    measure();
    const ro = new ResizeObserver(measure);
    ro.observe(el);
    return () => ro.disconnect();
  }, [width, aspect]);

  useEffect(() => {
    if (!elRef.current) return;
    // Transparent canvas: the product-photo backdrop is a DOM <img> layered
    // BEHIND this element (never drawn into it), so no CORS requirement on
    // the image host and no canvas-taint risk for toDataURL. The export is a
    // transparent PNG containing only the design layers — exactly the print
    // artwork.
    const canvas = new Canvas(elRef.current, {
      backgroundColor: '',
      preserveObjectStacking: true,
    });
    canvas.setDimensions({ width: dims.w, height: dims.h });
    canvasRef.current = canvas;

    // Keep our chrome (empty-state hint, layer count, contextual layer tools) in
    // sync with the fabric object graph without touching the render loop.
    const syncObjects = () => setObjectCount(canvas.getObjects().length);
    const syncSelection = () => setHasSelection(!!canvas.getActiveObject());
    canvas.on('object:added', syncObjects);
    canvas.on('object:removed', syncObjects);
    canvas.on('selection:created', syncSelection);
    canvas.on('selection:updated', syncSelection);
    canvas.on('selection:cleared', syncSelection);

    setReady(true);

    return () => {
      void canvas.dispose();
      canvasRef.current = null;
    };
  }, [dims.w, dims.h]);

  // Backdrop <img> load state — hide it (and fall back to the plain stage)
  // if the image 404s.
  const [backdropOk, setBackdropOk] = useState(true);
  useEffect(() => setBackdropOk(true), [backgroundUrl]);

  const sizeToScale = (size: LogoSize): number => ({ S: 0.4, M: 0.7, L: 1.0 })[size];

  const markDirty = () => setCaptured(false);

  const handleLogoUpload = async (file: File) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const reader = new FileReader();
    const dataUrl = await new Promise<string>((resolve, reject) => {
      reader.onload = () => resolve(reader.result as string);
      reader.onerror = () => reject(new Error('Could not read file.'));
      reader.readAsDataURL(file);
    });

    const img = await FabricImage.fromURL(dataUrl);
    const scale = sizeToScale(logoSize);
    img.scaleToWidth(dims.w * 0.4 * scale);
    img.set({ left: dims.w / 2, top: dims.h / 2, originX: 'center', originY: 'center' });
    canvas.add(img);
    canvas.setActiveObject(img);
    canvas.requestRenderAll();
    setHasLogo(true);
    markDirty();
  };

  const applyNameText = () => {
    const canvas = canvasRef.current;
    if (!canvas || !nameText) return;
    const text = new IText(nameText, {
      left: dims.w / 2,
      top: dims.h - 60,
      originX: 'center',
      fontSize: 28,
      fill: '#111111',
      fontFamily: 'Helvetica, Arial, sans-serif',
    });
    canvas.add(text);
    canvas.setActiveObject(text);
    canvas.requestRenderAll();
    markDirty();
  };

  const withActive = (fn: (canvas: Canvas, active: FabricObject) => void) => {
    const canvas = canvasRef.current;
    const active = canvas?.getActiveObject();
    if (!canvas || !active) return;
    fn(canvas, active);
    canvas.requestRenderAll();
    markDirty();
  };

  const deleteSelected = () =>
    withActive((canvas, active) => {
      canvas.remove(active);
      canvas.discardActiveObject();
      if (!canvas.getObjects().some((o) => o instanceof FabricImage)) setHasLogo(false);
    });

  const bringForward = () => withActive((canvas, active) => canvas.bringObjectForward(active));
  const sendBackward = () => withActive((canvas, active) => canvas.sendObjectBackwards(active));

  const capture = () => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    // Export at a fixed print resolution (4× the requested design width) regardless
    // of the responsive on-screen size, so a phone-sized preview still produces the
    // same high-res print file as a desktop one.
    const multiplier = (width * 4) / dims.w;
    // Transparent-background PNG of only the design layers — the product
    // photo lives in a DOM layer behind the canvas and is never exported
    // (spec 7: the artwork IS the production file, not a product mockup).
    const dataUrl = canvas.toDataURL({ format: 'png', multiplier });
    const layout = canvas.toJSON();
    onCapture({
      dataUrl,
      layout,
      customization: {
        logo_size: hasLogo ? logoSize : null,
        name_text: nameText || null,
        artwork_ref: dataUrl,
      },
    });
    setCaptured(true);
  };

  const isEmpty = ready && objectCount === 0;

  const [dragOver, setDragOver] = useState(false);

  const handleDrop = (e: DragEvent) => {
    e.preventDefault();
    setDragOver(false);
    const file = Array.from(e.dataTransfer.files).find((f) =>
      ['image/png', 'image/jpeg'].includes(f.type),
    );
    if (file) void handleLogoUpload(file);
  };

  return (
    <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
      {/* Canvas stage */}
      <div ref={stageRef} className="min-w-0 flex-1">
        <div
          className={cn(
            'group relative mx-auto max-w-full overflow-hidden rounded-lg border',
            'bg-[repeating-conic-gradient(var(--color-surface-2)_0%_25%,var(--color-surface)_0%_50%)] bg-[length:20px_20px]',
            'shadow-card',
            dragOver ? 'border-primary ring-2 ring-ring' : 'border-border',
          )}
          style={{ width: dims.w }}
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={handleDrop}
        >
          {/* Product-photo backdrop: DOM layer under the fabric canvas — never
              drawn into it (no CORS requirement, no export taint) */}
          {backgroundUrl && backdropOk && (
            <img
              src={backgroundUrl}
              alt=""
              aria-hidden="true"
              referrerPolicy="no-referrer"
              onError={() => setBackdropOk(false)}
              className="pointer-events-none absolute inset-0 h-full w-full object-cover"
            />
          )}

          {/* fabric mounts here — DO NOT alter the element wiring */}
          <canvas ref={elRef} className="relative block touch-none" aria-label="Design canvas" />

          {/* Loading skeleton while fabric initialises */}
          {!ready && (
            <div className="absolute inset-0" style={{ width: dims.w, height: dims.h }} aria-hidden="true">
              <Skeleton className="h-full w-full" />
            </div>
          )}

          {/* Empty-state hint overlaid on a blank canvas (non-blocking) */}
          <AnimatePresence>
            {isEmpty && (
              <Motion
                variants={fadeIn}
                initial="hidden"
                animate="visible"
                exit="exit"
                className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 px-6 text-center"
              >
                <span
                  className="flex h-11 w-11 items-center justify-center rounded-full bg-surface/90 text-fg-subtle shadow-sm"
                  aria-hidden="true"
                >
                  <PlusIcon />
                </span>
                <p className={cn('text-sm font-medium', backgroundUrl ? 'rounded bg-surface/80 px-2 py-0.5 text-fg' : 'text-fg-muted')}>
                  {backgroundUrl ? 'Place your design on the product' : 'Start your design'}
                </p>
                <p className={cn('text-xs', backgroundUrl ? 'rounded bg-surface/80 px-2 py-0.5 text-fg-muted' : 'text-fg-subtle')}>
                  Drag &amp; drop a logo here, upload one, or add text.
                </p>
              </Motion>
            )}
          </AnimatePresence>

          {/* Selection-aware layer toolbar, floats over the stage */}
          <AnimatePresence>
            {hasSelection && (
              <motion.div
                initial={animate ? { opacity: 0, y: 6 } : false}
                animate={{ opacity: 1, y: 0 }}
                exit={animate ? { opacity: 0, y: 6 } : undefined}
                transition={springSoft}
                className="absolute left-1/2 top-3 flex -translate-x-1/2 items-center gap-1 rounded-full border border-border bg-surface/95 p-1 shadow-md backdrop-blur"
              >
                <IconButton label="Bring forward" onClick={bringForward}>
                  <LayerUpIcon />
                </IconButton>
                <IconButton label="Send backward" onClick={sendBackward}>
                  <LayerDownIcon />
                </IconButton>
                <span className="mx-0.5 h-5 w-px bg-border" aria-hidden="true" />
                <IconButton label="Delete selected" tone="danger" onClick={deleteSelected}>
                  <TrashIcon />
                </IconButton>
              </motion.div>
            )}
          </AnimatePresence>
        </div>

        <div className="mt-2 flex items-center justify-center gap-2">
          <Badge tone="neutral" size="sm">
            {objectCount} {objectCount === 1 ? 'element' : 'elements'}
          </Badge>
          {hasSelection && (
            <span className="text-xs text-fg-subtle">Tip: drag handles to resize, or use the layer tools above.</span>
          )}
        </div>
      </div>

      {/* Control panel */}
      <div className="w-full shrink-0 rounded-lg border border-border bg-surface p-4 shadow-card lg:w-72">
        <h3 className="mb-4 font-display text-lg">Design tools</h3>

        <div className="flex flex-col gap-5">
          {/* Logo group */}
          <fieldset className="flex flex-col gap-3">
            <legend className="mb-1 text-2xs font-semibold uppercase tracking-wide text-fg-subtle">Logo</legend>
            <Select
              label="Logo size"
              value={logoSize}
              onChange={(e) => setLogoSize(e.target.value as LogoSize)}
              options={LOGO_SIZES.map((s) => ({ value: s, label: LOGO_SIZE_LABELS[s] }))}
              hint="Applied when you upload."
            />
            <div className="flex flex-col gap-1.5">
              <span className="text-sm font-medium text-fg">Upload logo</span>
              <label
                className={cn(
                  'flex cursor-pointer flex-col items-center justify-center gap-1.5 rounded-md border border-dashed',
                  'border-border-strong bg-surface-2/50 px-3 py-4 text-center transition-colors duration-fast ease-standard',
                  'hover:border-primary hover:bg-surface-2 focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-1 focus-within:ring-offset-bg',
                )}
              >
                <UploadIcon />
                <span className="text-sm font-medium text-fg">Choose an image</span>
                <span className="text-2xs text-fg-subtle">PNG or JPEG</span>
                <input
                  type="file"
                  accept="image/png,image/jpeg"
                  className="sr-only"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) void handleLogoUpload(file);
                    e.target.value = '';
                  }}
                />
              </label>
            </div>
          </fieldset>

          {/* Text group */}
          <fieldset className="flex flex-col gap-3">
            <legend className="mb-1 text-2xs font-semibold uppercase tracking-wide text-fg-subtle">Text</legend>
            <Input
              label="Name / text"
              type="text"
              value={nameText}
              maxLength={255}
              placeholder="e.g. Acme Pte Ltd"
              onChange={(e) => setNameText(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && nameText) {
                  e.preventDefault();
                  applyNameText();
                }
              }}
            />
            <Button variant="outline" size="sm" onClick={applyNameText} disabled={!nameText} leadingIcon={<TextIcon />}>
              Add text
            </Button>
          </fieldset>

          <div className="h-px bg-border" aria-hidden="true" />

          {/* Capture */}
          <Button
            variant={captured ? 'secondary' : 'primary'}
            onClick={capture}
            disabled={!ready}
            fullWidth
            leadingIcon={captured ? <CheckIcon /> : undefined}
          >
            {captured ? 'Design saved' : 'Use this design'}
          </Button>
          <p className="text-xs text-fg-subtle" aria-live="polite">
            {captured
              ? 'Captured at print resolution. Re-save after any edits.'
              : 'Save your layout to attach it to the order.'}
          </p>
        </div>
      </div>
    </div>
  );
}

/* ------------------------------------------------------------------ */
/* Icon button + tooltip: tactile, a11y-labelled control primitive.    */
/* ------------------------------------------------------------------ */

function IconButton({
  label,
  onClick,
  tone = 'default',
  children,
}: {
  label: string;
  onClick: () => void;
  tone?: 'default' | 'danger';
  children: ReactNode;
}) {
  const animate = useReducedMotionSafe();
  return (
    <Tooltip content={label}>
      <motion.button
        type="button"
        onClick={onClick}
        aria-label={label}
        whileTap={animate ? { scale: 0.9 } : undefined}
        transition={springSoft}
        className={cn(
          'flex h-8 w-8 items-center justify-center rounded-full text-fg transition-colors duration-fast ease-standard',
          'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-bg',
          tone === 'danger' ? 'hover:bg-danger-bg hover:text-danger' : 'hover:bg-surface-2',
        )}
      >
        {children}
      </motion.button>
    </Tooltip>
  );
}

/* ------------------------------------------------------------------ */
/* Inline icons (currentColor, decorative — labels live on the button) */
/* ------------------------------------------------------------------ */

const iconProps = {
  width: 16,
  height: 16,
  viewBox: '0 0 20 20',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.7,
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  'aria-hidden': true,
};

function PlusIcon() {
  return (
    <svg {...iconProps} width={20} height={20}>
      <path d="M10 4v12M4 10h12" />
    </svg>
  );
}

function UploadIcon() {
  return (
    <svg {...iconProps} width={20} height={20} className="text-fg-subtle">
      <path d="M10 13V4M6.5 7.5 10 4l3.5 3.5M4 14v1.5A1.5 1.5 0 0 0 5.5 17h9a1.5 1.5 0 0 0 1.5-1.5V14" />
    </svg>
  );
}

function TextIcon() {
  return (
    <svg {...iconProps}>
      <path d="M4 6V5h12v1M10 5v11M8 16h4" />
    </svg>
  );
}

function CheckIcon() {
  return (
    <svg {...iconProps}>
      <path d="M4 10.5 8 14l8-8.5" />
    </svg>
  );
}

function TrashIcon() {
  return (
    <svg {...iconProps}>
      <path d="M4 6h12M8 6V4.5A.5.5 0 0 1 8.5 4h3a.5.5 0 0 1 .5.5V6m2 0v9.5A1.5 1.5 0 0 1 12.5 17h-5A1.5 1.5 0 0 1 6 15.5V6" />
    </svg>
  );
}

function LayerUpIcon() {
  return (
    <svg {...iconProps}>
      <path d="M10 4 4 7l6 3 6-3-6-3ZM4 13l6 3 6-3" />
    </svg>
  );
}

function LayerDownIcon() {
  return (
    <svg {...iconProps}>
      <path d="M4 7l6 3 6-3M10 13 4 16l6 3 6-3-6-3Z" />
    </svg>
  );
}
