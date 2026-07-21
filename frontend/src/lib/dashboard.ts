import api from './api';

export interface DashboardActivity {
  id: number;
  actor: string | null;
  event: string;
  auditableType: string;
  /** Join key. Kept alongside the label - other callers may key off it. */
  auditableId: number;
  /**
   * Ready-to-print identity for the row, composed server-side: "Order 9BWVKWCDXH"
   * for a Quote, "Product #12" for every other audited type. The feed is generic
   * over auditableType, so it must not carry per-type naming rules of its own.
   */
  auditableLabel: string;
  at: string | null;
}

export interface DashboardAtRisk {
  jobId: number;
  quoteId: number;
  /**
   * Display identity. camelCase here (not the Resources' snake_case
   * quote_reference) because this feed is a hand-built projection in
   * DashboardMetrics::atRisk, keyed like its jobId/quoteId neighbours.
   * quoteId remains the join key.
   */
  quoteReference?: string | null;
  track: string;
  state: string;
  readyAt: string | null;
}

export interface DashboardPayload {
  pipeline: Record<string, number>;
  production: { byState: Record<string, number>; wip: number; overdue: number };
  atRisk: DashboardAtRisk[];
  queues: {
    proofsPending: number;
    procurementToReconfirm: number;
    cataloguePending: number;
    reordersOpen: number;
  };
  activity: DashboardActivity[];
  valueBooked: { currency: string; amount: number } | null;
}

export async function fetchDashboard(): Promise<DashboardPayload> {
  const { data } = await api.get<DashboardPayload>('/admin/dashboard');
  return data;
}
