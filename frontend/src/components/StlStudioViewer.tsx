import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import * as THREE from 'three';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import api from '../lib/api';
import { Spinner, cn } from '../ui';

/**
 * Full-screen "print studio" for staff/superadmin: lays a multi-part MODEL_3D's
 * pieces FLAT on a virtual build bed (like a slicer), with a plate list, a build
 * bed toggle, per-part colour + a from→to colour swap, and orthographic view
 * presets. Purely a visualisation - colours/visibility reset on close and NOTHING
 * is persisted (the production floor prints from the stored STLs, unchanged).
 *
 * three.js objects live in a mutable ref so React state (colour, visibility,
 * selection, bed) mutates the scene imperatively without re-running the heavy
 * geometry load.
 */
export interface StudioPart {
  id: number;
  label: string;
  src: string;
  isPrimary: boolean;
}

interface Props {
  parts: StudioPart[];
  /** Owning product id - used for the ZIP export endpoint. */
  productId: number;
  open: boolean;
  onClose: () => void;
  /** Product name, shown in the studio header. */
  title?: string;
}

type ViewPreset = 'iso' | 'front' | 'back' | 'left' | 'right' | 'top';

/** Beyond this many parts, skip per-plate thumbnail renders (keeps it snappy). */
const THUMB_CAP = 60;
const THUMB_SIZE = 96;

/** Distinct default hue per plate (hex string for the colour dots). */
function defaultColor(i: number, n: number): string {
  const hex = new THREE.Color().setHSL((i / Math.max(1, n)) * 0.85, 0.55, 0.55).getHexString();
  return `#${hex}`;
}

interface SceneRefs {
  renderer: THREE.WebGLRenderer;
  scene: THREE.Scene;
  camera: THREE.PerspectiveCamera;
  controls: OrbitControls;
  meshes: Map<number, THREE.Mesh>;
  bed: THREE.Group;
  center: THREE.Vector3;
  radius: number;
  frame: number;
}

export default function StlStudioViewer({ parts, productId, open, onClose, title }: Props) {
  const mountRef = useRef<HTMLDivElement | null>(null);
  const refs = useRef<SceneRefs | null>(null);

  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [showBed, setShowBed] = useState(true);
  // focusId = the plate the camera frames / highlights (also the solo target).
  const [focusId, setFocusId] = useState<number | null>(null);
  // selected = plates shown on the bed + included in the export (default: all).
  const [selected, setSelected] = useState<Set<number>>(new Set());
  // solo = view one plate at a time (focusId), stepped with prev/next.
  const [solo, setSolo] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [colors, setColors] = useState<Record<number, string>>({});
  const [thumbs, setThumbs] = useState<Record<number, string>>({});
  const [fromColor, setFromColor] = useState('#000000');
  const [toColor, setToColor] = useState('#e11d2a');

  // Stable identity for the load effect - re-load only when the plate set changes.
  const partsKey = useMemo(() => parts.map((p) => `${p.id}:${p.src}`).join('|'), [parts]);

  // ── Camera helpers ────────────────────────────────────────────────────────
  const applyPreset = useCallback((preset: ViewPreset) => {
    const r = refs.current;
    if (!r) return;
    const { center, radius, camera, controls } = r;
    const d = radius * 2.4 || 200;
    const pos: Record<ViewPreset, [number, number, number]> = {
      iso: [center.x + d * 0.75, center.y + d * 0.65, center.z + d * 0.75],
      front: [center.x, center.y + radius * 0.3, center.z + d],
      back: [center.x, center.y + radius * 0.3, center.z - d],
      left: [center.x - d, center.y + radius * 0.3, center.z],
      right: [center.x + d, center.y + radius * 0.3, center.z],
      top: [center.x, center.y + d, center.z + 0.001],
    };
    camera.position.set(...pos[preset]);
    controls.target.copy(center);
    controls.update();
  }, []);

  // ── Build the scene + load geometries once per open/plate-set. ────────────
  useEffect(() => {
    if (!open) return;
    const mount = mountRef.current;
    if (!mount) return;

    setStatus('loading');
    let disposed = false;

    const width = mount.clientWidth || 640;
    const height = mount.clientHeight || 480;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 100000);
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(width, height);
    mount.appendChild(renderer.domElement);

    scene.add(new THREE.AmbientLight(0xffffff, 0.75));
    const key = new THREE.DirectionalLight(0xffffff, 0.85);
    key.position.set(1, 1.4, 1);
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 0.35);
    fill.position.set(-1, 0.5, -1);
    scene.add(fill);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;

    const bed = new THREE.Group();
    scene.add(bed);

    const meshes = new Map<number, THREE.Mesh>();
    refs.current = {
      renderer,
      scene,
      camera,
      controls,
      meshes,
      bed,
      center: new THREE.Vector3(),
      radius: 100,
      frame: 0,
    };

    const animate = () => {
      const r = refs.current;
      if (!r || disposed) return;
      r.frame = requestAnimationFrame(animate);
      r.controls.update();
      r.renderer.render(r.scene, r.camera);
    };

    const onResize = () => {
      const r = refs.current;
      if (!r) return;
      const w = mount.clientWidth || width;
      const h = mount.clientHeight || height;
      r.camera.aspect = w / h;
      r.camera.updateProjectionMatrix();
      r.renderer.setSize(w, h);
    };
    window.addEventListener('resize', onResize);

    // Load every plate (axios carries the Sanctum cookie), lay flat, shelf-pack.
    Promise.allSettled(parts.map((p) => api.get(p.src, { responseType: 'arraybuffer' })))
      .then((results) => {
        if (disposed) return;

        const loaded: { part: StudioPart; geometry: THREE.BufferGeometry; footprint: number; depth: number }[] = [];
        const nextThumbs: Record<number, string> = {};
        const nextColors: Record<number, string> = {};
        const allIds: number[] = [];

        // A tiny dedicated renderer for plate thumbnails (disposed after).
        const thumbRenderer =
          parts.length <= THUMB_CAP
            ? new THREE.WebGLRenderer({ antialias: true, alpha: true })
            : null;
        thumbRenderer?.setSize(THUMB_SIZE, THUMB_SIZE);

        results.forEach((res, i) => {
          if (res.status !== 'fulfilled') return;
          let geometry: THREE.BufferGeometry;
          try {
            geometry = new STLLoader().parse(res.value.data as ArrayBuffer);
          } catch {
            return; // non-STL (3mf/obj) or corrupt - skip.
          }
          geometry.computeVertexNormals();
          geometry.computeBoundingBox();

          // Centre on origin.
          const c = new THREE.Vector3();
          geometry.boundingBox!.getCenter(c);
          geometry.translate(-c.x, -c.y, -c.z);
          geometry.computeBoundingBox();

          const part = parts[i];
          const color = defaultColor(i, parts.length);
          nextColors[part.id] = color;
          allIds.push(part.id);

          // Snapshot for the plate list BEFORE reorienting/packing.
          if (thumbRenderer) {
            nextThumbs[part.id] = renderThumb(thumbRenderer, geometry, color);
          }

          // Lay flat: make the SMALLEST bounding extent the vertical (Y) axis so
          // the plate rests on its largest footprint, like a slicer.
          layFlat(geometry);
          geometry.computeBoundingBox();
          const size = new THREE.Vector3();
          geometry.boundingBox!.getSize(size);

          loaded.push({ part, geometry, footprint: Math.max(size.x, size.z), depth: size.z });
        });

        thumbRenderer?.dispose();

        if (loaded.length === 0) {
          setStatus('error');
          return;
        }

        // Shelf-pack the plates left→right, wrapping into rows, within a bed
        // width scaled to the largest plate so the arrangement reads like a bed.
        const maxFootprint = loaded.reduce((m, l) => Math.max(m, l.footprint), 0);
        const gap = maxFootprint * 0.15;
        const bedWidth = Math.max(maxFootprint, Math.sqrt(loaded.length) * (maxFootprint + gap));

        let cursorX = 0;
        let cursorZ = 0;
        let rowDepth = 0;
        const bounds = new THREE.Box3();

        for (const item of loaded) {
          const size = new THREE.Vector3();
          item.geometry.boundingBox!.getSize(size);
          if (cursorX > 0 && cursorX + size.x > bedWidth) {
            cursorX = 0;
            cursorZ += rowDepth + gap;
            rowDepth = 0;
          }
          // Sit on the bed (min Y → 0) and place at the packing cursor.
          const min = item.geometry.boundingBox!.min;
          item.geometry.translate(cursorX - min.x, -min.y, cursorZ - min.z);
          item.geometry.computeBoundingBox();

          const material = new THREE.MeshStandardMaterial({
            color: new THREE.Color(nextColors[item.part.id]),
            roughness: 0.6,
            metalness: 0.05,
          });
          const mesh = new THREE.Mesh(item.geometry, material);
          scene.add(mesh);
          meshes.set(item.part.id, mesh);
          bounds.union(item.geometry.boundingBox!);

          cursorX += size.x + gap;
          rowDepth = Math.max(rowDepth, size.z);
        }

        // Re-centre the whole packed layout over the origin so the bed grid sits
        // under it symmetrically.
        const layoutCenter = bounds.getCenter(new THREE.Vector3());
        meshes.forEach((mesh) => {
          mesh.geometry.translate(-layoutCenter.x, 0, -layoutCenter.z);
          mesh.geometry.computeBoundingBox();
        });
        bounds.translate(new THREE.Vector3(-layoutCenter.x, 0, -layoutCenter.z));

        // Build the bed: a grid + a solid plate just under y=0.
        const span = Math.max(bounds.getSize(new THREE.Vector3()).x, bounds.getSize(new THREE.Vector3()).z, maxFootprint) * 1.35;
        buildBed(bed, span);

        const center = bounds.getCenter(new THREE.Vector3());
        const radius = bounds.getSize(new THREE.Vector3()).length() / 2 || 80;
        const r = refs.current!;
        r.center = center;
        r.radius = radius;
        r.controls.maxDistance = radius * 8;
        r.controls.target.copy(center);

        setColors(nextColors);
        setSelected(new Set(allIds));
        setThumbs(nextThumbs);
        setFocusId(null);
        setSolo(false);
        setStatus('ready');

        // Initial iso framing.
        applyPreset('iso');
        animate();
      })
      .catch(() => {
        if (!disposed) setStatus('error');
      });

    return () => {
      disposed = true;
      window.removeEventListener('resize', onResize);
      const r = refs.current;
      if (r) {
        cancelAnimationFrame(r.frame);
        r.controls.dispose();
        r.meshes.forEach((m) => {
          m.geometry.dispose();
          (m.material as THREE.Material).dispose();
        });
        disposeGroup(r.bed);
        r.renderer.dispose();
        if (r.renderer.domElement.parentNode === mount) {
          mount.removeChild(r.renderer.domElement);
        }
      }
      refs.current = null;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, partsKey]);

  // ── State → scene: colours ────────────────────────────────────────────────
  useEffect(() => {
    if (status !== 'ready') return;
    const r = refs.current;
    if (!r) return;
    Object.entries(colors).forEach(([id, hex]) => {
      const mesh = r.meshes.get(Number(id));
      if (mesh) (mesh.material as THREE.MeshStandardMaterial).color.set(hex);
    });
  }, [colors, status]);

  // ── State → scene: visibility (solo shows only the focused plate) ─────────
  useEffect(() => {
    if (status !== 'ready') return;
    const r = refs.current;
    if (!r) return;
    r.meshes.forEach((mesh, id) => {
      mesh.visible = solo ? id === focusId : selected.has(id);
    });
  }, [selected, solo, focusId, status]);

  // ── State → scene: bed toggle ─────────────────────────────────────────────
  useEffect(() => {
    if (status !== 'ready') return;
    const r = refs.current;
    if (r) r.bed.visible = showBed;
  }, [showBed, status]);

  // ── State → scene: focus highlight + camera framing ───────────────────────
  useEffect(() => {
    if (status !== 'ready') return;
    const r = refs.current;
    if (!r) return;
    r.meshes.forEach((mesh, id) => {
      const mat = mesh.material as THREE.MeshStandardMaterial;
      mat.emissive.set(id === focusId ? 0x2a2a2a : 0x000000);
    });
    if (focusId != null) {
      const mesh = r.meshes.get(focusId);
      if (mesh?.geometry.boundingBox) {
        const c = mesh.geometry.boundingBox.getCenter(new THREE.Vector3());
        r.controls.target.copy(c);
        r.controls.update();
      }
    } else {
      r.controls.target.copy(r.center);
      r.controls.update();
    }
  }, [focusId, status]);

  // Escape closes; lock body scroll while open.
  useEffect(() => {
    if (!open) return;
    const { overflow } = document.body.style;
    document.body.style.overflow = 'hidden';
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => {
      document.body.style.overflow = overflow;
      window.removeEventListener('keydown', onKey);
    };
  }, [open, onClose]);

  if (!open) return null;

  const setPartColor = (id: number, hex: string) => setColors((c) => ({ ...c, [id]: hex }));

  const toggleSelected = (id: number) =>
    setSelected((s) => {
      const n = new Set(s);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });
  const selectAll = () => setSelected(new Set(parts.map((p) => p.id)));
  const selectNone = () => setSelected(new Set());

  const enterSolo = () => {
    setSolo(true);
    setFocusId((f) => f ?? parts[0]?.id ?? null);
  };
  const stepSolo = (dir: 1 | -1) => {
    if (parts.length === 0) return;
    const idx = Math.max(0, parts.findIndex((p) => p.id === focusId));
    const next = parts[(idx + dir + parts.length) % parts.length];
    setFocusId(next.id);
  };

  const exportSelected = async () => {
    if (exporting || selected.size === 0) return;
    // id 0 is the single-primary fallback plate → send [] so the backend
    // exports the product's primary model file.
    const partIds = [...selected].filter((id) => id > 0);
    setExporting(true);
    try {
      const res = await api.post(
        `/admin/products/${productId}/parts/export`,
        { part_ids: partIds },
        { responseType: 'blob' },
      );
      const url = URL.createObjectURL(res.data as Blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${(title || 'model').replace(/[^\w.-]+/g, '-').toLowerCase()}-plates.zip`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } catch {
      // Surfaced by the disabled/again-clickable button; no toast host here.
    } finally {
      setExporting(false);
    }
  };

  const applyColorSwap = () => {
    setColors((c) => {
      const next = { ...c };
      for (const [id, hex] of Object.entries(c)) {
        if (sameColor(hex, fromColor)) next[Number(id)] = toColor;
      }
      return next;
    });
  };
  const resetColors = () =>
    setColors(Object.fromEntries(parts.map((p, i) => [p.id, defaultColor(i, parts.length)])));

  return createPortal(
    <div
      className="fixed inset-0 z-modal flex flex-col bg-ink-900/95"
      role="dialog"
      aria-modal="true"
      aria-label="3D print studio"
    >
      {/* Header */}
      <div className="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-2.5">
        <span className="truncate text-sm font-medium text-white/90">
          {title ? `${title} — print studio` : 'Print studio'}
        </span>
        <button
          type="button"
          onClick={onClose}
          aria-label="Close studio"
          className="rounded-md p-1.5 text-white/70 hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
        >
          ✕
        </button>
      </div>

      <div className="relative flex min-h-0 flex-1">
        {/* Plate list */}
        <aside className="z-raised flex w-64 shrink-0 flex-col border-r border-white/10 bg-ink-900/60">
          <div className="flex items-center justify-between gap-2 px-3 py-2">
            <span className="text-2xs font-semibold uppercase tracking-wide text-white/50">
              Plates ({selected.size}/{parts.length})
            </span>
            <div className="flex items-center gap-1">
              <SidebarBtn onClick={selectAll} label="Show all plates">All</SidebarBtn>
              <SidebarBtn onClick={selectNone} label="Hide all plates">None</SidebarBtn>
              <SidebarBtn
                onClick={() => (solo ? setSolo(false) : enterSolo())}
                label={solo ? 'Exit solo view' : 'View one plate at a time'}
                active={solo}
              >
                Solo
              </SidebarBtn>
            </div>
          </div>

          {/* Solo stepper: view one plate at a time. */}
          {solo && (
            <div className="flex items-center justify-between gap-2 border-y border-white/10 bg-white/5 px-3 py-1.5">
              <SidebarBtn onClick={() => stepSolo(-1)} label="Previous plate">◀</SidebarBtn>
              <span className="min-w-0 flex-1 truncate text-center text-2xs text-white/80">
                {(() => {
                  const idx = Math.max(0, parts.findIndex((p) => p.id === focusId));
                  return `Plate ${idx + 1} of ${parts.length}`;
                })()}
              </span>
              <SidebarBtn onClick={() => stepSolo(1)} label="Next plate">▶</SidebarBtn>
            </div>
          )}

          <ul className="flex min-h-0 flex-1 flex-col overflow-y-auto">
            {parts.map((p, i) => {
              const isSelected = selected.has(p.id);
              const isFocus = focusId === p.id;
              return (
                <li key={p.id}>
                  <div
                    className={cn(
                      'flex items-center gap-2 px-2 py-2 transition-colors',
                      isFocus ? 'bg-white/15' : 'hover:bg-white/5',
                    )}
                  >
                    {/* Multi-select: include the plate on the bed + in the export. */}
                    <input
                      type="checkbox"
                      checked={isSelected}
                      onChange={() => toggleSelected(p.id)}
                      aria-label={`Select plate ${i + 1}`}
                      className="h-3.5 w-3.5 shrink-0 accent-brand-500"
                    />
                    <button
                      type="button"
                      onClick={() => {
                        setFocusId((f) => (f === p.id && !solo ? null : p.id));
                      }}
                      className="flex min-w-0 flex-1 items-center gap-2 text-left focus-visible:outline-none"
                    >
                      <span className="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded bg-white/10">
                        {thumbs[p.id] ? (
                          <img src={thumbs[p.id]} alt="" className="h-full w-full object-contain" />
                        ) : (
                          <span className="text-xs text-white/60">{i + 1}</span>
                        )}
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-xs font-medium text-white/90">
                          Plate {i + 1} — {p.label || `Part ${i + 1}`}
                        </span>
                        {p.isPrimary && <span className="text-2xs text-brand-300">Primary</span>}
                      </span>
                    </button>
                    {/* Colour dot doubles as a native colour picker. */}
                    <label
                      className="relative h-5 w-5 shrink-0 cursor-pointer rounded-full ring-1 ring-white/30"
                      style={{ backgroundColor: colors[p.id] ?? '#8a8a8a' }}
                      title="Plate colour"
                    >
                      <input
                        type="color"
                        value={colors[p.id] ?? '#8a8a8a'}
                        onChange={(e) => setPartColor(p.id, e.target.value)}
                        className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                        aria-label={`Colour for plate ${i + 1}`}
                      />
                    </label>
                  </div>
                </li>
              );
            })}
          </ul>

          {/* Export: hand the selected plates to the print floor's slicer. */}
          <div className="border-t border-white/10 p-2">
            <button
              type="button"
              onClick={() => void exportSelected()}
              disabled={selected.size === 0 || exporting}
              className="flex w-full items-center justify-center gap-2 rounded-md bg-brand-500 px-3 py-2 text-xs font-semibold text-white transition-colors hover:bg-brand-400 disabled:cursor-not-allowed disabled:opacity-40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
            >
              {exporting ? 'Preparing…' : `⬇ Export selected (${selected.size})`}
            </button>
            <p className="mt-1 text-center text-[10px] leading-tight text-white/40">
              Downloads STL plates for your slicer — the app doesn’t generate G-code.
            </p>
          </div>
        </aside>

        {/* Canvas + floating controls */}
        <div className="relative min-w-0 flex-1">
          <div ref={mountRef} className="h-full w-full" />

          {status !== 'ready' && (
            <div className="absolute inset-0 flex items-center justify-center text-sm text-white/70">
              {status === 'loading' ? (
                <Spinner size="md" label="Loading plates…" />
              ) : (
                'No printable plates to show.'
              )}
            </div>
          )}

          {/* Colors panel (top-right) */}
          <div className="absolute right-3 top-3 flex flex-col gap-2 rounded-lg border border-white/10 bg-ink-900/70 p-3 backdrop-blur-sm">
            <div className="flex items-center justify-between gap-6">
              <span className="text-2xs font-semibold uppercase tracking-wide text-white/60">Colours</span>
              <button
                type="button"
                onClick={resetColors}
                aria-label="Reset colours"
                className="rounded p-0.5 text-white/60 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
                title="Reset to default"
              >
                ↺
              </button>
            </div>
            <div className="flex items-center gap-2">
              <ColorSwatch value={fromColor} onChange={setFromColor} label="From colour" />
              <button
                type="button"
                onClick={applyColorSwap}
                aria-label="Apply colour swap"
                className="rounded px-1.5 text-white/70 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
                title="Recolour all matching plates"
              >
                →
              </button>
              <ColorSwatch value={toColor} onChange={setToColor} label="To colour" />
            </div>
          </div>

          {/* Show the Bed (bottom-left) */}
          <label className="absolute bottom-3 left-3 flex cursor-pointer items-center gap-2 rounded-full bg-ink-900/70 px-3 py-1.5 text-xs text-white/80 backdrop-blur-sm">
            <input
              type="checkbox"
              checked={showBed}
              onChange={(e) => setShowBed(e.target.checked)}
              className="h-3.5 w-3.5 accent-brand-500"
            />
            Show the Bed
          </label>

          {/* View presets (bottom-center) */}
          <div className="absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-1 rounded-full border border-white/10 bg-ink-900/70 px-2 py-1.5 backdrop-blur-sm">
            <PresetBtn label="Reset / iso" onClick={() => applyPreset('iso')}>
              ⟳
            </PresetBtn>
            <span className="mx-0.5 h-4 w-px bg-white/15" />
            <PresetBtn label="Front" onClick={() => applyPreset('front')}>
              Front
            </PresetBtn>
            <PresetBtn label="Back" onClick={() => applyPreset('back')}>
              Back
            </PresetBtn>
            <PresetBtn label="Left" onClick={() => applyPreset('left')}>
              Left
            </PresetBtn>
            <PresetBtn label="Right" onClick={() => applyPreset('right')}>
              Right
            </PresetBtn>
            <PresetBtn label="Top" onClick={() => applyPreset('top')}>
              Top
            </PresetBtn>
          </div>
        </div>
      </div>
    </div>,
    document.body,
  );
}

function ColorSwatch({ value, onChange, label }: { value: string; onChange: (v: string) => void; label: string }) {
  return (
    <label
      className="relative h-7 w-7 cursor-pointer rounded-full ring-1 ring-white/30"
      style={{ backgroundColor: value }}
      title={label}
    >
      <input
        type="color"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
        aria-label={label}
      />
    </label>
  );
}

function SidebarBtn({
  onClick,
  label,
  active,
  children,
}: {
  onClick: () => void;
  label: string;
  active?: boolean;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      title={label}
      aria-pressed={active}
      className={cn(
        'rounded px-1.5 py-0.5 text-2xs font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40',
        active ? 'bg-brand-500 text-white' : 'text-white/70 hover:bg-white/10 hover:text-white',
      )}
    >
      {children}
    </button>
  );
}

function PresetBtn({ label, onClick, children }: { label: string; onClick: () => void; children: ReactNode }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-label={label}
      title={label}
      className="rounded px-2 py-1 text-2xs font-medium text-white/75 hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/40"
    >
      {children}
    </button>
  );
}

// ── three.js helpers ────────────────────────────────────────────────────────

/** Rotate a centred geometry so its smallest extent becomes the vertical axis. */
function layFlat(geometry: THREE.BufferGeometry): void {
  const size = new THREE.Vector3();
  geometry.boundingBox!.getSize(size);
  // Already flat if Y is the smallest.
  if (size.y <= size.x && size.y <= size.z) return;
  if (size.x <= size.y && size.x <= size.z) {
    geometry.rotateZ(Math.PI / 2); // X → Y
  } else {
    geometry.rotateX(Math.PI / 2); // Z → Y
  }
  geometry.computeBoundingBox();
}

/** A grid + solid plate just under y=0, sized to `span`. */
function buildBed(bed: THREE.Group, span: number): void {
  const grid = new THREE.GridHelper(span, Math.max(4, Math.round(span / (span / 20))), 0x9aa0a6, 0x6b7075);
  (grid.material as THREE.Material).transparent = true;
  (grid.material as THREE.Material).opacity = 0.6;
  grid.position.y = 0.05;
  bed.add(grid);

  const plate = new THREE.Mesh(
    new THREE.PlaneGeometry(span, span),
    new THREE.MeshStandardMaterial({ color: 0x8b9096, roughness: 0.95, metalness: 0.0, side: THREE.DoubleSide }),
  );
  plate.rotation.x = -Math.PI / 2;
  plate.position.y = 0;
  bed.add(plate);
}

/** Render one geometry alone to a data-URL thumbnail (iso framing). */
function renderThumb(renderer: THREE.WebGLRenderer, source: THREE.BufferGeometry, color: string): string {
  const scene = new THREE.Scene();
  scene.add(new THREE.AmbientLight(0xffffff, 0.8));
  const light = new THREE.DirectionalLight(0xffffff, 0.9);
  light.position.set(1, 1.5, 1);
  scene.add(light);

  const geometry = source.clone();
  const material = new THREE.MeshStandardMaterial({ color: new THREE.Color(color), roughness: 0.6 });
  const mesh = new THREE.Mesh(geometry, material);
  scene.add(mesh);

  const box = new THREE.Box3().setFromObject(mesh);
  const center = box.getCenter(new THREE.Vector3());
  const radius = box.getSize(new THREE.Vector3()).length() / 2 || 1;
  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, radius * 100);
  camera.position.set(center.x + radius * 1.8, center.y + radius * 1.6, center.z + radius * 1.8);
  camera.lookAt(center);

  renderer.render(scene, camera);
  const url = renderer.domElement.toDataURL('image/png');

  geometry.dispose();
  material.dispose();
  return url;
}

function disposeGroup(group: THREE.Group): void {
  group.traverse((obj) => {
    const mesh = obj as THREE.Mesh;
    if (mesh.geometry) mesh.geometry.dispose();
    const mat = mesh.material as THREE.Material | THREE.Material[] | undefined;
    if (Array.isArray(mat)) mat.forEach((m) => m.dispose());
    else mat?.dispose();
  });
}

/** Loose hex equality (case-insensitive, tolerates #rgb vs #rrggbb). */
function sameColor(a: string, b: string): boolean {
  return new THREE.Color(a).getHexString() === new THREE.Color(b).getHexString();
}
