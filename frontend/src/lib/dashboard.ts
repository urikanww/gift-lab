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
