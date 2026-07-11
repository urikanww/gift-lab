import * as THREE from 'three';

/**
 * Main-thread wrapper around the STL parse worker. Sends the ArrayBuffer to a
 * shared module-level worker (transferred, so no copy) and rebuilds a
 * THREE.BufferGeometry from the positions/normals/bounding-box it posts back.
 *
 * A single shared worker serializes parses across all viewers - fine, and it
 * avoids spawning a worker (each bundles three) per component. A non-STL/corrupt
 * buffer rejects, so callers can `.catch()` to skip that part just like the old
 * inline `try { STLLoader.parse } catch {}`.
 */

interface ParseResponse {
  id: number;
  ok: boolean;
  error?: string;
  positions?: ArrayBuffer;
  normals?: ArrayBuffer | null;
  min?: [number, number, number] | null;
  max?: [number, number, number] | null;
}

let worker: Worker | null = null;
let seq = 0;
const pending = new Map<number, { resolve: (g: THREE.BufferGeometry) => void; reject: (e: Error) => void }>();

function getWorker(): Worker {
  if (worker) return worker;
  worker = new Worker(new URL('../workers/stlParse.worker.ts', import.meta.url), { type: 'module' });

  worker.onmessage = (e: MessageEvent<ParseResponse>) => {
    const msg = e.data;
    const entry = pending.get(msg.id);
    if (!entry) return;
    pending.delete(msg.id);

    if (!msg.ok || !msg.positions) {
      entry.reject(new Error(msg.error ?? 'Failed to parse STL.'));
      return;
    }

    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.BufferAttribute(new Float32Array(msg.positions), 3));
    if (msg.normals) {
      geometry.setAttribute('normal', new THREE.BufferAttribute(new Float32Array(msg.normals), 3));
    }
    if (msg.min && msg.max) {
      geometry.boundingBox = new THREE.Box3(
        new THREE.Vector3(msg.min[0], msg.min[1], msg.min[2]),
        new THREE.Vector3(msg.max[0], msg.max[1], msg.max[2]),
      );
    }
    entry.resolve(geometry);
  };

  worker.onerror = () => {
    // A worker-level crash can't be tied to one request - fail all in-flight
    // parses so the viewers drop to their error state instead of hanging.
    pending.forEach((p) => p.reject(new Error('STL worker crashed.')));
    pending.clear();
  };

  return worker;
}

/** Parse STL bytes into a BufferGeometry off the main thread. */
export function parseStlInWorker(buffer: ArrayBuffer): Promise<THREE.BufferGeometry> {
  return new Promise((resolve, reject) => {
    const id = ++seq;
    pending.set(id, { resolve, reject });
    getWorker().postMessage({ id, buffer }, [buffer]);
  });
}
