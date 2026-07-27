import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';

/**
 * Renders a QR for the buyer's permanent signed tracking link. `link` is the
 * relative path from the API (tracking_link); we resolve it against the current
 * origin so the encoded URL opens the app anywhere it is scanned.
 */
export default function TrackingQr({ link, size = 160 }: { link: string; size?: number }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    setFailed(false);
    const absolute = new URL(link, window.location.origin).toString();
    QRCode.toCanvas(canvas, absolute, { width: size, margin: 1 }).catch(() => {
      // A rejected generation used to be an unhandled promise rejection with
      // no fallback for the buyer. Keep the canvas mounted (so a later retry
      // via `link`/`size` changing still has a ref to draw into) and show a
      // small text fallback in its place.
      setFailed(true);
    });
  }, [link, size]);

  return (
    <>
      <canvas ref={canvasRef} aria-label="Order tracking QR code" className={failed ? 'hidden' : undefined} />
      {failed && (
        <p role="status" className="text-xs text-fg-subtle">
          Could not generate QR code.
        </p>
      )}
    </>
  );
}
