import { describe, it, expect, vi, beforeEach } from 'vitest';
import { useDashboardStore } from './dashboardStore';
import * as dash from '../lib/dashboard';

const payload: dash.DashboardPayload = {
  pipeline: { SENT: 2 },
  production: { byState: { READY: 1 }, wip: 0, overdue: 0 },
  atRisk: [],
  queues: { proofsPending: 3, procurementToReconfirm: 1, cataloguePending: 4, reordersOpen: 2 },
  activity: [],
  valueBooked: null,
};

beforeEach(() => {
  useDashboardStore.setState({ data: null, loading: false, error: null });
});

describe('dashboardStore', () => {
  it('loads the snapshot', async () => {
    vi.spyOn(dash, 'fetchDashboard').mockResolvedValue(payload);
    await useDashboardStore.getState().load();
    expect(useDashboardStore.getState().data?.queues.proofsPending).toBe(3);
    expect(useDashboardStore.getState().loading).toBe(false);
  });

  it('records an error on failure', async () => {
    vi.spyOn(dash, 'fetchDashboard').mockRejectedValue(new Error('boom'));
    await useDashboardStore.getState().load();
    expect(useDashboardStore.getState().error).toBeTruthy();
    expect(useDashboardStore.getState().data).toBeNull();
  });
});
