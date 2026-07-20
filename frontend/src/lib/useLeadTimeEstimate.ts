import { useEffect, useState } from 'react';
import api, { ensureCsrf } from './api';
import type { LeadTimeEstimate } from '../types';

/**
 * Order-level delivery-window estimate for a set of products. Event-driven:
 * refetches whenever the product set changes. Failure is non-fatal — returns
 * null so callers can simply hide the window (and still let the buyer pick a
 * date). Shared by the designer (single product) and checkout (whole cart) so
 * both read the same "need it by" feasibility signal.
 */
export function useLeadTimeEstimate(productIds: number[]): LeadTimeEstimate | null {
  const [lead, setLead] = useState<LeadTimeEstimate | null>(null);
  // Stable primitive dep: the effect re-runs on membership change, not on every
  // new array identity from the caller's render.
  const key = productIds.join(',');

  useEffect(() => {
    if (productIds.length === 0) {
      setLead(null);
      return;
    }
    let active = true;
    // Ensure the Sanctum XSRF cookie exists before this stateful POST, so a
    // first-time anonymous visitor (no prior write) still gets the window
    // instead of a 419. Non-fatal: on any failure we just hide the estimate.
    void (async () => {
      try {
        await ensureCsrf();
        const { data } = await api.post<LeadTimeEstimate>('/lead-time-estimate', {
          line_items: productIds.map((product_id) => ({ product_id })),
        });
        if (active) setLead(data);
      } catch {
        if (active) setLead(null);
      }
    })();
    return () => {
      active = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key]);

  return lead;
}
