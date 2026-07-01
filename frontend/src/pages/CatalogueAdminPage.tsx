import { useEffect, useState } from 'react';
import { useCatalogueAdminStore } from '../stores/catalogueAdminStore';
import { useAuthStore } from '../stores/authStore';
import { AsyncBoundary } from '../components/ui/States';

export default function CatalogueAdminPage() {
  const { items, loading, error, fetch, publish, unpublish, setAutoPublish, autoPublishSaving } =
    useCatalogueAdminStore();
  const user = useAuthStore((s) => s.user);
  const isSuperadmin = user?.role === 'superadmin';
  const [autoPublish, setAutoPublishLocal] = useState(false);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  const toggleAutoPublish = async () => {
    const next = !autoPublish;
    setAutoPublishLocal(next);
    await setAutoPublish(next);
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
                    <button type="button" className="btn btn--primary" onClick={() => void publish(it.id)}>
                      Publish
                    </button>
                  )}
                  {it.publish_state === 'PUBLISHED' && (
                    <button type="button" className="btn btn--ghost" onClick={() => void unpublish(it.id)}>
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
