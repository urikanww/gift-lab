import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import * as THREE from 'three';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import api from '../lib/api';
import { buildDecalGeometry, renderPrintFile } from '../lib/modelDecal';
import type { PrintZone } from '../lib/printZone';

/**
 * Live 3D preview for the customer designer: the real mesh in the chosen
 * filament colour with the captured artwork projected as a decal on the
 * admin-authored print zone. Passive (auto-rotating) - it never recenters the
 * geometry so model space stays aligned with the zone. Exposes an imperative
 * handle to flatten the decal into the UV production print file on capture.
 */

interface Props {
  /** Product slug (or legacy id) used by the model stream endpoint. */
  productKey: string;
  /** Filament colour name (Black/White/Grey). */
  filamentColor: string;
  /** Admin-authored decoration zone (model-space mm). */
  zone: PrintZone;
  /** Captured artwork as a PNG data URL, or null when nothing is placed yet. */
  artworkDataUrl: string | null;
  className?: string;
}

export interface DecalPreviewHandle {
  /**
   * Build the decal from the current artwork + zone and return the
   * UV-flattened production print PNG (data URL), or null if unavailable.
   */
  generatePrintFile: () => string | null;
}

// Same filament map as modelFaceSnapshot.ts so the preview and the backdrop
// render the item in one consistent colour.
const FILAMENT_HEX: Record<string, number> = {
  Black: 0x2e2e2e,
  White: 0xf1f1ee,
  Grey: 0x9c9c9c,
};

const Model3dDecalPreview = forwardRef<DecalPreviewHandle, Props>(function Model3dDecalPreview(
  { productKey, filamentColor, zone, artworkDataUrl, className },
  ref,
) {
  const mountRef = useRef<HTMLDivElement | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

  // three.js objects kept in refs so the artwork/zone effect and the imperative
  // handle can reach the live mesh + scene without re-running the loader effect.
  const meshRef = useRef<THREE.Mesh | null>(null);
  const sceneRef = useRef<THREE.Scene | null>(null);
  const decalRef = useRef<THREE.Mesh | null>(null);
  // Last texture loaded for the decal - reused to flatten the print file.
  const textureRef = useRef<THREE.Texture | null>(null);

  // Loader + renderer lifecycle. Re-runs only on a product/colour change; the
  // artwork decal is layered on by a separate effect so placing a logo doesn't
  // re-download the STL.
  useEffect(() => {
    const mount = mountRef.current;
    if (!mount) return;

    let disposed = false;
    const width = mount.clientWidth;
    const height = mount.clientHeight || 360;

    const scene = new THREE.Scene();
    sceneRef.current = scene;
    const camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 5000);
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setSize(width, height);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    mount.appendChild(renderer.domElement);

    scene.add(new THREE.AmbientLight(0xffffff, 0.7));
    const keyLight = new THREE.DirectionalLight(0xffffff, 1.2);
    keyLight.position.set(1, 2, 3);
    scene.add(keyLight);
    const fill = new THREE.DirectionalLight(0xffffff, 0.4);
    fill.position.set(-2, -1, -2);
    scene.add(fill);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.autoRotate = true;
    controls.autoRotateSpeed = 1.5;

    let frame = 0;
    const animate = () => {
      frame = requestAnimationFrame(animate);
      controls.update();
      renderer.render(scene, camera);
    };

    new STLLoader().load(
      `${api.defaults.baseURL}/catalogue/${productKey}/model`,
      (geometry) => {
        if (disposed) return;
        // Do NOT recenter: keep model space aligned with the zone coordinates.
        geometry.computeVertexNormals();

        const color = FILAMENT_HEX[filamentColor] ?? FILAMENT_HEX.Grey;
        const material = new THREE.MeshStandardMaterial({ color, roughness: 0.55, metalness: 0.1 });
        const mesh = new THREE.Mesh(geometry, material);
        mesh.updateMatrixWorld(true);
        meshRef.current = mesh;
        scene.add(mesh);

        // Fit the camera to the bounding sphere, targeting its centre.
        geometry.computeBoundingSphere();
        const sphere = geometry.boundingSphere;
        const center = sphere?.center ?? new THREE.Vector3();
        const radius = sphere?.radius ?? 50;
        camera.position.set(center.x + radius * 1.8, center.y + radius * 1.4, center.z + radius * 1.8);
        controls.target.copy(center);
        controls.maxDistance = radius * 6;
        controls.update();

        setState('ready');
        animate();
      },
      undefined,
      () => {
        if (!disposed) setState('error');
      },
    );

    const onResize = () => {
      const w = mount.clientWidth;
      const h = mount.clientHeight || 360;
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h);
    };
    window.addEventListener('resize', onResize);

    return () => {
      disposed = true;
      cancelAnimationFrame(frame);
      window.removeEventListener('resize', onResize);
      controls.dispose();
      renderer.dispose();
      textureRef.current?.dispose();
      textureRef.current = null;
      decalRef.current = null;
      meshRef.current = null;
      sceneRef.current = null;
      scene.traverse((obj) => {
        if (obj instanceof THREE.Mesh) {
          obj.geometry.dispose();
          (Array.isArray(obj.material) ? obj.material : [obj.material]).forEach((m) => m.dispose());
        }
      });
      mount.removeChild(renderer.domElement);
    };
  }, [productKey, filamentColor]);

  // Project (or clear) the artwork decal whenever the artwork or zone changes
  // and the mesh is loaded. Disposes any prior decal + texture first.
  useEffect(() => {
    const scene = sceneRef.current;
    const mesh = meshRef.current;
    if (!scene || !mesh) return;

    // Remove and dispose the previous decal + texture.
    const prior = decalRef.current;
    if (prior) {
      scene.remove(prior);
      prior.geometry.dispose();
      (Array.isArray(prior.material) ? prior.material : [prior.material]).forEach((m) => m.dispose());
      decalRef.current = null;
    }
    textureRef.current?.dispose();
    textureRef.current = null;

    if (!artworkDataUrl) return;

    let cancelled = false;
    const texture = new THREE.TextureLoader().load(artworkDataUrl, () => {
      if (cancelled) {
        texture.dispose();
        return;
      }
      textureRef.current = texture;
      const decalGeo = buildDecalGeometry(mesh, zone);
      if (!decalGeo) return; // Zone off the mesh - skip silently.
      const decal = new THREE.Mesh(
        decalGeo,
        new THREE.MeshStandardMaterial({
          map: texture,
          transparent: true,
          polygonOffset: true,
          polygonOffsetFactor: -4,
        }),
      );
      decalRef.current = decal;
      scene.add(decal);
    });

    return () => {
      cancelled = true;
    };
  }, [artworkDataUrl, zone]);

  useImperativeHandle(
    ref,
    () => ({
      generatePrintFile: () => {
        const mesh = meshRef.current;
        const texture = textureRef.current;
        if (!mesh || !texture) return null;
        const decalGeo = buildDecalGeometry(mesh, zone);
        if (!decalGeo) return null;
        try {
          return renderPrintFile(decalGeo, texture, 2048, zone.width_mm / zone.height_mm);
        } finally {
          decalGeo.dispose();
        }
      },
    }),
    [zone],
  );

  if (state === 'error') return null;

  return (
    <div className={className}>
      <div
        ref={mountRef}
        className="h-[360px] w-full rounded-lg border border-border bg-surface"
        aria-label="Live 3D decal preview"
        role="img"
      />
      {state === 'loading' && <p className="mt-2 text-sm text-fg-muted">Loading 3D preview…</p>}
      {state === 'ready' && (
        <p className="mt-2 text-sm text-fg-subtle">Live preview · your artwork on the real model</p>
      )}
    </div>
  );
});

export default Model3dDecalPreview;
