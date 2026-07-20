import { Badge, Input } from '../../ui';
import type { LeadTimeEstimate } from '../../types';

const fmtDate = (d: string) => new Date(d).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const todayISO = () => new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD, local

interface Props {
  /** Order-level delivery window; null while loading or if the estimate failed. */
  lead: LeadTimeEstimate | null;
  /** The buyer's chosen deadline (YYYY-MM-DD), or '' for none. */
  value: string;
  onChange: (next: string) => void;
}

type Feasibility = 'none' | 'on_track' | 'tight' | 'at_risk';

/**
 * Grade the chosen deadline against the standard window:
 * - on_track: on/after the latest estimated arrival — comfortably safe.
 * - tight:    within the window (earliest ≤ date < latest) — achievable, not guaranteed.
 * - at_risk:  before the earliest arrival — not makeable on standard; needs rush.
 */
function grade(value: string, lead: LeadTimeEstimate): Feasibility {
  if (value === '') return 'none';
  if (value >= lead.latest) return 'on_track';
  if (value >= lead.earliest) return 'tight';
  return 'at_risk';
}

/**
 * Order-level "need it by" date plus delivery-window feasibility. Shared by the
 * designer and checkout so both read/write the same cart deadline with identical
 * UX. Without an estimate it degrades to a plain optional date picker.
 */
export default function NeedByField({ lead, value, onChange }: Props) {
  if (!lead) {
    return (
      <Input
        type="date"
        label="Need it by (optional)"
        value={value}
        min={todayISO()}
        onChange={(e) => onChange(e.target.value)}
      />
    );
  }

  const state = grade(value, lead);

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-fg">Estimated delivery</p>
          <p className="text-sm text-fg-muted">
            Arrives {fmtDate(lead.earliest)} – {fmtDate(lead.latest)}
          </p>
        </div>
        {state === 'on_track' && (
          <Badge tone="success" dot>
            On track
          </Badge>
        )}
        {state === 'tight' && (
          <Badge tone="warning" dot>
            Tight
          </Badge>
        )}
        {state === 'at_risk' && (
          <Badge tone="danger" dot>
            At risk
          </Badge>
        )}
      </div>

      <Input
        type="date"
        label="Need it by (optional)"
        value={value}
        min={todayISO()}
        onChange={(e) => onChange(e.target.value)}
      />

      {state === 'tight' && (
        <p className="text-sm text-warning">
          Cutting it close — standard arrival is {fmtDate(lead.earliest)}–{fmtDate(lead.latest)}, so{' '}
          {fmtDate(value)} isn’t guaranteed.
        </p>
      )}
      {state === 'at_risk' && (
        <p className="text-sm text-danger">
          {fmtDate(value)} is before our earliest ({fmtDate(lead.earliest)}).
          {lead.rush_available && lead.rush_earliest
            ? ` Rush can arrive ${fmtDate(lead.rush_earliest)}${
                lead.rush_fee ? ` (+SGD ${lead.rush_fee.toFixed(2)})` : ''
              } — ask us to add it.`
            : ' We may not make this date — talk to us first.'}
        </p>
      )}
    </div>
  );
}
