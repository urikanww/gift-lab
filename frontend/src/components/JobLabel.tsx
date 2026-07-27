import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';
import { useOptionalToast } from '../ui';

/**
 * Printable traveler label: job id as a QR the floor scans to advance the job.
 * The QR encodes the raw job id - the advance endpoints are staff-auth gated, so
 * the id alone is not a secret. Opens the browser print dialog on mount.
 */
export default function JobLabel({ jobId, onClose }: { jobId: number; onClose: () => void }) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const [error, setError] = useState<string | null>(null);
  const { toast } = useOptionalToast();

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    setError(null);
    QRCode.toCanvas(canvas, String(jobId), { width: 220, margin: 2 })
      .then(() => {
        window.print();
      })
      .catch(() => {
        // A rejected generation used to leave an unhandled promise rejection
        // and a blank, silently-stuck modal (window.print() never fired, no
        // feedback). Surface it visibly instead, using the same toast channel
        // the rest of the floor uses for failures.
        const message = 'Could not generate the label QR code. Try closing and reopening this label.';
        setError(message);
        toast({ title: 'Print label failed', description: message, tone: 'danger' });
      });
  }, [jobId, toast]);

  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center gap-4 bg-white p-8 text-black print:static">
      <p className="text-2xl font-bold">Job #{jobId}</p>
      <canvas ref={canvasRef} className={error ? 'hidden' : undefined} />
      {error && (
        <p role="alert" className="max-w-xs text-center text-sm text-red-600">
          {error}
        </p>
      )}
      <button className="text-sm underline print:hidden" onClick={onClose}>
        Close
      </button>
    </div>
  );
}
