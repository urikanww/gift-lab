import { useEffect, useRef, useState } from 'react';
import * as THREE from 'three';
import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import api from '../lib/api';

/**
 * Interactive 3D preview for MODEL_3D products. Streams the model from
 * /api/catalogue/{key}/model and renders it with orbit + auto-rotate.
 * Lazy-loaded (three.js is heavy) — only MODEL_3D detail pages pay the cost.
 */

interface Props {
  /** Product slug (or legacy id) used by the model stream endpoint. */
  productKey: string;
  className?: string;
}

export default function ModelViewer({ productKey, className }: Props) {
  const mountRef = useRef<HTMLDivElement | null>(null);
  const [state, setState] = useState<'loading' | 'ready' | 'error'>('loading');

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

    // Absolute API origin — the SPA dev server doesn't proxy /api.
    new STLLoader().load(
      `${api.defaults.baseURL}/catalogue/${productKey}/model`,
      (geometry) => {
        if (disposed) return;
        geometry.center();
        geometry.computeVertexNormals();

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
      <div ref={mountRef} className="h-[360px] w-full rounded-lg border border-border bg-surface" aria-label="Interactive 3D preview" role="img" />
      {state === 'loading' && <p className="mt-2 text-sm text-fg-muted">Loading 3D preview…</p>}
      {state === 'ready' && <p className="mt-2 text-sm text-fg-subtle">Drag to rotate · scroll to zoom</p>}
    </div>
  );
}
