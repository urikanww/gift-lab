import { Badge, Card, cn } from '../ui';
import { Motion, fadeInUp } from '../motion';
import type { TrackResult } from '../types';

export function TrackResultView({ result }: { result: TrackResult }) {
  const currentIdx = result.stages.findIndex((s) => s.code === result.stage);

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible">
      <Card padding="lg" className="flex flex-col gap-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-2xs uppercase tracking-wide text-fg-subtle">Order</p>
            <p className="font-display text-xl text-fg">{result.reference}</p>
          </div>
          <Badge tone={result.cancelled ? 'danger' : 'brand'} size="md" dot>
            {result.stage_label}
          </Badge>
        </div>

        {result.cancelled ? (
          <p className="text-sm text-fg-muted">This order was cancelled. Contact us if you think this is wrong.</p>
        ) : (
          <ol className="flex flex-col gap-3" aria-label="Order progress">
            {result.stages.map((step, i) => {
              const done = i < currentIdx;
              const active = i === currentIdx;
              return (
                <li key={step.code} className="flex items-center gap-3">
                  <span
                    className={cn(
                      'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                      active
                        ? 'bg-primary text-primary-fg'
                        : done
                          ? 'bg-success text-white'
                          : 'bg-surface-2 text-fg-subtle',
                    )}
                    aria-hidden="true"
                  >
                    {done ? '✓' : i + 1}
                  </span>
                  <span
                    className={cn(
                      'text-sm',
                      active ? 'font-semibold text-fg' : done ? 'text-fg-muted' : 'text-fg-subtle',
                    )}
                  >
                    {step.label}
                    {active && <span className="sr-only"> (current status)</span>}
                  </span>
                </li>
              );
            })}
          </ol>
        )}

        {result.needed_by && (
          <p className="text-sm text-fg-muted">
            Needed by {new Date(result.needed_by).toLocaleDateString()}
          </p>
        )}

        {result.items_total > 1 &&
          result.items_completed > 0 &&
          result.items_completed < result.items_total && (
            <p className="text-sm text-fg-muted">
              {result.items_completed} of {result.items_total} items shipped
            </p>
          )}

        {result.shipments?.length > 0 && (
          <div className="flex flex-col gap-1">
            <p className="text-2xs uppercase tracking-wide text-fg-subtle">Shipments</p>
            {result.shipments.map((s, i) => (
              <p key={i} className="text-sm text-fg">
                {s.tracking_url ? (
                  <a href={s.tracking_url} target="_blank" rel="noopener noreferrer" className="text-primary underline">
                    Track with {s.carrier_label ?? 'carrier'} ({s.ref})
                  </a>
                ) : (
                  <span>
                    {s.carrier_label ? `${s.carrier_label}: ` : ''}
                    {s.ref}
                  </span>
                )}
              </p>
            ))}
          </div>
        )}

        {result.updated_at && (
          <p className="text-xs text-fg-subtle">
            Last updated {new Date(result.updated_at).toLocaleString()}
          </p>
        )}
      </Card>
    </Motion>
  );
}
