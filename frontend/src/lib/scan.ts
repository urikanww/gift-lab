import { Html5Qrcode } from 'html5-qrcode';

/**
 * Start decoding QR codes from the rear camera into `elementId`. Calls `onScan`
 * with each decoded value (the job id). Returns a stop() that releases the
 * camera. getUserMedia requires HTTPS (or localhost).
 */
export async function startCameraScan(
  elementId: string,
  onScan: (value: string) => void,
): Promise<() => Promise<void>> {
  const scanner = new Html5Qrcode(elementId);
  await scanner.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: 200 },
    (decoded) => onScan(decoded),
    () => {},
  );
  return async () => {
    await scanner.stop();
    scanner.clear();
  };
}
