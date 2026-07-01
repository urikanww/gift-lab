import { useEffect } from 'react';
import { useQueueStore } from '../stores/queueStore';
import { AsyncBoundary } from '../components/ui/States';
import type { JobState } from '../types';

const NEXT_STATE: Partial<Record<JobState, { label: string; to: JobState }>> = {
  READY: { label: 'Start production', to: 'IN_PRODUCTION' },
  IN_PRODUCTION: { label: 'Mark shipped', to: 'SHIPPED' },
  SHIPPED: { label: 'Close', to: 'CLOSED' },
};

export default function ProductionQueuePage() {
  const { jobs, loading, error, fetchQueue, advance, subscribe, unsubscribe } = useQueueStore();

  useEffect(() => {
    void fetchQueue();
    subscribe(); // live via Reverb; no polling
    return () => unsubscribe();
  }, [fetchQueue, subscribe, unsubscribe]);

  return (
    <section>
      <h1>Production queue</h1>
      <p className="muted">Shared FCFS queue by readiness — UV + 3D, no customer priority. Live.</p>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={jobs.length === 0}
        emptyTitle="Queue is clear."
        onRetry={fetchQueue}
      >
        <table className="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Quote</th>
              <th>Track</th>
              <th>Qty</th>
              <th>Ready at</th>
              <th>State</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {jobs.map((j) => {
              const next = NEXT_STATE[j.state];
              return (
                <tr key={j.id}>
                  <td>{j.id}</td>
                  <td>#{j.quote_id}</td>
                  <td>{j.track}</td>
                  <td>{j.qty}</td>
                  <td>{j.ready_at ? new Date(j.ready_at).toLocaleString() : '—'}</td>
                  <td>
                    <span className={`badge badge--${j.state.toLowerCase()}`}>{j.state}</span>
                  </td>
                  <td>
                    {next && (
                      <button type="button" className="btn" onClick={() => void advance(j.id, next.to)}>
                        {next.label}
                      </button>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </AsyncBoundary>
    </section>
  );
}
