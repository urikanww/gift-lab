import { useEffect, useRef } from 'react';
import QRCode from 'qrcode';

/**
 * Printable traveler label: job id as a QR the floor scans to advance the job.
 * The QR encodes the raw job id - the advance endpoints are staff-auth gated, so
 * the id alone is not a secret. Opens the browser print dialog on mount.
 */
export default function JobLabel({ jobId, onClose }: { jobId: number; onClose: () => void }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    void QRCode.toCanvas(canvas, String(jobId), { width: 220, margin: 2 }).then(() => {
      window.print();
    });
  }, [jobId]);

  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-white p-8 text-black print:static">
      <p className="text-2xl font-bold">Job #{jobId}</p>
      <canvas ref={canvasRef} />
      <button className="text-sm underline print:hidden" onClick={onClose}>
        Close
      </button>
    </div>
  );
}
