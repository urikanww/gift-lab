import api from './api';

export interface RevenueMonth {
  month: string;
  bookings: number;
  billed: number;
}
export interface TopProduct {
  productId: number;
  name: string | null;
  units: number;
  revenue: number;
}
export interface ReportsPayload {
  revenueTrend: RevenueMonth[];
  topProducts: TopProduct[];
  repeatCustomerRate: { activeCompanies: number; repeatCompanies: number; rate: number };
  range: { from: string; to: string };
}

export async function fetchReports(from: string, to: string): Promise<ReportsPayload> {
  const { data } = await api.get<ReportsPayload>('/admin/reports', { params: { from, to } });
  return data;
}

/** Absolute URL for the CSV export (plain-anchor download; Sanctum cookie authenticates). */
export function reportsExportUrl(from: string, to: string): string {
  const base = (api.defaults.baseURL ?? '').replace(/\/$/, '');
  const qs = new URLSearchParams({ from, to }).toString();
  return `${base}/admin/reports/export?${qs}`;
}
