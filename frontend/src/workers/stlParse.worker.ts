import { STLLoader } from 'three/examples/jsm/loaders/STLLoader.js';

/**
 * Web Worker: parse STL bytes off the main thread so a 100 MB mesh doesn't
 * freeze the tab. Receives an ArrayBuffer (transferred), runs STLLoader().parse
 * + computes smooth vertex normals + a bounding box (matching what the viewers
 * did inline), and posts the geometry's raw typed-array buffers back as
 * transferables. The main thread (see lib/parseStl.ts) rebuilds a
 * THREE.BufferGeometry from them. Non-STL bytes (.3mf/.obj) throw in parse and
 * come back as `{ ok: false }`, letting a caller skip that part.
 */

interface ParseRequest {
  id: number;
  buffer: ArrayBuffer;
}

interface ParseResponse {
  id: number;
  ok: boolean;
  error?: string;
  positions?: ArrayBuffer;
  normals?: ArrayBuffer | null;
  min?: [number, number, number] | null;
  max?: [number, number, number] | null;
}

// The tsconfig lib is DOM-only (no "webworker" lib), so `self` is typed as a
// Window. Narrow it to the worker surface we actually use to avoid the Window
// postMessage(targetOrigin) overload.
const ctx = self as unknown as {
  onmessage: ((e: MessageEvent<ParseRequest>) => void) | null;
  postMessage: (message: ParseResponse, transfer?: Transferable[]) => void;
};

ctx.onmessage = (e) => {
  const { id, buffer } = e.data;
  try {
    const geometry = new STLLoader().parse(buffer);
    geometry.computeVertexNormals();
    geometry.computeBoundingBox();

    const position = geometry.getAttribute('position').array as Float32Array;
    const normalAttr = geometry.getAttribute('normal');
    const normal = normalAttr ? (normalAttr.array as Float32Array) : null;
    const bb = geometry.boundingBox;

    const transfer: Transferable[] = [position.buffer as ArrayBuffer];
    if (normal) transfer.push(normal.buffer as ArrayBuffer);

    ctx.postMessage(
      {
        id,
        ok: true,
        positions: position.buffer as ArrayBuffer,
        normals: normal ? (normal.buffer as ArrayBuffer) : null,
        min: bb ? [bb.min.x, bb.min.y, bb.min.z] : null,
        max: bb ? [bb.max.x, bb.max.y, bb.max.z] : null,
      },
      transfer,
    );
  } catch (err) {
    ctx.postMessage({ id, ok: false, error: err instanceof Error ? err.message : String(err) });
  }
};
