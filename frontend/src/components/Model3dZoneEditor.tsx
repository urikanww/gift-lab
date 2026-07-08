import { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import api, { apiError, ensureCsrf } from '../lib/api';
import { detectPrintZone } from '../lib/planarDetect';
import { zoneBasis } from '../lib/printZone';
import { Button, Input } from '../ui';
import type { PrintZone } from '../types';

/**
 * Admin print-zone editor. Streams a MODEL_3D product's mesh (through the
 * auth-carrying axios instance, not the loaders' raw XHR), auto-detects a
 * suggested flat print face, and lets staff click the model to reposition the
 * zone and set its size in millimetres.
 *
 * Coordinate note: the mesh is added at the origin with an identity transform
 * and is NOT re-centred, so raycast hit points, `detectPrintZone` output and
 * the zone quad all share one model-space frame. The camera is fitted to the
 * geometry's bounding sphere instead of moving the geometry.
 */

interface Props {
  productId: number;
  hasGlb: boolean;
  initialZone: PrintZone | null;
  onSaved: (zone: PrintZone) => void;
}

const DEFAULT_SIZE_MM = 20;

/** A vector perpendicular to n (for a fresh/degenerate "up"). */
function perpendicular(n: THREE.Vector3): THREE.Vector3 {
  const up = Math.abs(n.y) < 0.9 ? new THREE.Vector3(0, 1, 0) : new THREE.Vector3(1, 0, 0);
  return up.sub(n.clone().multiplyScalar(up.dot(n))).normalize();
}

/** Build the next zone after a click, preserving size + reprojecting up. */
function nextZone(prev: PrintZone | null, point: THREE.Vector3, worldNormal: THREE.Vector3): PrintZone {
  const n = worldNormal.clone().normalize();
  let up: THREE.Vector3;
  if (prev) {
    up = new THREE.Vector3(...prev.up).sub(n.clone().multiplyScalar(new THREE.Vector3(...prev.up).dot(n)));
    if (up.lengthSq() < 1e-6) up = perpendicular(n);
    else up.normalize();
  } else {
    up = perpendicular(n);
  }
  return {
    normal: [n.x, n.y, n.z],
    center: [point.x, point.y, point.z],
    up: [up.x, up.y, up.z],
    width_mm: prev?.width_mm ?? DEFAULT_SIZE_MM,
    height_mm: prev?.height_mm ?? DEFAULT_SIZE_MM,
  };
}

export default function Model3dZoneEditor({ productId, hasGlb, initialZone, onSaved }: Props) {
  const mountRef = useRef<HTMLDivElement | null>(null);
  const sceneRef = useRef<THREE.Scene | null>(null);
  const cameraRef = useRef<THREE.PerspectiveCamera | null>(null);
  const modelMeshRef = useRef<THREE.Mesh | null>(null);
  const zoneMeshRef = useRef<THREE.Mesh | null>(null);

  const [status, setStatus] = useState<'loading' | 'ready' | 'error'>('loading');
  const [errorMsg, setErrorMsg] = useState<string | null>(null);
  const [zone, setZone] = useState<PrintZone | null>(null);
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  // ---- three.js lifecycle (mirrors ModelViewer.tsx; no auto-rotate) ----
  useEffect(() => {
    const mount = mountRef.current;
    if (!mount) return;

    let disposed = false;
    const width = mount.clientWidth;
    const height = mount.clientHeight || 420;

    const scene = new THREE.Scene();
    sceneRef.current = scene;
    const camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 5000);
    cameraRef.current = camera;
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setSize(width, height);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    mount.appendChild(renderer.domElement);

    scene.add(new THREE.AmbientLight(0xffffff, 0.7));
    const keyLight = new THREE.DirectionalLight(0xffffff, 1.2);
    keyLight.position.set(1, 2, 3);
    scene.add(keyLight);
    const fillLight = new THREE.DirectionalLight(0xffffff, 0.4);
    fillLight.position.set(-2, -1, -2);
    scene.add(fillLight);

    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.autoRotate = false;

    const raycaster = new THREE.Raycaster();

    let frame = 0;
    const animate = () => {
      frame = requestAnimationFrame(animate);
      controls.update();
      renderer.render(scene, camera);
    };

    // Fetch bytes through axios (carries the Sanctum cookie), then .parse().
    const load = async () => {
      const res = await api.get(`/admin/products/${productId}/model`, {
        params: { kind: hasGlb ? 'glb' : 'mesh' },
        responseType: 'arraybuffer',
      });
      const buf = res.data as ArrayBuffer;

      let geometry: THREE.BufferGeometry;
      if (hasGlb) {
        geometry = await new Promise<THREE.BufferGeometry>((resolve, reject) => {
          new GLTFLoader().parse(
            buf,
            '',
            (gltf) => {
              gltf.scene.updateMatrixWorld(true);
              let found: THREE.BufferGeometry | null = null;
              gltf.scene.traverse((obj) => {
                const m = obj as THREE.Mesh;
                if (!found && m.isMesh && m.geometry) {
                  // Bake the node transform so geometry space == world space.
                  const g = m.geometry.clone();
                  g.applyMatrix4(m.matrixWorld);
                  found = g;
                }
              });
              if (found) resolve(found);
              else reject(new Error('No mesh found in GLB.'));
            },
            (err) => reject(err instanceof Error ? err : new Error('Failed to parse GLB.')),
          );
        });
      } else {
        // STLLoader.parse throws on non-STL bytes (.obj/.3mf) - surfaced below.
        geometry = new STLLoader().parse(buf);
      }

      if (disposed) {
        geometry.dispose();
        return;
      }

      geometry.computeVertexNormals();

      const material = new THREE.MeshStandardMaterial({ color: 0x8a8a8a, roughness: 0.55, metalness: 0.1 });
      const mesh = new THREE.Mesh(geometry, material);
      modelMeshRef.current = mesh;
      scene.add(mesh);

      // Fit the camera to the geometry's own bounding sphere (no recentring).
      geometry.computeBoundingSphere();
      const sphere = geometry.boundingSphere;
      const center = sphere?.center ?? new THREE.Vector3();
      const radius = sphere?.radius ?? 50;
      camera.position.set(center.x + radius * 1.8, center.y + radius * 1.4, center.z + radius * 1.8);
      camera.lookAt(center);
      controls.target.copy(center);
      controls.maxDistance = radius * 6;
      controls.update();

      setZone(initialZone ?? detectPrintZone(geometry));
      setStatus('ready');
      animate();
    };

    load().catch((err) => {
      if (disposed) return;
      setStatus('error');
      setErrorMsg(
        'Preview supports STL or GLB models. Replace the model with an STL to edit the print zone.',
      );
      // Keep the underlying reason in the console for debugging.
      console.error('[Model3dZoneEditor] load failed', err);
    });

    // ---- Click-to-place: distinguish a click from an orbit drag ----
    const pointerDown = { x: 0, y: 0 };
    const onPointerDown = (e: PointerEvent) => {
      pointerDown.x = e.clientX;
      pointerDown.y = e.clientY;
    };
    const onPointerUp = (e: PointerEvent) => {
      const moved = Math.hypot(e.clientX - pointerDown.x, e.clientY - pointerDown.y);
      if (moved > 5) return; // orbit drag, not a click
      const mesh = modelMeshRef.current;
      if (!mesh) return;
      const rect = renderer.domElement.getBoundingClientRect();
      const ndc = new THREE.Vector2(
        ((e.clientX - rect.left) / rect.width) * 2 - 1,
        -((e.clientY - rect.top) / rect.height) * 2 + 1,
      );
      raycaster.setFromCamera(ndc, camera);
      const hits = raycaster.intersectObject(mesh, false);
      if (!hits.length || !hits[0].face) return;
      const hit = hits[0];
      const normalMatrix = new THREE.Matrix3().getNormalMatrix(mesh.matrixWorld);
      const worldNormal = hit.face!.normal.clone().applyMatrix3(normalMatrix).normalize();
      setZone((prev) => nextZone(prev, hit.point.clone(), worldNormal));
    };
    renderer.domElement.addEventListener('pointerdown', onPointerDown);
    renderer.domElement.addEventListener('pointerup', onPointerUp);

    const onResize = () => {
      const w = mount.clientWidth;
      const h = mount.clientHeight || 420;
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h);
    };
    window.addEventListener('resize', onResize);

    return () => {
      disposed = true;
      cancelAnimationFrame(frame);
      window.removeEventListener('resize', onResize);
      renderer.domElement.removeEventListener('pointerdown', onPointerDown);
      renderer.domElement.removeEventListener('pointerup', onPointerUp);
      controls.dispose();
      renderer.dispose();
      scene.traverse((obj) => {
        if (obj instanceof THREE.Mesh) {
          obj.geometry.dispose();
          (Array.isArray(obj.material) ? obj.material : [obj.material]).forEach((m) => m.dispose());
        }
      });
      if (renderer.domElement.parentNode === mount) mount.removeChild(renderer.domElement);
      sceneRef.current = null;
      cameraRef.current = null;
      modelMeshRef.current = null;
      zoneMeshRef.current = null;
    };
  }, [productId, hasGlb, initialZone]);

  // ---- Zone quad: rebuild/reorient whenever the zone changes ----
  useEffect(() => {
    const scene = sceneRef.current;
    if (!scene || !zone) return;

    const geo = new THREE.PlaneGeometry(zone.width_mm, zone.height_mm);
    let mesh = zoneMeshRef.current;
    if (!mesh) {
      const mat = new THREE.MeshStandardMaterial({
        color: 0x2563eb,
        transparent: true,
        opacity: 0.35,
        side: THREE.DoubleSide,
        depthTest: true,
      });
      mesh = new THREE.Mesh(geo, mat);
      zoneMeshRef.current = mesh;
      scene.add(mesh);
    } else {
      mesh.geometry.dispose();
      mesh.geometry = geo;
    }

    const { n, u, v } = zoneBasis(zone);
    const basis = new THREE.Matrix4().makeBasis(u, v, n);
    mesh.quaternion.setFromRotationMatrix(basis);
    // Nudge along +n to avoid z-fighting with the surface.
    mesh.position.set(zone.center[0], zone.center[1], zone.center[2]).addScaledVector(n, 0.1);
  }, [zone]);

  const setSize = (key: 'width_mm' | 'height_mm', raw: string) => {
    const value = Math.max(1, Number(raw) || 1);
    setZone((prev) => (prev ? { ...prev, [key]: value } : prev));
  };

  const save = async () => {
    if (!zone || saving) return;
    setSaving(true);
    setSaveError(null);
    try {
      await ensureCsrf();
      await api.post(`/admin/products/${productId}/print-zone`, { print_zone: zone });
      onSaved(zone);
    } catch (err) {
      setSaveError(apiError(err));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="flex flex-col gap-4 lg:flex-row">
      <div className="min-w-0 flex-1">
        <div
          ref={mountRef}
          className="h-[420px] w-full rounded-lg border border-border bg-surface"
          aria-label="Print zone editor"
          role="img"
        />
        {status === 'loading' && <p className="mt-2 text-sm text-fg-muted">Loading model…</p>}
        {status === 'error' && errorMsg && <p className="mt-2 text-sm text-danger">{errorMsg}</p>}
        {status === 'ready' && !zone && (
          <p className="mt-2 text-sm text-fg-muted">
            No flat face detected — click the model to place the print zone.
          </p>
        )}
        {status === 'ready' && zone && (
          <p className="mt-2 text-sm text-fg-subtle">
            Drag to rotate · scroll to zoom · click the surface to reposition the zone
          </p>
        )}
      </div>

      <div className="flex w-full flex-col gap-3 lg:w-56">
        <Input
          label="Width (mm)"
          type="number"
          min={1}
          step={1}
          value={zone ? zone.width_mm : ''}
          onChange={(e) => setSize('width_mm', e.target.value)}
          disabled={status !== 'ready' || !zone}
        />
        <Input
          label="Height (mm)"
          type="number"
          min={1}
          step={1}
          value={zone ? zone.height_mm : ''}
          onChange={(e) => setSize('height_mm', e.target.value)}
          disabled={status !== 'ready' || !zone}
        />
        <Button onClick={() => void save()} loading={saving} disabled={!zone || status !== 'ready'}>
          Save print zone
        </Button>
        {saveError && <p className="text-sm text-danger">{saveError}</p>}
      </div>
    </div>
  );
}
