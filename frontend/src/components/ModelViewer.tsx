import { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { loadModelWithProgress } from '../lib/loadModelWithProgress';
import { parseStlInWorker } from '../lib/parseStl';
import ModelLoadProgress, { type ModelLoadPhase } from './ModelLoadProgress';

/**
 * Interactive 3D preview for MODEL_3D products. Streams the model from
 * /api/catalogue/{key}/model and renders it with orbit + auto-rotate.
 * Lazy-loaded (three.js is heavy) - only MODEL_3D detail pages pay the cost.
 */

interface Props {
  /** Product slug (or legacy id) used by the model stream endpoint. */
  productKey: string;
  className?: string;
}

export default function ModelViewer({ productKey, className }: Props) {
  const mountRef = useRef<HTMLDivElement | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');
  const [progress, setProgress] = useState<{ phase: ModelLoadPhase; loaded: number; total: number | null }>({
    phase: 'downloading',
    loaded: 0,
    total: null,
  });

  useEffect(() => {
    const mount = mountRef.current;
    if (!mount) return;

    let disposed = false;
    const width = mount.clientWidth;
    const height = mount.clientHeight || 360;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, width / height, 0.1, 2000);
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setSize(width, height);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    mount.appendChild(renderer.domElement);

    scene.add(new THREE.AmbientLight(0xffffff, 0.7));
    const key = new THREE.DirectionalLight(0xffffff, 1.2);
    key.position.set(1, 2, 3);
    scene.add(key);
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

    // Determinate download (axios progress) → worker parse (no main-thread
    // freeze on big meshes). Fetched via the authed axios instance, so the
    // relative catalogue path carries the session cookie.
    loadModelWithProgress(`/catalogue/${productKey}/model`, (loaded, total) => {
      if (!disposed) setProgress({ phase: 'downloading', loaded, total });
    })
      .then((buffer) => {
        if (disposed) return null;
        setProgress((p) => ({ ...p, phase: 'processing' }));
        return parseStlInWorker(buffer);
      })
      .then((geometry) => {
        if (disposed || !geometry) return;
        geometry.center();

        const material = new THREE.MeshStandardMaterial({ color: 0x8a8a8a, roughness: 0.55, metalness: 0.1 });
        const mesh = new THREE.Mesh(geometry, material);

        // Fit camera to the model's bounding sphere.
        geometry.computeBoundingSphere();
        const radius = geometry.boundingSphere?.radius ?? 50;
        camera.position.set(radius * 1.8, radius * 1.4, radius * 1.8);
        camera.lookAt(0, 0, 0);
        controls.maxDistance = radius * 6;

        // STL convention is Z-up; three.js is Y-up.
        mesh.rotation.x = -Math.PI / 2;
        scene.add(mesh);
        setState('ready');
        animate();
      })
      .catch(() => {
        if (!disposed) setState('error');
      });

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
      scene.traverse((obj) => {
        if (obj instanceof THREE.Mesh) {
          obj.geometry.dispose();
          (Array.isArray(obj.material) ? obj.material : [obj.material]).forEach((m) => m.dispose());
        }
      });
      mount.removeChild(renderer.domElement);
    };
  }, [productKey]);

  if (state === 'error') return null;

  return (
    <div className={className}>
      <div className="relative h-[360px] w-full">
        <div ref={mountRef} className="h-full w-full rounded-lg border border-border bg-surface" aria-label="Interactive 3D preview" role="img" />
        {state === 'loading' && (
          <div className="absolute inset-0 flex items-center justify-center">
            <ModelLoadProgress phase={progress.phase} loaded={progress.loaded} total={progress.total} />
          </div>
        )}
      </div>
      {state === 'ready' && <p className="mt-2 text-sm text-fg-subtle">Drag to rotate · scroll to zoom</p>}
    </div>
  );
}
