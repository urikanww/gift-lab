import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { ThemeProvider } from '../ui';

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
  scaleToWidth = vi.fn();
  setCoords = vi.fn();
  set = vi.fn();
  getScaledWidth = () => 100;
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
  constructor(_el: unknown, _opts: unknown) {
    created.push(this);
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

/* ----------------------------- test helpers ------------------------------ */

// The component builds one canvas per mount; grab the most recent.
function lastCanvas(): FakeCanvas {
  return created[created.length - 1];
}
function fire(canvas: FakeCanvas, evt: string) {
  (canvas.handlers[evt] || []).forEach((fn) => fn({}));
}
