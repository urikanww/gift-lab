import { useEffect, useState } from 'react';
import { useProcurementStore } from '../stores/procurementStore';
import { EmptyState } from '../components/ui/States';

export default function ProcurementPage() {
  const { alerts, subscribe, unsubscribe, reconfirm } = useProcurementStore();
  const [amend, setAmend] = useState<Record<number, { qty: number; unit_price: number }>>({});

  useEffect(() => {
    subscribe(); // live awaiting-reconfirm alerts via Reverb
    return () => unsubscribe();
  }, [subscribe, unsubscribe]);

  const setAmendField = (id: number, field: 'qty' | 'unit_price', value: number) =>
    setAmend((s) => {
      const prev = s[id] ?? { qty: 0, unit_price: 0 };
      return { ...s, [id]: { ...prev, [field]: value } };
    });

  if (alerts.length === 0) {
    return (
      <EmptyState title="No lines awaiting reconfirmation.">
        <p className="muted">Qty shortfalls and price jumps from stock re-checks appear here in real time.</p>
      </EmptyState>
    );
  }

  return (
    <section>
      <h1>Procurement desk</h1>
      <p className="muted">Resolve lines flagged during the stock/price re-check. One line never blocks the rest.</p>

      <ul className="alerts">
        {alerts.map((a) => (
          <li key={a.line_item_id} className="alert">
            <div className="alert__body">
              <strong>Line #{a.line_item_id}</strong> (Quote #{a.quote_id}) —{' '}
              <span className="badge badge--awaiting_reconfirm">{a.reason}</span>
              <div className="muted">
                Ordered {a.ordered_qty}, procurable {a.procured_qty ?? '—'} · quoted {a.unit_price}, re-checked{' '}
                {a.procured_price ?? '—'}
              </div>
            </div>

            <div className="alert__actions">
              <div className="inline-form">
                <input
                  type="number"
                  min={1}
                  placeholder="qty"
                  className="qty-input"
                  value={amend[a.line_item_id]?.qty ?? ''}
                  onChange={(e) => setAmendField(a.line_item_id, 'qty', Number(e.target.value))}
                />
                <input
                  type="number"
                  min={0}
                  step="0.01"
                  placeholder="unit price"
                  className="qty-input"
                  value={amend[a.line_item_id]?.unit_price ?? ''}
                  onChange={(e) => setAmendField(a.line_item_id, 'unit_price', Number(e.target.value))}
                />
                <button
                  type="button"
                  className="btn"
                  disabled={!amend[a.line_item_id]?.qty || !amend[a.line_item_id]?.unit_price}
                  onClick={() => void reconfirm(a.line_item_id, 'amend', amend[a.line_item_id])}
                >
                  Amend & re-procure
                </button>
              </div>
              <button type="button" className="btn btn--primary" onClick={() => void reconfirm(a.line_item_id, 'approve')}>
                Accept as-is
              </button>
              <button type="button" className="btn btn--ghost" onClick={() => void reconfirm(a.line_item_id, 'drop')}>
                Drop line
              </button>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}
