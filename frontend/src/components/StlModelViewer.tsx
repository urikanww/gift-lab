import { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import api from '../lib/api';
import { Spinner, cn } from '../ui';

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

    // Fetch each part through axios (carries the Sanctum cookie), then .parse().
    // allSettled + per-part try/catch so one bad/non-STL part never kills the view.
    const srcs = Array.isArray(src) ? src : [src];
    Promise.allSettled(srcs.map((s) => api.get(s, { responseType: 'arraybuffer' })))
      .then((results) => {
        if (disposed) return;
        const box = new THREE.Box3();
        let added = 0;
        results.forEach((r, i) => {
          if (r.status !== 'fulfilled') return;
          let geometry: THREE.BufferGeometry;
          try {
            geometry = new STLLoader().parse(r.value.data as ArrayBuffer);
          } catch {
            return; // non-STL (3mf/obj) or corrupt - skip this part.
          }
          geometry.computeVertexNormals();
          const material = new THREE.MeshStandardMaterial({
            color: colorFor(i, srcs.length),
            roughness: 0.55,
            metalness: 0.1,
          });
          scene.add(new THREE.Mesh(geometry, material));
          geometry.computeBoundingBox();
          if (geometry.boundingBox) box.union(geometry.boundingBox);
          added++;
        });

        if (added === 0) {
          setState('error');
          return;
        }

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
          {state === 'loading' ? <Spinner size="md" label="Loading model…" /> : 'Model unavailable'}
        </div>
      )}
    </div>
  );
}
