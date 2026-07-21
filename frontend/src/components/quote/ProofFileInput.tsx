import { useId, useRef, useState } from 'react';
import api, { apiError, ensureCsrf } from '../../lib/api';
import { Button } from '../../ui';

const MAX_BYTES = 3 * 1024 * 1024;
const ACCEPT = 'application/pdf,image/png,image/jpeg,image/webp';

/**
 * Picks a proof file, uploads it, and hands back the stored ref.
 *
 * Staff used to paste a link from wherever they had uploaded the file, because
 * the field was plain text and its placeholder suggested an object-store key -
 * the one value the display could not turn into a working link. This keeps the
 * whole job in the app.
 *
 * The size and type checks mirror the server's exactly. They are a courtesy,
 * not the gate: the server rejects independently, and a 3 MB round-trip that
 * fails is a slow way to learn about a typo'd file.
 */
export default function ProofFileInput({
  label,
  hint,
  value,
  onChange,
  error,
  disabled = false,
}: {
  label: string;
  hint?: string;
  /** The stored ref, or empty when nothing is attached. */
  value: string;
  onChange: (ref: string, fileName: string | null) => void;
  error?: string;
  disabled?: boolean;
}) {
  const inputId = useId();
  const inputRef = useRef<HTMLInputElement>(null);
  const [uploading, setUploading] = useState(false);
  const [fileName, setFileName] = useState<string | null>(null);
  const [localError, setLocalError] = useState<string | undefined>();

  const shownError = error ?? localError;

  const upload = async (file: File) => {
    setLocalError(undefined);

    if (file.size > MAX_BYTES) {
      setLocalError('The proof must be 3 MB or smaller.');
      return;
    }
    if (!ACCEPT.split(',').includes(file.type)) {
      setLocalError('Proofs must be a PDF, PNG, JPG or WEBP file.');
      return;
    }

    setUploading(true);
    try {
      await ensureCsrf();
      const form = new FormData();
      form.append('proof', file);
      const { data } = await api.post<{ ref: string }>('/uploads/proof', form);
      setFileName(file.name);
      onChange(data.ref, file.name);
    } catch (err) {
      setLocalError(apiError(err));
    } finally {
      setUploading(false);
    }
  };

  const clear = () => {
    setFileName(null);
    setLocalError(undefined);
    onChange('', null);
    // Reset the native input, or picking the same file again fires no change.
    if (inputRef.current) inputRef.current.value = '';
  };

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={inputId} className="text-sm font-medium text-fg">
        {label}
      </label>

      {value ? (
        <div className="flex items-center justify-between gap-3 rounded-md border border-border-strong bg-surface px-3 py-2">
          <span className="truncate text-sm text-fg">{fileName ?? value}</span>
          <Button variant="ghost" size="sm" onClick={clear} disabled={disabled || uploading}>
            Remove
          </Button>
        </div>
      ) : (
        <input
          id={inputId}
          ref={inputRef}
          type="file"
          accept={ACCEPT}
          disabled={disabled || uploading}
          aria-invalid={shownError ? true : undefined}
          aria-describedby={shownError ? `${inputId}-error` : undefined}
          className="block w-full text-sm text-fg-muted file:mr-3 file:rounded-md file:border-0 file:bg-surface-2 file:px-3 file:py-2 file:text-sm file:font-medium file:text-fg hover:file:bg-surface-3"
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) void upload(file);
          }}
        />
      )}

      {uploading && <p className="text-sm text-fg-subtle">Uploading…</p>}
      {hint && !shownError && <p className="text-sm text-fg-subtle">{hint}</p>}
      {shownError && (
        <p id={`${inputId}-error`} role="alert" className="text-sm text-danger">
          {shownError}
        </p>
      )}
    </div>
  );
}
