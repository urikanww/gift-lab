import { useEffect, useRef } from 'react';
import QRCode from 'qrcode';

/**
 * Renders a QR for the buyer's permanent signed tracking link. `link` is the
 * relative path from the API (tracking_link); we resolve it against the current
 * origin so the encoded URL opens the app anywhere it is scanned.
 */
export default function TrackingQr({ link, size = 160 }: { link: string; size?: number }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const absolute = new URL(link, window.location.origin).toString();
    void QRCode.toCanvas(canvas, absolute, { width: size, margin: 1 });
  }, [link, size]);

  return <canvas ref={canvasRef} aria-label="Order tracking QR code" />;
}
