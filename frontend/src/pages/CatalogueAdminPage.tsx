import { useEffect, useState } from 'react';
import { useCatalogueAdminStore } from '../stores/catalogueAdminStore';
import { useAuthStore } from '../stores/authStore';
import { AsyncBoundary } from '../components/ui/States';

export default function CatalogueAdminPage() {
  const { items, loading, error, fetch, publish, unpublish, setAutoPublish, autoPublish, autoPublishSaving } =
    useCatalogueAdminStore();
  const user = useAuthStore((s) => s.user);
  const isSuperadmin = user?.role === 'superadmin';
  const [pendingId, setPendingId] = useState<number | null>(null);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  // Toggle reads the store's server-hydrated value; the store only flips
  // `autoPublish` after the PATCH persists (and sets `error` on failure), so the
  // checkbox never shows an unsaved change.
  const toggleAutoPublish = () => void setAutoPublish(!autoPublish);

  // Single-flight guard so a rapid double-click can't fire publish/unpublish
  // twice on the same row.
  const runRow = async (id: number, fn: (id: number) => Promise<void>) => {
    if (pendingId !== null) return;
    setPendingId(id);
    try {
      await fn(id);
    } finally {
      setPendingId(null);
    }
  };

  return (
    <section>
      <div className="quote-detail__head">
        <h1>Catalogue gate</h1>
        {isSuperadmin && (
          <label className="field" style={{ margin: 0 }}>
            <span>
              <input type="checkbox" checked={autoPublish} disabled={autoPublishSaving} onChange={toggleAutoPublish} />{' '}
              Auto-publish complete items
            </span>
          </label>
        )}
      </div>
      <p className="muted">Review scraped-UV and 3D items. Publish complete/licence-cleared items; pull drifted ones.</p>

      <AsyncBoundary
        loading={loading}
        error={error}
        isEmpty={items.length === 0}
        emptyTitle="Nothing awaiting review."
        onRetry={fetch}
      >
        <table className="table">
          <thead>
            <tr>
              <th>Item</th>
              <th>Class</th>
              <th>State</th>
              <th>Blockers</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {items.map((it) => (
              <tr key={it.id}>
                <td>
                  {it.name}
                  {it.creator_credit && <div className="credit">by {it.creator_credit}</div>}
                </td>
                <td>{it.class}</td>
                <td>
                  <span className={`badge badge--${it.publish_state.toLowerCase()}`}>{it.publish_state}</span>
                </td>
                <td>
                  {it.cannot_publish_reasons?.length
                    ? it.cannot_publish_reasons.map((r) => (
                        <span key={r} className="badge badge--awaiting_reconfirm" style={{ marginRight: 4 }}>
                          {r}
                        </span>
                      ))
                    : '—'}
                </td>
                <td>
                  {it.publish_state === 'READY_TO_APPROVE' && (
                    <button
                      type="button"
                      className="btn btn--primary"
                      disabled={pendingId !== null}
                      onClick={() => void runRow(it.id, publish)}
                    >
                      Publish
                    </button>
                  )}
                  {it.publish_state === 'PUBLISHED' && (
                    <button
                      type="button"
                      className="btn btn--ghost"
                      disabled={pendingId !== null}
                      onClick={() => void runRow(it.id, unpublish)}
                    >
                      Unpublish
                    </button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </AsyncBoundary>
    </section>
  );
}
