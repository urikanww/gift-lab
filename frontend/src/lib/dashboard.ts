import api from './api';

export interface DashboardActivity {
  id: number;
  actor: string | null;
  event: string;
  auditableType: string;
  auditableId: number;
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
