import { useEffect, useRef, useState } from 'react';
import { Canvas, FabricImage, IText } from 'fabric';
import type { Customization } from '../types';

export interface CapturedArtwork {
  // Production-grade export (high multiplier) — this is what becomes the proof
  // and, once approved, the print file (spec 7). No separate re-processing.
  dataUrl: string;
  layout: object;
  customization: Customization;
}

interface DesignerCanvasProps {
  width?: number;
  height?: number;
  onCapture: (artwork: CapturedArtwork) => void;
}

const LOGO_SIZES = ['S', 'M', 'L'] as const;

export default function DesignerCanvas({ width = 500, height = 380, onCapture }: DesignerCanvasProps) {
  const elRef = useRef<HTMLCanvasElement | null>(null);
  const canvasRef = useRef<Canvas | null>(null);
  const [hasLogo, setHasLogo] = useState(false);
  const [nameText, setNameText] = useState('');
  const [logoSize, setLogoSize] = useState<(typeof LOGO_SIZES)[number]>('M');

  useEffect(() => {
    if (!elRef.current) return;
    const canvas = new Canvas(elRef.current, {
      backgroundColor: '#ffffff',
      preserveObjectStacking: true,
    });
    canvas.setDimensions({ width, height });
    canvasRef.current = canvas;

    return () => {
      void canvas.dispose();
      canvasRef.current = null;
    };
  }, [width, height]);

  const sizeToScale = (size: (typeof LOGO_SIZES)[number]): number =>
    ({ S: 0.4, M: 0.7, L: 1.0 })[size];

  const handleLogoUpload = async (file: File) => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const reader = new FileReader();
    const dataUrl = await new Promise<string>((resolve, reject) => {
      reader.onload = () => resolve(reader.result as string);
      reader.onerror = () => reject(new Error('Could not read file.'));
      reader.readAsDataURL(file);
    });

    const img = await FabricImage.fromURL(dataUrl);
    const scale = sizeToScale(logoSize);
    img.scaleToWidth(width * 0.4 * scale);
    img.set({ left: width / 2, top: height / 2, originX: 'center', originY: 'center' });
    canvas.add(img);
    canvas.setActiveObject(img);
    canvas.requestRenderAll();
    setHasLogo(true);
  };

  const applyNameText = () => {
    const canvas = canvasRef.current;
    if (!canvas || !nameText) return;
    const text = new IText(nameText, {
      left: width / 2,
      top: height - 60,
      originX: 'center',
      fontSize: 28,
      fill: '#111111',
      fontFamily: 'Helvetica, Arial, sans-serif',
    });
    canvas.add(text);
    canvas.setActiveObject(text);
    canvas.requestRenderAll();
  };

  const capture = () => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    // multiplier=4 gives print-resolution output from the on-screen preview.
    const dataUrl = canvas.toDataURL({ format: 'png', multiplier: 4 });
    const layout = canvas.toJSON();
    onCapture({
      dataUrl,
      layout,
      customization: {
        logo_size: hasLogo ? logoSize : null,
        name_text: nameText || null,
        artwork_ref: dataUrl,
      },
    });
  };

  return (
    <div className="designer">
      <canvas ref={elRef} className="designer__canvas" />

      <div className="designer__tools">
        <label className="field">
          Logo size
          <select value={logoSize} onChange={(e) => setLogoSize(e.target.value as (typeof LOGO_SIZES)[number])}>
            {LOGO_SIZES.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </label>

        <label className="field">
          Upload logo
          <input
            type="file"
            accept="image/png,image/jpeg,image/svg+xml"
            onChange={(e) => {
              const file = e.target.files?.[0];
              if (file) void handleLogoUpload(file);
            }}
          />
        </label>

        <label className="field">
          Name / text
          <input
            type="text"
            value={nameText}
            maxLength={255}
            placeholder="e.g. Acme Pte Ltd"
            onChange={(e) => setNameText(e.target.value)}
          />
        </label>

        <div className="designer__actions">
          <button type="button" className="btn" onClick={applyNameText} disabled={!nameText}>
            Add text
          </button>
          <button type="button" className="btn btn--primary" onClick={capture}>
            Use this design
          </button>
        </div>
      </div>
    </div>
  );
}
