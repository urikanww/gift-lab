import { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { cn } from '../ui';
import { loadModelWithProgress } from '../lib/loadModelWithProgress';
import { parseStlInWorker } from '../lib/parseStl';
import ModelLoadProgress, { type ModelLoadPhase } from './ModelLoadProgress';

/**
 * Plain STL viewer for staff/superadmin inspection - renders any mesh served by
 * an authenticated API path (e.g. a single model part). Bytes are fetched via
 * axios so the Sanctum cookie rides along, then parsed client-side (STLLoader's
 * own loader wouldn't carry the session). Neutral material, orbit + auto-rotate,
 * fitted to the geometry's bounding sphere. No decal/zone logic - see
 * Model3dDecalPreview for the customer designer's decorated preview.
 */
interface Props {
  /**
   * API path(s) (relative to the axios baseURL) that stream STL bytes. Pass an
   * array to assemble several parts into one scene - each part gets a distinct
   * hue and the camera fits the combined bounds. Non-STL parts (3mf/obj) that
   * fail to parse are skipped, not fatal.
   */
  src: string | string[];
  className?: string;
}

/** Distinct hue per part in an assembly; neutral grey for a lone mesh. */
function colorFor(i: number, n: number): number {
  if (n <= 1) return 0x8a8a8a;
  return new THREE.Color().setHSL((i / n) * 0.85, 0.55, 0.55).getHex();
}

export default function StlModelViewer({ src, className }: Props) {
  const mountRef = useRef<HTMLDivElement | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [progress, setProgress] = useState<{ phase: ModelLoadPhase; loaded: number; total: number | null }>({
    phase: 'downloading',
    loaded: 0,
    total: null,
  });
  // Stable dep: an inline array prop would re-run the effect every render.
  const srcKey = Array.isArray(src) ? src.join('|') : src;

  useEffect(() => {
    const mount = mountRef.current;
    if (!mount) return;

    let disposed = false;
    let frame = 0;

    const width = mount.clientWidth || 320;
    const height = mount.clientHeight || 240;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 5000);
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setSize(width, height);
    mount.appendChild(renderer.domElement);

    scene.add(new THREE.AmbientLight(0xffffff, 0.7));
    const key = new THREE.DirectionalLight(0xffffff, 0.9);
    key.position.set(1, 1, 1);
    scene.add(key);
    const fill = new THREE.DirectionalLight(0xffffff, 0.4);
    fill.position.set(-1, -0.5, -1);
    scene.add(fill);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.autoRotate = true;
    controls.autoRotateSpeed = 1.5;

    const animate = () => {
      frame = requestAnimationFrame(animate);
      controls.update();
      renderer.render(scene, camera);
    };

    const onResize = () => {
      const w = mount.clientWidth || width;
      const h = mount.clientHeight || height;
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h);
    };
    window.addEventListener('resize', onResize);

    // Fetch each part through axios (carries the Sanctum cookie) with progress,
    // then parse in a worker. allSettled so one bad/non-STL part (worker parse
    // rejects) never kills the view. Download progress is aggregated across all
    // parts; the phase flips to 'processing' once every part has downloaded.
    const srcs = Array.isArray(src) ? src : [src];
    const loadeds = new Array<number>(srcs.length).fill(0);
    const totals = new Array<number | null>(srcs.length).fill(null);
    let remaining = srcs.length;
    const report = () => {
      if (disposed) return;
      const known = totals.every((t) => t != null);
      setProgress({
        phase: 'downloading',
        loaded: loadeds.reduce((a, b) => a + b, 0),
        total: known ? (totals as number[]).reduce((a, b) => a + b, 0) : null,
      });
    };

    Promise.allSettled(
      srcs.map(async (s, i) => {
        const buffer = await loadModelWithProgress(s, (loaded, total) => {
          loadeds[i] = loaded;
          totals[i] = total;
          report();
        });
        remaining -= 1;
        if (remaining === 0 && !disposed) setProgress((p) => ({ ...p, phase: 'processing' }));
        return parseStlInWorker(buffer);
      }),
    )
      .then((results) => {
        if (disposed) return;

        // Keep every part that loaded + parsed (skip non-STL/corrupt), with its
        // index for a stable colour. The worker already computed vertex normals
        // + a bounding box.
        const geos: { geometry: THREE.BufferGeometry; i: number }[] = [];
        results.forEach((r, i) => {
          if (r.status !== 'fulfilled') return;
          const geometry = r.value;
          geometry.computeBoundingBox();
          geos.push({ geometry, i });
        });

        if (geos.length === 0) {
          setState('error');
          return;
        }

        // Multi-part parts are often each exported centred on the origin, so a
        // naive overlay stacks them. Lay them out in a grid (spaced by the
        // largest part) so every piece is visible without overlap. A single
        // mesh is shown in place.
        const multi = geos.length > 1;
        const cols = Math.ceil(Math.sqrt(geos.length));
        const rows = Math.ceil(geos.length / cols);
        let cell = 0;
        if (multi) {
          for (const { geometry } of geos) {
            const s = new THREE.Vector3();
            geometry.boundingBox!.getSize(s);
            cell = Math.max(cell, s.x, s.y, s.z);
          }
          cell *= 1.3; // gap between parts
        }

        const box = new THREE.Box3();
        geos.forEach(({ geometry, i }, k) => {
          if (multi) {
            // Centre each part, then move it to its grid cell.
            const c = new THREE.Vector3();
            geometry.boundingBox!.getCenter(c);
            geometry.translate(-c.x, -c.y, -c.z);
            const col = k % cols;
            const row = Math.floor(k / cols);
            geometry.translate((col - (cols - 1) / 2) * cell, 0, (row - (rows - 1) / 2) * cell);
            geometry.computeBoundingBox();
          }
          const material = new THREE.MeshStandardMaterial({
            color: colorFor(i, srcs.length),
            roughness: 0.55,
            metalness: 0.1,
          });
          scene.add(new THREE.Mesh(geometry, material));
          if (geometry.boundingBox) box.union(geometry.boundingBox);
        });

        const center = box.getCenter(new THREE.Vector3());
        const radius = box.getSize(new THREE.Vector3()).length() / 2 || 50;
        camera.position.set(center.x + radius * 1.8, center.y + radius * 1.4, center.z + radius * 1.8);
        camera.lookAt(center);
        controls.target.copy(center);
        controls.maxDistance = radius * 6;
        controls.update();

        setState('ready');
        animate();
      })
      .catch((err) => {
        if (disposed) return;
        setState('error');
        console.error('[StlModelViewer] load failed', err);
      });

    return () => {
      disposed = true;
      cancelAnimationFrame(frame);
      window.removeEventListener('resize', onResize);
      controls.dispose();
      renderer.dispose();
      if (renderer.domElement.parentNode === mount) {
        mount.removeChild(renderer.domElement);
      }
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [srcKey]);

  return (
    <div className={cn('relative overflow-hidden rounded-lg bg-surface-2', className)}>
      <div ref={mountRef} className="h-full w-full" />
      {state !== 'ready' && (
        <div className="absolute inset-0 flex items-center justify-center text-sm text-fg-muted">
          {state === 'loading' ? (
            <ModelLoadProgress phase={progress.phase} loaded={progress.loaded} total={progress.total} />
          ) : (
            'Model unavailable'
          )}
        </div>
      )}
    </div>
  );
}
