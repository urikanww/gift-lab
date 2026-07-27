import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createRef } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ThemeProvider } from '../ui';
import type { DesignerCanvasHandle } from './DesignerCanvas';

/* -------------------------------------------------------------------------- */
/* Fabric is a real <canvas> library; jsdom has no 2D context. Mock it with a  */
/* tiny fake that records the calls our keyboard bindings drive (remove /      */
/* loadFromJSON), so we can test Delete + Ctrl/Cmd+Z undo without a GPU.       */
/* -------------------------------------------------------------------------- */

class FakeImage {
  scaleX = 1;
  scaleY = 1;
  left = 0;
  top = 0;
  angle = 0;
  scaleToWidth = vi.fn();
  setCoords = vi.fn();
  // Fabric's set() mutates the object; mirror that so placement round-trips
  // (setLogoFraction -> getLogoPlacement) read back the values we wrote.
  set = vi.fn((patch: Record<string, unknown>) => {
    Object.assign(this, patch);
    return this;
  });
  setControlsVisibility = vi.fn();
  getScaledWidth = () => 100;
  getBoundingRect = () => ({ left: 100, top: 100, width: 100, height: 60 });
  static fromURL = vi.fn(async () => new FakeImage());
}

const created: FakeCanvas[] = [];

class FakeCanvas {
  handlers: Record<string, ((e: any) => void)[]> = {};
  objects: FakeImage[] = [];
  active: FakeImage | null = null;
  loadFromJSON = vi.fn(async () => {});
  remove = vi.fn((o: FakeImage) => {
    this.objects = this.objects.filter((x) => x !== o);
  });
  constructor(el: unknown, _opts: unknown) {
    created.push(this);
    // Mimic fabric v6's DOM surgery (C17): the mounted <canvas> is re-parented
    // into a library-owned .canvas-container div. Any React sibling anchored
    // on the canvas node would then crash reconciliation - the isolation
    // wrapper in DesignerCanvas exists precisely to absorb this.
    const canvasEl = el as HTMLCanvasElement | null;
    if (canvasEl?.parentNode) {
      const container = document.createElement('div');
      container.className = 'canvas-container';
      canvasEl.parentNode.insertBefore(container, canvasEl);
      container.appendChild(canvasEl);
    }
  }
  setDimensions = vi.fn();
  on = (evt: string, fn: (e: any) => void) => {
    (this.handlers[evt] ||= []).push(fn);
  };
  getObjects = () => this.objects;
  getActiveObject = () => this.active;
  setActiveObject = (o: FakeImage) => {
    this.active = o;
  };
  discardActiveObject = vi.fn(() => {
    this.active = null;
  });
  add = (o: FakeImage) => {
    this.objects.push(o);
  };
  requestRenderAll = vi.fn();
  bringObjectForward = vi.fn();
  sendObjectBackwards = vi.fn();
  toJSON = () => ({ objects: this.objects.map(() => ({})) });
  toDataURL = () => 'data:image/png;base64,AAAA';
  dispose = vi.fn(async () => {});
}

vi.mock('fabric', () => ({
  Canvas: FakeCanvas,
  FabricImage: FakeImage,
}));

// framer-motion's ResizeObserver / rAF are not needed; jsdom lacks
// ResizeObserver, so provide a no-op one for the responsive stage effect.
beforeEach(() => {
  (globalThis as any).ResizeObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
  // jsdom never actually decodes an <img>, so the print-quality probe
  // (`new Image()` + onload) inside handleLogoUpload would hang forever.
  // Resolve it deterministically so a real upload flow can complete in a test.
  (globalThis as any).Image = class {
    naturalWidth = 2000;
    naturalHeight = 2000;
    onload: (() => void) | null = null;
    onerror: (() => void) | null = null;
    set src(_v: string) {
      queueMicrotask(() => this.onload?.());
    }
  };
});

afterEach(() => {
  vi.clearAllMocks();
});

// Import AFTER vi.mock so the component picks up the fake fabric.
async function renderCanvas(props: Record<string, unknown> = {}) {
  const { default: DesignerCanvas } = await import('./DesignerCanvas');
  const utils = render(
    <ThemeProvider>
      <DesignerCanvas onCapture={() => {}} {...(props as any)} />
    </ThemeProvider>,
  );
  return utils;
}

it('shows the Delete / Ctrl+Z hint when an object is selected', async () => {
  await renderCanvas();
  const stage = screen.getByLabelText('Design canvas').parentElement as HTMLElement;

  // Seed a selected object on the fake canvas via its instance.
  const canvas = lastCanvas();
  const img = new FakeImage();
  canvas.objects.push(img);
  canvas.active = img;
  // Fire a selection event so the component flips hasSelection.
  fire(canvas, 'selection:created');

  expect(await screen.findByText(/Delete to/i)).toBeInTheDocument();
  expect(screen.getByText(/Ctrl\+Z to undo/i)).toBeInTheDocument();
  expect(stage).toBeInTheDocument();
});

it('does not render a name/text tool', async () => {
  await renderCanvas();
  expect(screen.queryByText(/name \/ text/i)).toBeNull();
});

it('Delete key removes the selected object', async () => {
  await renderCanvas();
  const stage = screen.getByLabelText('Design canvas').parentElement as HTMLElement;
  const canvas = lastCanvas();
  const img = new FakeImage();
  canvas.objects.push(img);
  canvas.active = img;

  fireEvent.keyDown(stage, { key: 'Delete' });

  expect(canvas.remove).toHaveBeenCalledWith(img);
});

it('does NOT delete when the event originates from a text input', async () => {
  await renderCanvas();
  const stage = screen.getByLabelText('Design canvas').parentElement as HTMLElement;
  const canvas = lastCanvas();
  const img = new FakeImage();
  canvas.objects.push(img);
  canvas.active = img;

  // Simulate the keydown coming from an <input> (typing) nested inside the
  // focusable stage: the event bubbles to the stage handler with the input as
  // its target, which the typing-guard must ignore.
  const input = document.createElement('input');
  stage.appendChild(input);
  fireEvent.keyDown(input, { key: 'Backspace' });

  expect(canvas.remove).not.toHaveBeenCalled();
  input.remove();
});

it('Ctrl+Z undoes the last delete via loadFromJSON', async () => {
  await renderCanvas();
  const stage = screen.getByLabelText('Design canvas').parentElement as HTMLElement;
  const canvas = lastCanvas();
  const img = new FakeImage();
  canvas.objects.push(img);
  canvas.active = img;

  // Delete pushes a snapshot, then Ctrl+Z should restore it.
  fireEvent.keyDown(stage, { key: 'Delete' });
  fireEvent.keyDown(stage, { key: 'z', ctrlKey: true });

  expect(canvas.loadFromJSON).toHaveBeenCalledTimes(1);
});

it('Ctrl+Z is a no-op with an empty history', async () => {
  await renderCanvas();
  const stage = screen.getByLabelText('Design canvas').parentElement as HTMLElement;
  const canvas = lastCanvas();

  fireEvent.keyDown(stage, { key: 'z', ctrlKey: true });

  expect(canvas.loadFromJSON).not.toHaveBeenCalled();
});

it('survives a backdrop that arrives after fabric re-parents the canvas (C17)', async () => {
  // Regression: the 3D face render resolves async, flipping backgroundUrl
  // null -> data URL after fabric has already moved the <canvas> node. Without
  // the isolation wrapper this rerender crashed React with an insertBefore
  // NotFoundError and blanked the whole designer.
  const { default: DesignerCanvas } = await import('./DesignerCanvas');
  const { rerender, container } = render(
    <ThemeProvider>
      <DesignerCanvas onCapture={() => {}} backgroundUrl={null} />
    </ThemeProvider>,
  );

  expect(container.querySelector('img[referrerpolicy="no-referrer"]')).toBeNull();

  rerender(
    <ThemeProvider>
      <DesignerCanvas onCapture={() => {}} backgroundUrl="data:image/png;base64,AAAA" />
    </ThemeProvider>,
  );

  // Designer still alive, backdrop mounted, canvas still inside fabric's container.
  expect(screen.getByLabelText('Design canvas')).toBeInTheDocument();
  expect(container.querySelector('img[referrerpolicy="no-referrer"]')).not.toBeNull();
  expect(screen.getByLabelText('Design canvas').parentElement?.className).toContain('canvas-container');

  // And the reverse hop (render failure clears the backdrop) is safe too.
  rerender(
    <ThemeProvider>
      <DesignerCanvas onCapture={() => {}} backgroundUrl={null} />
    </ThemeProvider>,
  );
  expect(screen.getByLabelText('Design canvas')).toBeInTheDocument();
  expect(container.querySelector('img[referrerpolicy="no-referrer"]')).toBeNull();
});

it('exposes an imperative handle whose zone-fraction placement round-trips', async () => {
  const { default: DesignerCanvas } = await import('./DesignerCanvas');
  const ref = createRef<DesignerCanvasHandle>();
  render(
    <ThemeProvider>
      <DesignerCanvas ref={ref} onCapture={() => {}} />
    </ThemeProvider>,
  );

  // Add a logo the same way the other tests seed the fake canvas.
  const canvas = lastCanvas();
  const img = new FakeImage();
  canvas.objects.push(img);
  canvas.active = img;

  ref.current!.setLogoFraction(0.25, 0.75);
  const p = ref.current!.getLogoPlacement();
  expect(p!.fu).toBeCloseTo(0.25, 2);
  expect(p!.fv).toBeCloseTo(0.75, 2);

  // The live render surface is reachable for a THREE.CanvasTexture.
  expect(ref.current!.getCanvasElement()).not.toBeNull();
});

it('survives a container resize: same canvas instance, placed objects untouched', async () => {
  // Regression for the resize-wipes-artwork bug: a dims change used to
  // dispose() and rebuild the fabric canvas, dropping every placed object
  // while the parent's hasLogo/price state kept advertising a logo that no
  // longer existed on the canvas.
  const { default: DesignerCanvas } = await import('./DesignerCanvas');
  const countBefore = created.length;
  const { rerender } = render(
    <ThemeProvider>
      <DesignerCanvas onCapture={() => {}} width={500} height={380} />
    </ThemeProvider>,
  );

  expect(created.length).toBe(countBefore + 1);
  const canvas = lastCanvas();
  const img = new FakeImage();
  canvas.objects.push(img);

  // Simulate a container/orientation resize (the real ResizeObserver path
  // recomputes `dims` from the container width; changing the `width` prop
  // drives the exact same measure()-then-setDims code path in jsdom, where
  // clientWidth is always 0).
  rerender(
    <ThemeProvider>
      <DesignerCanvas onCapture={() => {}} width={300} height={228} />
    </ThemeProvider>,
  );

  // No new fabric canvas was constructed, and the old one was never disposed.
  expect(created.length).toBe(countBefore + 1);
  expect(canvas.dispose).not.toHaveBeenCalled();
  // The resize is applied via setDimensions, not a dispose+recreate.
  expect(canvas.setDimensions).toHaveBeenCalledWith({ width: 300, height: 228 });
  // Crucially, the previously-placed object is still there.
  expect(canvas.getObjects()).toContain(img);
  expect(canvas.getObjects().length).toBe(1);
});

it('rescales a placed object proportionally on resize instead of leaving it at stale pixel coords', async () => {
  const { default: DesignerCanvas } = await import('./DesignerCanvas');
  const { rerender } = render(
    <ThemeProvider>
      <DesignerCanvas onCapture={() => {}} width={500} height={380} />
    </ThemeProvider>,
  );
  const canvas = lastCanvas();
  const img = new FakeImage();
  img.left = 250;
  img.top = 190;
  img.scaleX = 1;
  img.scaleY = 1;
  canvas.objects.push(img);

  rerender(
    <ThemeProvider>
      <DesignerCanvas onCapture={() => {}} width={250} height={190} />
    </ThemeProvider>,
  );

  // Halving the canvas footprint should halve the object's position/scale so
  // it stays in the same relative spot rather than sitting at now-oversized
  // absolute coordinates (or being lost entirely).
  expect(img.left).toBeCloseTo(125, 0);
  expect(img.top).toBeCloseTo(95, 0);
  expect(img.scaleX).toBeCloseTo(0.5, 2);
  expect(img.scaleY).toBeCloseTo(0.5, 2);
});

it('has no `multiple` attribute on the artwork file input (single-artwork rule)', async () => {
  const { container } = await renderCanvas();
  const input = container.querySelector('input[type="file"]') as HTMLInputElement;
  expect(input).toBeTruthy();
  expect(input.multiple).toBe(false);
});

it('replaces the existing artwork instead of stacking a second upload', async () => {
  const onLogoChange = vi.fn();
  const { container } = await renderCanvas({ onLogoChange });
  const input = container.querySelector('input[type="file"]') as HTMLInputElement;

  const file1 = new File(['first-logo'], 'logo1.png', { type: 'image/png' });
  fireEvent.change(input, { target: { files: [file1] } });

  await waitFor(() => {
    expect(lastCanvas().getObjects().length).toBe(1);
  });
  const firstImg = lastCanvas().getObjects()[0];

  const file2 = new File(['second-logo'], 'logo2.png', { type: 'image/png' });
  fireEvent.change(input, { target: { files: [file2] } });

  // Wait on `remove` specifically (rather than the object count, which is
  // already 1 from the first upload and would make the wait a no-op) so the
  // assertion only runs once the second upload's replace-logic has fired.
  await waitFor(() => {
    expect(lastCanvas().remove).toHaveBeenCalledTimes(1);
  });
  // Still exactly one artwork object, and it's the NEW one - the second
  // upload replaced the first rather than stacking on top of it.
  expect(lastCanvas().getObjects().length).toBe(1);
  expect(lastCanvas().getObjects()[0]).not.toBe(firstImg);
});

it('selecting two files at once only ever uses the first (native single-file input)', async () => {
  const { container } = await renderCanvas();
  const input = container.querySelector('input[type="file"]') as HTMLInputElement;

  const file1 = new File(['a'], 'a.png', { type: 'image/png' });
  const file2 = new File(['b'], 'b.png', { type: 'image/png' });
  fireEvent.change(input, { target: { files: [file1, file2] } });

  await waitFor(() => {
    expect(lastCanvas().getObjects().length).toBe(1);
  });
});

/* ----------------------------- test helpers ------------------------------ */

// The component builds one canvas per mount; grab the most recent.
function lastCanvas(): FakeCanvas {
  return created[created.length - 1];
}
function fire(canvas: FakeCanvas, evt: string) {
  (canvas.handlers[evt] || []).forEach((fn) => fn({}));
}
