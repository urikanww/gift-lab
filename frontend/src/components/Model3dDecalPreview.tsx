import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import * as THREE from 'three';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import api from '../lib/api';
import { Button } from '../ui';
import { buildDecalGeometry, renderPrintFile } from '../lib/modelDecal';
import { worldToZoneFraction } from '../lib/zoneMapping';
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
  /** Enable drag-to-move on the flat printable face. */
  interactive?: boolean;
  /** Live design canvas wrapped as a CanvasTexture; null → use artworkDataUrl. */
  liveCanvas?: HTMLCanvasElement | null;
  /** Bumped by the parent on any placement change → flag the texture needsUpdate. */
  dirtyTick?: number;
  /** Reports the zone fraction (0..1) as the buyer drags the logo. */
  onDragPlacement?: (fu: number, fv: number) => void;
  /** Reports the accumulated logo angle in degrees as the buyer nudges rotation. */
  onRotate?: (deg: number) => void;
  /** The true logo angle (deg) from the parent. The rotate control reflects this
   *  so a rotation made on the 2D pad keeps the control in sync (no stale jump). */
  angle?: number;
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
  { productKey, filamentColor, zone, artworkDataUrl, className, interactive, liveCanvas, dirtyTick, onDragPlacement, onRotate, angle },
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
  const controlsRef = useRef<OrbitControls | null>(null);

  // Latest-value refs so the once-run loader effect's pointer handlers read the
  // current props (they must NOT re-run and re-download the STL).
  const interactiveRef = useRef(interactive);
  const onDragPlacementRef = useRef(onDragPlacement);
  const zoneRef = useRef(zone);
  useEffect(() => {
    interactiveRef.current = interactive;
    onDragPlacementRef.current = onDragPlacement;
    zoneRef.current = zone;
    // Auto-spin fights placement, so disable it while interactive.
    if (controlsRef.current) controlsRef.current.autoRotate = !interactive;
  }, [interactive, onDragPlacement, zone]);

  // A decal is only worth building when there is something to show. Toggling this
  // (or the mesh/zone) rebuilds geometry; swapping artwork content does not.
  const hasSource = (interactive === true && !!liveCanvas) || !!artworkDataUrl;

  // Loader + renderer lifecycle. Re-runs only on a product/colour change; the
  // artwork decal is layered on by a separate effect so placing a logo doesn't
  // re-download the STL.
  useEffect(() => {
    const mount = mountRef.current;
    if (!mount) return;

    // Force a real loading→ready transition on every (re)run. Without this, a
    // filament-colour change re-runs this effect while state is already 'ready',
    // so setState('ready') below is a no-op and the geometry/texture effects
    // (gated on state === 'ready') never re-fire, leaving the decal missing.
    setState('loading');

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
    // Auto-spin fights drag-to-place, so gate it on the (latest) interactive prop.
    controls.autoRotate = !interactiveRef.current;
    controls.autoRotateSpeed = 1.5;
    controlsRef.current = controls;

    let dragging = false;
    let frame = 0;
    const animate = () => {
      frame = requestAnimationFrame(animate);
      controls.update();
      // While dragging a live decal, re-upload the moving canvas each frame.
      if (dragging && textureRef.current) textureRef.current.needsUpdate = true;
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

    // ---- Drag-to-move on the flat face (with an off-zone guard) ----
    const raycaster = new THREE.Raycaster();
    const ndc = new THREE.Vector2();
    // Accept only hits whose world face-normal is ~parallel (≤25°) to the zone
    // normal; a hit on a side face returns null so OrbitControls can orbit.
    const NORMAL_DOT_MIN = Math.cos((25 * Math.PI) / 180);
    const toFraction = (e: PointerEvent): { fu: number; fv: number } | null => {
      const mesh = meshRef.current;
      const zoneNow = zoneRef.current;
      if (!mesh) return null;
      const rect = renderer.domElement.getBoundingClientRect();
      ndc.set(
        ((e.clientX - rect.left) / rect.width) * 2 - 1,
        -((e.clientY - rect.top) / rect.height) * 2 + 1,
      );
      raycaster.setFromCamera(ndc, camera);
      const hit = raycaster.intersectObject(mesh, false)[0];
      if (!hit || !hit.face) return null;
      const nm = new THREE.Matrix3().getNormalMatrix(mesh.matrixWorld);
      const worldNormal = hit.face.normal.clone().applyMatrix3(nm).normalize();
      const zoneNormal = new THREE.Vector3(...zoneNow.normal).normalize();
      if (worldNormal.dot(zoneNormal) < NORMAL_DOT_MIN) return null; // off the printable face
      return worldToZoneFraction(hit.point.clone(), zoneNow);
    };
    const onDown = (e: PointerEvent) => {
      if (!interactiveRef.current) return;
      const f = toFraction(e);
      if (!f) return; // off the face → let OrbitControls orbit
      dragging = true;
      controls.enableRotate = false;
      onDragPlacementRef.current?.(f.fu, f.fv);
    };
    const onMove = (e: PointerEvent) => {
      if (!dragging) return;
      const f = toFraction(e);
      if (f) onDragPlacementRef.current?.(f.fu, f.fv);
    };
    const onUp = () => {
      dragging = false;
      controls.enableRotate = true;
    };
    renderer.domElement.addEventListener('pointerdown', onDown);
    renderer.domElement.addEventListener('pointermove', onMove);
    renderer.domElement.addEventListener('pointerup', onUp);
    renderer.domElement.addEventListener('pointerleave', onUp);

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
      renderer.domElement.removeEventListener('pointerdown', onDown);
      renderer.domElement.removeEventListener('pointermove', onMove);
      renderer.domElement.removeEventListener('pointerup', onUp);
      renderer.domElement.removeEventListener('pointerleave', onUp);
      controls.enableRotate = true;
      controls.dispose();
      controlsRef.current = null;
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

  // Build the decal GEOMETRY once per (mesh, zone) - and add/remove it as the
  // source toggles. A texture/content change (below) does NOT rebuild geometry,
  // it only re-uploads the map, which keeps drag-to-move cheap.
  useEffect(() => {
    const scene = sceneRef.current;
    const mesh = meshRef.current;
    if (!scene || !mesh || !hasSource) return;

    const decalGeo = buildDecalGeometry(mesh, zone);
    if (!decalGeo) return; // Zone off the mesh - skip silently.
    const material = new THREE.MeshStandardMaterial({
      map: textureRef.current, // reuse the current texture; the texture effect keeps it in sync
      transparent: true,
      polygonOffset: true,
      polygonOffsetFactor: -4,
    });
    const decal = new THREE.Mesh(decalGeo, material);
    decalRef.current = decal;
    scene.add(decal);

    return () => {
      scene.remove(decal);
      decalGeo.dispose();
      material.dispose();
      if (decalRef.current === decal) decalRef.current = null;
    };
  }, [state, zone, hasSource]);

  // Bind the decal's texture SOURCE. Live path: wrap the design canvas as a
  // CanvasTexture (content updates flag needsUpdate, no PNG re-encode). Otherwise
  // load the captured artwork data URL (display-only). Swapping the source here
  // never rebuilds the decal geometry - it just re-points material.map.
  useEffect(() => {
    if (state !== 'ready') return;

    // Assign a texture to the live decal material (if it exists yet).
    const applyMap = (tex: THREE.Texture | null) => {
      const decal = decalRef.current;
      if (decal && !Array.isArray(decal.material)) {
        (decal.material as THREE.MeshStandardMaterial).map = tex;
        decal.material.needsUpdate = true;
      }
    };

    textureRef.current?.dispose();
    textureRef.current = null;

    if (interactive && liveCanvas) {
      const texture = new THREE.CanvasTexture(liveCanvas);
      textureRef.current = texture;
      applyMap(texture);
      return () => {
        texture.dispose();
        if (textureRef.current === texture) textureRef.current = null;
      };
    }

    if (artworkDataUrl) {
      let cancelled = false;
      const texture = new THREE.TextureLoader().load(artworkDataUrl, () => {
        if (!cancelled) texture.needsUpdate = true;
      });
      textureRef.current = texture;
      applyMap(texture);
      return () => {
        cancelled = true;
        texture.dispose();
        if (textureRef.current === texture) textureRef.current = null;
      };
    }

    applyMap(null);
    return;
  }, [state, interactive, liveCanvas, artworkDataUrl]);

  // A placement change bumps dirtyTick: re-upload the live CanvasTexture once.
  useEffect(() => {
    const tex = textureRef.current;
    if (tex instanceof THREE.CanvasTexture) tex.needsUpdate = true;
  }, [dirtyTick]);

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

  // The control is a CONTROLLED reflection of the true angle prop: a rotation
  // made on the 2D pad flows in via `angle`, so the control never goes stale and
  // clicking it can't jump the logo. Each nudge computes off the incoming angle.
  const currentAngle = angle ?? 0;
  const bump = (delta: number) => {
    const next = (((currentAngle + delta) % 360) + 360) % 360;
    onRotate?.(next);
  };
  const resetAngle = () => {
    onRotate?.(0);
  };

  if (state === 'error') return null;

  // Show the rotate control only while interactive and there is a logo to rotate.
  const showRotate = state === 'ready' && interactive === true && (!!liveCanvas || !!artworkDataUrl);

  return (
    <div className={className}>
      {/* Relative wrapper so the rotate overlay can sit over the canvas mount.
          The overlay is a SIBLING of the WebGL canvas (which three.js appends
          inside mountRef), so its pointer events never reach the drag handlers. */}
      <div className="relative">
        <div
          ref={mountRef}
          className="h-[360px] w-full rounded-lg border border-border bg-surface"
          aria-label="Live 3D decal preview"
          role="img"
        />
        {showRotate && (
          <div className="absolute bottom-2 right-2 flex items-center gap-1 rounded-full border border-border bg-surface/90 px-1.5 py-1 shadow-md backdrop-blur">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 px-0"
              aria-label="Rotate left"
              onClick={() => bump(-15)}
            >
              ↺
            </Button>
            <button
              type="button"
              onClick={resetAngle}
              aria-label="Reset rotation"
              className="min-w-[2.75rem] rounded px-1 text-center text-xs tabular-nums text-fg-subtle hover:text-fg"
            >
              {Math.round(currentAngle)}°
            </button>
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 px-0"
              aria-label="Rotate right"
              onClick={() => bump(15)}
            >
              ↻
            </Button>
          </div>
        )}
      </div>
      {state === 'loading' && <p className="mt-2 text-sm text-fg-muted">Loading 3D preview…</p>}
      {state === 'ready' && (
        <p className="mt-2 text-sm text-fg-subtle">Live preview · your artwork on the real model</p>
      )}
    </div>
  );
});

export default Model3dDecalPreview;
