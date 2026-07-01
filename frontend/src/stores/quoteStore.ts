import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import { getEcho } from '../lib/echo';
import type { CartLine, Proof, Quote, QuoteState } from '../types';

interface QuoteStateChangedPayload {
  quote_id: number;
  state: QuoteState;
  previous_state: QuoteState;
  total: string;
}

interface ProofStatusChangedPayload {
  proof_id: number;
  quote_id: number;
  version: number;
  state: Proof['state'];
}

interface QuoteStoreState {
  quotes: Quote[];
  current: Quote | null;
  loading: boolean;
  error: string | null;
  subscribedCompany: number | null;

  fetchQuotes: () => Promise<void>;
  fetchQuote: (id: number) => Promise<void>;
  createQuote: (companyId: number, lines: CartLine[], notes: string | null) => Promise<Quote | null>;
  send: (id: number) => Promise<void>;
  accept: (id: number) => Promise<void>;
  procure: (id: number) => Promise<void>;
  issueProof: (id: number, artworkRef: string, notes: string | null) => Promise<void>;
  decideProof: (proofId: number, decision: 'approve' | 'request_changes', notes: string | null) => Promise<void>;
  issuePurchaseOrder: (id: number, poRef: string, terms: string | null) => Promise<void>;
  subscribeCompany: (companyId: number) => void;
  unsubscribeCompany: () => void;
}

export const useQuoteStore = create<QuoteStoreState>((set, get) => ({
  quotes: [],
  current: null,
  loading: false,
  error: null,
  subscribedCompany: null,

  fetchQuotes: async () => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: Quote[] }>('/quotes');
      set({ quotes: data.data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  fetchQuote: async (id) => {
    set({ loading: true, error: null });
    try {
      const { data } = await api.get<{ data: Quote }>(`/quotes/${id}`);
      set({ current: data.data, loading: false });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  createQuote: async (companyId, lines, notes) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ data: Quote }>('/quotes', {
        company_id: companyId,
        notes,
        line_items: lines.map((l) => ({
          product_id: l.product.id,
          variant_id: l.variant?.id ?? null,
          qty: l.qty,
          customization: l.customization,
        })),
      });
      return data.data;
    } catch (err) {
      set({ error: apiError(err) });
      return null;
    }
  },

  send: async (id) => {
    await ensureCsrf();
    await api.post(`/quotes/${id}/send`);
    await get().fetchQuote(id);
  },

  accept: async (id) => {
    await ensureCsrf();
    await api.post(`/quotes/${id}/accept`);
    await get().fetchQuote(id);
  },

  procure: async (id) => {
    await ensureCsrf();
    await api.post(`/quotes/${id}/procure`);
    await get().fetchQuote(id);
  },

  issueProof: async (id, artworkRef, notes) => {
    await ensureCsrf();
    await api.post(`/quotes/${id}/proofs`, { artwork_version_ref: artworkRef, notes });
    await get().fetchQuote(id);
  },

  decideProof: async (proofId, decision, notes) => {
    await ensureCsrf();
    await api.post(`/proofs/${proofId}/decide`, { decision, notes });
    const current = get().current;
    if (current) await get().fetchQuote(current.id);
  },

  issuePurchaseOrder: async (id, poRef, terms) => {
    await ensureCsrf();
    await api.post(`/quotes/${id}/purchase-order`, { po_ref: poRef, terms });
    await get().fetchQuote(id);
  },

  subscribeCompany: (companyId) => {
    if (get().subscribedCompany === companyId) return;
    get().unsubscribeCompany();

    const echo = getEcho();
    echo
      .private(`company.${companyId}`)
      .listen('.quote.state-changed', (e: QuoteStateChangedPayload) => {
        set((s) => ({
          quotes: s.quotes.map((q) =>
            q.id === e.quote_id ? { ...q, state: e.state, total: e.total } : q,
          ),
          current:
            s.current && s.current.id === e.quote_id
              ? { ...s.current, state: e.state, total: e.total }
              : s.current,
        }));
      })
      .listen('.proof.status-changed', (e: ProofStatusChangedPayload) => {
        // Refetch the affected quote so proof list + state stay authoritative.
        const current = get().current;
        if (current && current.id === e.quote_id) {
          void get().fetchQuote(e.quote_id);
        }
      });

    set({ subscribedCompany: companyId });
  },

  unsubscribeCompany: () => {
    const companyId = get().subscribedCompany;
    if (companyId !== null) {
      getEcho().leave(`company.${companyId}`);
      set({ subscribedCompany: null });
    }
  },
}));
