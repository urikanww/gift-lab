import { useEffect, useRef, useState, type DragEvent, type KeyboardEvent, type ReactNode } from 'react';
import { Canvas, FabricImage, Textbox, type FabricObject } from 'fabric';
import { AnimatePresence, motion } from 'framer-motion';
import type { Customization } from '../types';
import { Button, Select, Tooltip, Badge, Skeleton, cn, useOptionalToast } from '../ui';
import { Motion, fadeIn, springSoft, useReducedMotionSafe } from '../motion';

export interface CapturedArtwork {
  // Production-grade export (high multiplier) - this is what becomes the proof
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
   * context. Display-only: stripped from the print export - the artwork file
   * must contain ONLY the layers to be printed, never the product photo.
   */
  backgroundUrl?: string | null;
  onCapture: (artwork: CapturedArtwork) => void;
  /**
   * Fires whenever the logo/text layers change or the size band changes, so
   * the page can price the design live (before "Use this design"). Size bands
   * are a price tier; text adds the per-unit personalisation fee (audit D9).
   */
  onLogoChange?: (info: { hasLogo: boolean; size: LogoSize; hasText: boolean }) => void;
  /** Company brand kit: a saved logo (data URL) + colour swatches to one-click apply. */
  brandLogo?: string | null;
  brandColors?: string[];
  /**
   * Physical size of the full canvas footprint in product mm (known for
   * MODEL_3D face renders). When present, the captured layout also carries
   * real-mm placement so production needs no pixel guesswork (audit C12/G2).
   */
  canvasMm?: { width: number; height: number } | null;
}

const LOGO_SIZES = ['S', 'M', 'L'] as const;
type LogoSize = (typeof LOGO_SIZES)[number];

const LOGO_SIZE_LABELS: Record<LogoSize, string> = {
  S: 'Small',
  M: 'Medium',
  L: 'Large',
};

// Each size is a HARD footprint band: logo width as a fraction of the canvas
// width, clamped to [min, max]. Contiguous, non-overlapping - picking Medium
// locks the logo between Medium's bounds; the resize handles cannot cross them.
const LOGO_BANDS: Record<LogoSize, { min: number; max: number }> = {
  S: { min: 0.14, max: 0.26 },
  M: { min: 0.26, max: 0.4 },
  L: { min: 0.4, max: 0.56 },
};

const bandMid = (size: LogoSize): number => (LOGO_BANDS[size].min + LOGO_BANDS[size].max) / 2;

// Placement precision: while dragging, a logo whose centre lands within this
// many pixels of a canvas centre/third snaps onto it, and a guide line shows.
const SNAP_PX = 7;
// Upload guardrails (audit C1/C2/C3): mirror the server's 10 MB / PNG+JPEG
// rules client-side so a bad file fails loudly at selection, not silently.
const MAX_UPLOAD_MB = 10;
const ACCEPTED_UPLOAD_TYPES = ['image/png', 'image/jpeg'];
// The printable UV zone as an inset fraction of the stage. Placement outside it
// is not producible on the flat face, so the frame keeps buyer + floor honest.
const PRINT_INSET = 0.1;

export default function DesignerCanvas({
  width = 500,
  height = 380,
  backgroundUrl,
  onCapture,
  onLogoChange,
  brandLogo,
  brandColors,
  canvasMm = null,
}: DesignerCanvasProps) {
  const elRef = useRef<HTMLCanvasElement | null>(null);
  const stageRef = useRef<HTMLDivElement | null>(null);
  const canvasRef = useRef<Canvas | null>(null);
  const [ready, setReady] = useState(false);
  const [hasLogo, setHasLogo] = useState(false);
  // Name/text personalisation layers (audit D9) - combinable with the logo.
  const [hasText, setHasText] = useState(false);
  const hasTextRef = useRef(false);
  const [logoSize, setLogoSize] = useState<LogoSize>('M');
  // Read the live band inside fabric event handlers (which close over stale
  // state) without re-registering listeners on every size change.
  const logoSizeRef = useRef<LogoSize>('M');
  const [objectCount, setObjectCount] = useState(0);
  const [hasSelection, setHasSelection] = useState(false);
  // Single-stack undo for destructive edits (delete / replace / transform).
  // Each entry is a full canvas JSON snapshot taken JUST BEFORE the change, so
  // Ctrl/Cmd+Z restores the prior layout. Capped to keep memory bounded; this
  // stays inside the flat 2D-over-photo model (no real 3D decal history).
  const undoStackRef = useRef<string[]>([]);
  const [canUndo, setCanUndo] = useState(false);
  // Active alignment guides (centre/thirds) shown while a logo snaps mid-drag.
  const [guides, setGuides] = useState<{ x: number | null; y: number | null }>({ x: null, y: null });
  // Print-zone clamp, defined inside the canvas-lifecycle effect (it closes
  // over the live dims) but needed by add/band/nudge handlers outside it.
  const clampToPrintAreaRef = useRef<((obj: FabricObject) => void) | null>(null);
  const [captured, setCaptured] = useState(false);
  const animate = useReducedMotionSafe();
  const { toast } = useOptionalToast();
  // Latest upload problem, mirrored inline next to the upload control (with a
  // toast for immediacy) so failures are never silent (audit C1/C2/C3).
  const [uploadIssue, setUploadIssue] = useState<{ message: string; tone: 'error' | 'warning' } | null>(null);

  const reportUploadIssue = (message: string, tone: 'error' | 'warning') => {
    setUploadIssue({ message, tone });
    toast({
      title: tone === 'error' ? 'Upload not accepted' : 'Print-quality warning',
      description: message,
      tone: tone === 'error' ? 'danger' : 'warning',
    });
  };

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
    // transparent PNG containing only the design layers - exactly the print
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

    // Enforce the size band: a logo may never be scaled outside the selected
    // band's [min, max] width. Clamp live during the drag AND on release, so
    // the S/M/L label always reflects the actual footprint the buyer pays for.
    const clampToBand = (obj: FabricObject) => {
      const band = LOGO_BANDS[logoSizeRef.current];
      const minW = dims.w * band.min;
      const maxW = dims.w * band.max;
      const w = obj.getScaledWidth();
      const factor = w > maxW ? maxW / w : w < minW ? minW / w : 1;
      if (factor !== 1) {
        obj.scaleX = (obj.scaleX ?? 1) * factor;
        obj.scaleY = (obj.scaleY ?? 1) * factor;
        obj.setCoords();
      }
    };
    const onScaling = (e: { target?: FabricObject }) => {
      if (e.target instanceof FabricImage) clampToBand(e.target);
      if (e.target) clampToPrintArea(e.target);
    };
    const onModified = (e: { target?: FabricObject }) => {
      if (e.target instanceof FabricImage) clampToBand(e.target);
      if (e.target) {
        clampToPrintArea(e.target);
        canvas.requestRenderAll();
      }
      // Drag/scale finished - retract the live guides.
      setGuides({ x: null, y: null });
    };
    const onRotating = (e: { target?: FabricObject }) => {
      if (e.target) clampToPrintArea(e.target);
    };
    // Keep every design element inside the producible print zone (audit C7/G5):
    // the at-add clamp alone let a drag push the logo outside the dashed frame
    // - or fully off-canvas - and that uncorrected placement became the print
    // file (C9). Applied on move, scale, rotate and nudge.
    const clampToPrintArea = (obj: FabricObject) => {
      const minX = dims.w * PRINT_INSET;
      const maxX = dims.w * (1 - PRINT_INSET);
      const minY = dims.h * PRINT_INSET;
      const maxY = dims.h * (1 - PRINT_INSET);
      obj.setCoords();
      const br = obj.getBoundingRect();
      let dx = 0;
      let dy = 0;
      if (br.left < minX) dx = minX - br.left;
      else if (br.left + br.width > maxX) dx = maxX - (br.left + br.width);
      if (br.top < minY) dy = minY - br.top;
      else if (br.top + br.height > maxY) dy = maxY - (br.top + br.height);
      if (dx !== 0 || dy !== 0) {
        obj.set({ left: (obj.left ?? 0) + dx, top: (obj.top ?? 0) + dy });
        obj.setCoords();
      }
    };
    clampToPrintAreaRef.current = clampToPrintArea;

    // Snap the logo's centre to the stage centre + thirds so placement is
    // precise and repeatable, and surface a guide line while it's locked on.
    // Logos are added with a centre origin, so left/top ARE the centre coords.
    const onMoving = (e: { target?: FabricObject }) => {
      const obj = e.target;
      if (!obj) return;
      const snapsX = [dims.w / 2, dims.w / 3, (dims.w * 2) / 3];
      const snapsY = [dims.h / 2, dims.h / 3, (dims.h * 2) / 3];
      let gx: number | null = null;
      let gy: number | null = null;
      for (const s of snapsX) {
        if (Math.abs((obj.left ?? 0) - s) < SNAP_PX) {
          obj.set('left', s);
          gx = s;
          break;
        }
      }
      for (const s of snapsY) {
        if (Math.abs((obj.top ?? 0) - s) < SNAP_PX) {
          obj.set('top', s);
          gy = s;
          break;
        }
      }
      obj.setCoords();
      clampToPrintArea(obj);
      setGuides({ x: gx, y: gy });
    };
    // Snapshot ONCE at the start of a drag/resize gesture (not on every
    // incremental move/scale event) so Ctrl/Cmd+Z reverses the whole transform.
    const onBeforeTransform = () => pushHistory();
    canvas.on('object:scaling', onScaling);
    canvas.on('object:moving', onMoving);
    canvas.on('object:rotating', onRotating);
    canvas.on('object:modified', onModified);
    canvas.on('before:transform', onBeforeTransform);

    setReady(true);

    return () => {
      void canvas.dispose();
      canvasRef.current = null;
    };
  }, [dims.w, dims.h]);

  // Backdrop <img> load state - hide it (and fall back to the plain stage)
  // if the image 404s.
  const [backdropOk, setBackdropOk] = useState(true);
  useEffect(() => setBackdropOk(true), [backgroundUrl]);

  const markDirty = () => setCaptured(false);

  const MAX_UNDO = 30;

  // Snapshot the current layout onto the undo stack BEFORE a destructive edit,
  // so Ctrl/Cmd+Z can restore it. No-op if the canvas isn't ready.
  const pushHistory = () => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const snapshot = JSON.stringify(canvas.toJSON());
    const stack = undoStackRef.current;
    stack.push(snapshot);
    if (stack.length > MAX_UNDO) stack.shift();
    setCanUndo(true);
  };

  // Reverse the last destructive edit: reload the most recent snapshot and
  // re-derive our chrome (logo presence + pricing band) from it.
  const undo = () => {
    const canvas = canvasRef.current;
    const snapshot = undoStackRef.current.pop();
    if (!canvas || snapshot === undefined) return;
    void canvas.loadFromJSON(snapshot).then(() => {
      canvas.discardActiveObject();
      // Restored objects are fresh instances - re-enable the rotate handle
      // and the enlarged touch hit-area.
      canvas.getObjects().forEach((o) => {
        o.setControlsVisibility({ mtr: true });
        o.set({ touchCornerSize: 44 });
      });
      canvas.requestRenderAll();
      const stillHasLogo = canvas.getObjects().some((o) => o instanceof FabricImage);
      const stillHasText = canvas.getObjects().some((o) => o instanceof Textbox);
      setHasLogo(stillHasLogo);
      setHasText(stillHasText);
      hasTextRef.current = stillHasText;
      onLogoChange?.({ hasLogo: stillHasLogo, size: logoSizeRef.current, hasText: stillHasText });
      setObjectCount(canvas.getObjects().length);
      setHasSelection(false);
      setCanUndo(undoStackRef.current.length > 0);
      markDirty();
    });
  };

  // Switch the size band. Re-fit an existing logo to the new band's midpoint so
  // the change is visible immediately, and price the new band live.
  const applyBand = (size: LogoSize) => {
    setLogoSize(size);
    logoSizeRef.current = size;
    const canvas = canvasRef.current;
    const img = canvas?.getObjects().find((o): o is FabricImage => o instanceof FabricImage);
    if (canvas && img) {
      // Re-fitting the logo to a new band resizes it - snapshot so it's undoable.
      pushHistory();
      img.scaleToWidth(dims.w * bandMid(size));
      img.setCoords();
      clampToPrintAreaRef.current?.(img);
      canvas.requestRenderAll();
      markDirty();
    }
    onLogoChange?.({ hasLogo, size, hasText: hasTextRef.current });
  };

  // Add a logo from a data URL (fresh upload OR the saved brand-kit logo -
  // both are data URLs, so neither hits a CORS wall on fabric load).
  const addLogoFromDataUrl = async (dataUrl: string) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    // Snapshot before the add so Ctrl/Cmd+Z reverses this placement (and, when
    // replacing an existing logo, restores the previous one).
    pushHistory();
    const img = await FabricImage.fromURL(dataUrl);
    // Land the logo at the current band's midpoint; the clamp keeps any later
    // resize inside the band.
    img.scaleToWidth(dims.w * bandMid(logoSize));
    img.set({ left: dims.w / 2, top: dims.h / 2, originX: 'center', originY: 'center' });
    // Rotation is a supported transform (audit C6): expose the rotate handle
    // and rotate around the centre so the band/zone clamps stay predictable.
    img.setControlsVisibility({ mtr: true });
    // 44px touch hit-area on the corner handles (audit C5-mobile) - the
    // rendered handle stays small; only the touch target grows.
    img.set({ centeredRotation: true, touchCornerSize: 44 });
    canvas.add(img);
    canvas.setActiveObject(img);
    canvas.requestRenderAll();
    setHasLogo(true);
    markDirty();
    onLogoChange?.({ hasLogo: true, size: logoSize, hasText: hasTextRef.current });
  };

  // Add a name/text personalisation layer (audit D9). Combinable with the
  // logo; the rendered glyphs ship inside the same print export, and the
  // source string is recorded on the customization for pricing + production.
  const addText = (content: string, color: string) => {
    const canvas = canvasRef.current;
    const trimmed = content.trim();
    if (!canvas || trimmed === '') return;
    pushHistory();
    const text = new Textbox(trimmed, {
      left: dims.w / 2,
      top: dims.h * 0.7,
      originX: 'center',
      originY: 'center',
      width: dims.w * 0.5,
      fontSize: Math.round(dims.w / 14),
      fontFamily: 'Arial, sans-serif',
      fill: color,
      textAlign: 'center',
      editable: false,
    });
    text.setControlsVisibility({ mtr: true });
    text.set({ touchCornerSize: 44 });
    canvas.add(text);
    canvas.setActiveObject(text);
    clampToPrintAreaRef.current?.(text);
    canvas.requestRenderAll();
    setHasText(true);
    hasTextRef.current = true;
    markDirty();
    onLogoChange?.({ hasLogo, size: logoSize, hasText: true });
  };

  // Specific, actionable message for an unsupported file type (audit C1) -
  // never a silent drop. SVG is deliberately excluded server-side (stored-XSS),
  // PDF is unsupported; both get their own copy.
  const uploadTypeError = (file: File): string | null => {
    if (ACCEPTED_UPLOAD_TYPES.includes(file.type)) return null;
    const name = file.name.toLowerCase();
    if (file.type === 'image/svg+xml' || name.endsWith('.svg')) {
      return 'SVG isn’t supported - export your logo as PNG or JPG and try again.';
    }
    if (file.type === 'application/pdf' || name.endsWith('.pdf')) {
      return 'PDF isn’t supported - export your artwork as PNG or JPG and try again.';
    }
    const ext = name.includes('.') ? `.${name.split('.').pop()}` : 'That file type';
    return `${ext} isn’t supported - upload a PNG or JPG.`;
  };

  // Pixel size of a just-read image, for the print-quality check (audit C3).
  const imageDimensions = (dataUrl: string): Promise<{ w: number; h: number }> =>
    new Promise((resolve, reject) => {
      const probe = new Image();
      probe.onload = () => resolve({ w: probe.naturalWidth, h: probe.naturalHeight });
      probe.onerror = () => reject(new Error('Could not read image.'));
      probe.src = dataUrl;
    });

  const handleLogoUpload = async (file: File) => {
    // Type gate (C1): reject with copy that says what to do instead.
    const typeError = uploadTypeError(file);
    if (typeError) {
      reportUploadIssue(typeError, 'error');
      return;
    }

    // Size gate (C2): the server caps uploads at 10 MB but only ever sees the
    // canvas re-export, so an oversized original must be stopped here.
    if (file.size > MAX_UPLOAD_MB * 1024 * 1024) {
      const mb = (file.size / (1024 * 1024)).toFixed(1);
      reportUploadIssue(
        `This file is ${mb} MB - the limit is ${MAX_UPLOAD_MB} MB. Resize or compress it and try again.`,
        'error',
      );
      return;
    }

    let dataUrl: string;
    try {
      dataUrl = await new Promise<string>((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = () => reject(new Error('Could not read file.'));
        reader.readAsDataURL(file);
      });
    } catch {
      reportUploadIssue('We couldn’t read that file. Try a different PNG or JPG.', 'error');
      return;
    }

    // Print-quality gate (C3): warn - without blocking - when the source has
    // fewer pixels than the selected band needs at print resolution (the
    // export is width×4 px; the band floor is the smallest printed footprint).
    try {
      const { w, h } = await imageDimensions(dataUrl);
      const minPrintPx = Math.round(LOGO_BANDS[logoSizeRef.current].min * width * 4);
      if (w < minPrintPx) {
        reportUploadIssue(
          `Heads up: this image is ${w}×${h}px - at the ${LOGO_SIZE_LABELS[logoSizeRef.current]} size it may print blurry. For crisp results use an image at least ${minPrintPx}px wide.`,
          'warning',
        );
      } else {
        setUploadIssue(null);
      }
    } catch {
      // Non-fatal: if the probe fails, fabric's own load will surface it.
      setUploadIssue(null);
    }

    await addLogoFromDataUrl(dataUrl);
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
      pushHistory();
      canvas.remove(active);
      canvas.discardActiveObject();
      const stillHasLogo = canvas.getObjects().some((o) => o instanceof FabricImage);
      const stillHasText = canvas.getObjects().some((o) => o instanceof Textbox);
      if (stillHasLogo !== hasLogo || stillHasText !== hasTextRef.current) {
        setHasLogo(stillHasLogo);
        setHasText(stillHasText);
        hasTextRef.current = stillHasText;
        onLogoChange?.({ hasLogo: stillHasLogo, size: logoSize, hasText: stillHasText });
      }
    });

  const bringForward = () => withActive((canvas, active) => canvas.bringObjectForward(active));
  const sendBackward = () => withActive((canvas, active) => canvas.sendObjectBackwards(active));

  // True while a run of arrow-key nudges is in flight, so we snapshot ONCE at
  // the start of the run (Ctrl/Cmd+Z then reverses the whole nudge sequence)
  // rather than flooding the undo stack with one entry per pixel.
  const nudgingRef = useRef(false);

  // Don't hijack keys while the user is typing in a form field (the stage is
  // focusable and this handler is also reachable when focus is inside it).
  const isTypingTarget = (t: EventTarget | null): boolean => {
    const el = t as HTMLElement | null;
    if (!el) return false;
    const tag = el.tagName;
    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
  };

  // Stage key bindings: arrow-nudge, Delete/Backspace to remove the selection,
  // and Ctrl/Cmd+Z to undo the last destructive edit.
  const onStageKeyDown = (e: KeyboardEvent<HTMLDivElement>) => {
    if (isTypingTarget(e.target)) return;

    // Undo - Ctrl/Cmd+Z (never with Shift, which is conventionally redo).
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && (e.key === 'z' || e.key === 'Z')) {
      e.preventDefault();
      undo();
      return;
    }

    // Delete selected object.
    if (e.key === 'Delete' || e.key === 'Backspace') {
      const canvas = canvasRef.current;
      if (!canvas?.getActiveObject()) return;
      e.preventDefault();
      deleteSelected();
      return;
    }

    // Arrow-key nudge for pixel-precise placement (Shift = 10px leaps). Only
    // acts when an object is selected, so it never hijacks page scroll keys.
    const arrows = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'];
    if (!arrows.includes(e.key)) return;
    const canvas = canvasRef.current;
    const active = canvas?.getActiveObject();
    if (!canvas || !active) return;
    e.preventDefault();
    if (!nudgingRef.current) {
      pushHistory();
      nudgingRef.current = true;
    }
    const step = e.shiftKey ? 10 : 1;
    const dx = e.key === 'ArrowLeft' ? -step : e.key === 'ArrowRight' ? step : 0;
    const dy = e.key === 'ArrowUp' ? -step : e.key === 'ArrowDown' ? step : 0;
    active.set({ left: (active.left ?? 0) + dx, top: (active.top ?? 0) + dy });
    active.setCoords();
    clampToPrintAreaRef.current?.(active);
    canvas.requestRenderAll();
    markDirty();
  };

  // End the nudge run when the arrow keys are released, so the next run starts
  // a fresh undo snapshot.
  const onStageKeyUp = (e: KeyboardEvent<HTMLDivElement>) => {
    if (e.key.startsWith('Arrow')) nudgingRef.current = false;
  };

  const capture = () => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    // Belt-and-braces zone check (audit C7/C9): every transform path clamps,
    // but a capture must never ship an element outside the producible zone.
    const minX = dims.w * PRINT_INSET;
    const maxX = dims.w * (1 - PRINT_INSET);
    const minY = dims.h * PRINT_INSET;
    const maxY = dims.h * (1 - PRINT_INSET);
    const tolerance = 1; // sub-pixel rounding from scale/rotate
    const outOfZone = canvas.getObjects().some((o) => {
      o.setCoords();
      const br = o.getBoundingRect();
      return (
        br.left < minX - tolerance ||
        br.top < minY - tolerance ||
        br.left + br.width > maxX + tolerance ||
        br.top + br.height > maxY + tolerance
      );
    });
    if (outOfZone) {
      reportUploadIssue(
        'Part of your design sits outside the print area. Move it inside the dashed frame, then save again.',
        'error',
      );
      return;
    }

    // Export at a fixed print resolution (4× the requested design width) regardless
    // of the responsive on-screen size, so a phone-sized preview still produces the
    // same high-res print file as a desktop one.
    const exportWidthPx = width * 4;
    const multiplier = exportWidthPx / dims.w;
    // Transparent-background PNG of only the design layers - the product
    // photo lives in a DOM layer behind the canvas and is never exported
    // (spec 7: the artwork IS the production file, not a product mockup).
    const dataUrl = canvas.toDataURL({ format: 'png', multiplier });
    // Machine-readable placement record (audit C12), persisted alongside the
    // artwork ref so production can read position/size/rotation without
    // opening the PNG. Coordinates are normalised fractions of the canvas so
    // they survive any display size; export scale gives the pixel mapping.
    const layout = {
      canvas: {
        display_width_px: dims.w,
        display_height_px: dims.h,
        export_width_px: exportWidthPx,
        export_height_px: Math.round(dims.h * multiplier),
        print_inset_fraction: PRINT_INSET,
        // Physical size the canvas footprint represents on the product, when
        // known (MODEL_3D face renders carry real mm from the STL geometry).
        width_mm: canvasMm ? +canvasMm.width.toFixed(2) : null,
        height_mm: canvasMm ? +canvasMm.height.toFixed(2) : null,
      },
      logo_size_band: hasLogo ? logoSize : null,
      band_bounds_fraction: hasLogo ? LOGO_BANDS[logoSize] : null,
      objects: canvas.getObjects().map((o) => {
        o.setCoords();
        const br = o.getBoundingRect();
        const cxFraction = (o.left ?? 0) / dims.w;
        const cyFraction = (o.top ?? 0) / dims.h;
        const wFraction = br.width / dims.w;
        const hFraction = br.height / dims.h;
        return {
          type: o.type,
          text: o instanceof Textbox ? o.text : undefined,
          center_x_fraction: +cxFraction.toFixed(4),
          center_y_fraction: +cyFraction.toFixed(4),
          width_fraction: +wFraction.toFixed(4),
          height_fraction: +hFraction.toFixed(4),
          angle_deg: +((o.angle ?? 0).toFixed(2)),
          placement_mm: canvasMm
            ? {
                center_x_mm: +(cxFraction * canvasMm.width).toFixed(2),
                center_y_mm: +(cyFraction * canvasMm.height).toFixed(2),
                width_mm: +(wFraction * canvasMm.width).toFixed(2),
                height_mm: +(hFraction * canvasMm.height).toFixed(2),
              }
            : null,
        };
      }),
    };
    // Recorded source text (audit D9): the rendered glyphs are in the export;
    // this is the machine-readable content for pricing + the production record.
    const textContent = canvas
      .getObjects()
      .filter((o): o is Textbox => o instanceof Textbox)
      .map((o) => o.text)
      .join('\n');

    onCapture({
      dataUrl,
      layout,
      customization: {
        logo_size: hasLogo ? logoSize : null,
        artwork_ref: dataUrl,
        layout,
        text: textContent || null,
      },
    });
    setCaptured(true);
  };

  const isEmpty = ready && objectCount === 0;

  const [dragOver, setDragOver] = useState(false);

  const handleDrop = (e: DragEvent) => {
    e.preventDefault();
    setDragOver(false);
    // Validate the dropped file through the SAME gate as the picker - a
    // rejected drop must explain itself, never vanish (audit C1/C2).
    const file = e.dataTransfer.files[0];
    if (file) void handleLogoUpload(file);
  };

  return (
    <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
      {/* Canvas stage */}
      <div ref={stageRef} className="min-w-0 flex-1">
        <div
          tabIndex={0}
          onKeyDown={onStageKeyDown}
          onKeyUp={onStageKeyUp}
          className={cn(
            'group relative mx-auto max-w-full overflow-hidden rounded-lg border',
            'bg-[repeating-conic-gradient(var(--color-surface-2)_0%_25%,var(--color-surface)_0%_50%)] bg-[length:20px_20px]',
            'shadow-card focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
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
          {/* Product-photo backdrop: DOM layer under the fabric canvas - never
              drawn into it (no CORS requirement, no export taint). */}
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

          {/* Fabric isolation boundary (C17): fabric v6 re-parents the
              <canvas> node into its own .canvas-container div at init, which
              breaks React's insertBefore for any conditional sibling anchored
              on the canvas (NotFoundError crash when the async 3D face render
              mounted the backdrop img). This wrapper div is React-owned and
              contains ONLY the canvas, so fabric's DOM surgery stays invisible
              to sibling reconciliation. Never render anything else inside it. */}
          <div className="relative">
            {/* fabric mounts here - DO NOT alter the element wiring */}
            <canvas ref={elRef} className="relative block touch-none" aria-label="Design canvas" />
          </div>

          {/* Producible print zone: the flat UV face the logo must sit within.
              DOM overlay only - never drawn into the canvas, so it can't leak
              into the exported print artwork. */}
          {ready && (
            <div
              className="pointer-events-none absolute z-raised rounded-sm border border-dashed border-primary/40"
              style={{ inset: `${PRINT_INSET * 100}%` }}
              aria-hidden="true"
            >
              <span className="absolute left-1 top-1 rounded bg-surface/85 px-1.5 py-0.5 text-2xs font-medium text-fg-subtle">
                Print area
              </span>
            </div>
          )}

          {/* Live alignment guides - appear only while a logo snaps mid-drag. */}
          {guides.x !== null && (
            <div
              className="pointer-events-none absolute inset-y-0 z-raised w-px bg-primary/70"
              style={{ left: guides.x }}
              aria-hidden="true"
            />
          )}
          {guides.y !== null && (
            <div
              className="pointer-events-none absolute inset-x-0 z-raised h-px bg-primary/70"
              style={{ top: guides.y }}
              aria-hidden="true"
            />
          )}

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
                  Drag &amp; drop a logo, then place it inside the print area.
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
            <span className="text-xs text-fg-subtle">
              Tip: drag to move (snaps to guides), arrow keys to nudge, handles to resize · Delete to
              remove · Ctrl+Z to undo.
            </span>
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
              onChange={(e) => applyBand(e.target.value as LogoSize)}
              options={LOGO_SIZES.map((s) => ({ value: s, label: LOGO_SIZE_LABELS[s] }))}
              hint="Sets a fixed size band - resizing stays within it, and larger bands cost more."
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
                <span className="text-2xs text-fg-subtle">PNG or JPEG, up to {MAX_UPLOAD_MB} MB</span>
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
              {uploadIssue && (
                <p
                  className={cn(
                    'rounded-md border px-2.5 py-1.5 text-xs',
                    uploadIssue.tone === 'error'
                      ? 'border-danger/30 bg-danger-bg text-danger'
                      : 'border-warning/30 bg-warning-bg text-warning',
                  )}
                  role="alert"
                >
                  {uploadIssue.message}
                </p>
              )}
            </div>

            {/* Brand kit: one-click apply the company's saved logo + show its
                colour swatches for reference. */}
            {(brandLogo || (brandColors && brandColors.length > 0)) && (
              <div className="flex flex-col gap-2 rounded-md border border-brand-100 bg-brand-50/50 p-2.5">
                <span className="text-2xs font-semibold uppercase tracking-wide text-fg-subtle">Brand kit</span>
                {brandLogo && (
                  <Button variant="outline" size="sm" onClick={() => void addLogoFromDataUrl(brandLogo)}>
                    Apply brand logo
                  </Button>
                )}
                {brandColors && brandColors.length > 0 && (
                  <div className="flex flex-wrap items-center gap-1.5">
                    {brandColors.map((c) => (
                      <span
                        key={c}
                        title={c}
                        className="h-5 w-5 rounded-full border border-border"
                        style={{ backgroundColor: c }}
                        aria-label={`Brand colour ${c}`}
                      />
                    ))}
                  </div>
                )}
              </div>
            )}
          </fieldset>

          <div className="h-px bg-border" aria-hidden="true" />

          {/* Text personalisation group (audit D9) - combinable with the logo,
              priced per unit via the configured personalisation fee. */}
          <fieldset className="flex flex-col gap-2">
            <legend className="mb-1 text-2xs font-semibold uppercase tracking-wide text-fg-subtle">
              Name / text
            </legend>
            <TextTool onAdd={addText} brandColors={brandColors} />
            {hasText && (
              <p className="text-2xs text-fg-subtle">
                Text added - drag to position it inside the print area.
              </p>
            )}
          </fieldset>

          <div className="h-px bg-border" aria-hidden="true" />

          {/* Undo - mouse-accessible mirror of Ctrl/Cmd+Z; reverses the last
              destructive edit (delete / replace / transform). */}
          <Button variant="outline" size="sm" onClick={undo} disabled={!canUndo} fullWidth>
            Undo last change
          </Button>

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
/* Text personalisation input (audit D9).                              */
/* ------------------------------------------------------------------ */

const TEXT_COLORS = ['#1a1a1a', '#ffffff'];

function TextTool({
  onAdd,
  brandColors,
}: {
  onAdd: (content: string, color: string) => void;
  brandColors?: string[];
}) {
  const [draft, setDraft] = useState('');
  const colors = [...TEXT_COLORS, ...(brandColors ?? [])];
  const [color, setColor] = useState(colors[0]);

  const add = () => {
    onAdd(draft, color);
    setDraft('');
  };

  return (
    <div className="flex flex-col gap-2">
      <input
        type="text"
        value={draft}
        maxLength={100}
        placeholder="e.g. Team NexGen 2026"
        aria-label="Personalisation text"
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            add();
          }
        }}
        className="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-fg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
      />
      <div className="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-label="Text colour">
        {colors.map((c) => (
          <button
            key={c}
            type="button"
            role="radio"
            aria-checked={color === c}
            aria-label={`Text colour ${c}`}
            title={c}
            onClick={() => setColor(c)}
            className={cn(
              'h-6 w-6 rounded-full border',
              color === c ? 'border-primary ring-2 ring-ring' : 'border-border',
            )}
            style={{ backgroundColor: c }}
          />
        ))}
      </div>
      <Button variant="outline" size="sm" onClick={add} disabled={draft.trim() === ''}>
        Add text
      </Button>
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
          'flex h-10 w-10 items-center justify-center rounded-full text-fg transition-colors duration-fast ease-standard',
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
/* Inline icons (currentColor, decorative - labels live on the button) */
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
