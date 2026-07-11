import { Spinner, cn } from '../ui';

/**
 * Shared loading indicator for the 3D viewers: a DETERMINATE download bar while
 * bytes stream in (needs a Content-Length → `total`), then an indeterminate
 * "processing" state while the worker parses the mesh. Falls back to a spinner
 * when the total is unknown (server sent no length).
 */
export type ModelLoadPhase = 'downloading' | 'processing';

export interface ModelLoadProgressProps {
  phase: ModelLoadPhase;
  /** Bytes received so far (download phase). */
  loaded?: number;
  /** Total bytes from Content-Length, or null when unknown → indeterminate. */
  total?: number | null;
  /** 'onDark' tunes the palette for the studio's dark overlay. */
  variant?: 'default' | 'onDark';
  className?: string;
}

function formatMb(bytes: number): string {
  return `${(bytes / 1048576).toFixed(1)} MB`;
}

export default function ModelLoadProgress({
  phase,
  loaded = 0,
  total,
  variant = 'default',
  className,
}: ModelLoadProgressProps) {
  const onDark = variant === 'onDark';
  const textCls = onDark ? 'text-white/70' : 'text-fg-muted';
  const pct = phase === 'downloading' && total ? Math.min(100, Math.round((loaded / total) * 100)) : null;

  return (
    <div className={cn('flex w-48 max-w-[70%] flex-col items-center gap-2', className)}>
      {pct != null ? (
        <>
          <div className={cn('h-1.5 w-full overflow-hidden rounded-full', onDark ? 'bg-white/20' : 'bg-black/10')}>
            <div
              className="h-full rounded-full bg-brand-500 transition-[width] duration-150 ease-out"
              style={{ width: `${pct}%` }}
              role="progressbar"
              aria-valuemin={0}
              aria-valuemax={100}
              aria-valuenow={pct}
              aria-label="Downloading model"
            />
          </div>
          <span className={cn('text-xs tabular-nums', textCls)}>
            Loading model… {pct}%
            {total ? ` · ${formatMb(loaded)} / ${formatMb(total)}` : ''}
          </span>
        </>
      ) : (
        <>
          <Spinner size="md" className={onDark ? 'text-white' : undefined} label={phase === 'processing' ? 'Processing model' : 'Loading model'} />
          <span className={cn('text-xs', textCls)}>
            {phase === 'processing'
              ? 'Processing model…'
              : `Loading model…${loaded ? ` ${formatMb(loaded)}` : ''}`}
          </span>
        </>
      )}
    </div>
  );
}
