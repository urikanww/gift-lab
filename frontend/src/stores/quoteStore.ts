import { create } from 'zustand';
import api, { apiError, ensureCsrf } from '../lib/api';
import { joinSharedPrivate, leaveSharedPrivate, onEchoReconnect } from '../lib/echo';
import type {
  CartLine,
  Paginated,
  Proof,
  Quote,
  QuoteState,
  QuoteSummary,
  ShippingAddressInput,
} from '../types';

// Unregister handle for the reconnect-refetch subscription.
let offReconnect: (() => void) | null = null;

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
  page: number;
  lastPage: number;
  subscribedCompany: number | null;
  summary: QuoteSummary | null;
  /**
   * Active orders-list search term, forwarded as `?q=`. Lives here rather than
   * in QuoteListPage because the reconnect refresh in subscribeCompany re-fetches
   * the list from store state: a component-local term would be invisible there,
   * so a socket drop would silently reset the user's filtered list to every order
   * while their term sat in the input.
   */
  searchTerm: string | undefined;

  /** `term` filters by partial reference or exact id; omitted means no filter. */
  fetchQuotes: (page?: number, term?: string) => Promise<void>;
  fetchSummary: () => Promise<void>;
  /** Accepts an opaque order reference (buyer URLs) or a numeric id. */
  fetchQuote: (idOrRef: string | number) => Promise<void>;
  createQuote: (
    companyId: number,
    lines: CartLine[],
    notes: string | null,
    neededBy?: string | null,
    idempotencyKey?: string | null,
    shippingAddress?: ShippingAddressInput | null,
  ) => Promise<Quote | null>;
  send: (id: number, proof?: { artwork_version_ref: string; notes?: string }) => Promise<void>;
  accept: (id: number) => Promise<void>;
  procure: (id: number) => Promise<void>;
  issueProof: (id: number, artworkRef: string, notes: string | null) => Promise<void>;
  decideProof: (proofId: number, decision: 'approve' | 'request_changes', notes: string | null) => Promise<void>;
  issueInvoice: (id: number, poRef: string, terms: string | null) => Promise<void>;
  /** Resolves true when payment was captured immediately (no redirect). */
  payNow: (id: number) => Promise<boolean>;
  /** Resolves true on success so the confirm modal only closes when the cancel actually landed. */
  cancelQuote: (id: number, reason?: string) => Promise<boolean>;
  subscribeCompany: (companyId: number) => void;
  unsubscribeCompany: () => void;
}

export const useQuoteStore = create<QuoteStoreState>((set, get) => ({
  quotes: [],
  current: null,
  loading: false,
  error: null,
  page: 1,
  lastPage: 1,
  subscribedCompany: null,
  summary: null,
  searchTerm: undefined,

  fetchQuotes: async (page = 1, term) => {
    set({ loading: true, error: null, searchTerm: term });
    try {
      // Omitted rather than sent empty: the API treats a blank q as no filter,
      // but keeping it out of the query string keeps the URL honest.
      const { data } = await api.get<Paginated<Quote>>('/quotes', {
        params: term ? { page, q: term } : { page },
      });
      set({
        quotes: data.data,
        page: data.meta?.current_page ?? page,
        lastPage: data.meta?.last_page ?? 1,
        loading: false,
      });
    } catch (err) {
      set({ loading: false, error: apiError(err) });
    }
  },

  fetchSummary: async () => {
    // Non-fatal for the dashboard: on failure the tiles simply stay empty.
    try {
      const { data } = await api.get<QuoteSummary>('/quotes/summary');
      set({ summary: data });
    } catch {
      set({ summary: null });
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

  createQuote: async (companyId, lines, notes, neededBy = null, idempotencyKey = null, shippingAddress = null) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ data: Quote }>('/quotes', {
        company_id: companyId,
        notes,
        // Omit when unset so the API sees a true absence, not an empty string.
        needed_by: neededBy || null,
        // Same cart re-submitted (double click / retry) returns the original
        // quote server-side instead of creating a duplicate draft.
        idempotency_key: idempotencyKey,
        // Buyer's checkout ship-to; snapshotted server-side onto the quote.
        shipping_address: shippingAddress,
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

  // Every write-path action sets `error` on failure instead of leaking an
  // unhandled promise rejection (the page awaits these in a try/finally with no
  // catch). On success the affected quote is refetched so state stays truthful
  // even if the Reverb broadcast is missed.
  send: async (id, proof) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/quotes/${id}/send`, proof ?? {});
      await get().fetchQuote(id);
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  accept: async (id) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/quotes/${id}/accept`);
      await get().fetchQuote(id);
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  procure: async (id) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/quotes/${id}/procure`);
      await get().fetchQuote(id);
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  issueProof: async (id, artworkRef, notes) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/quotes/${id}/proofs`, { artwork_version_ref: artworkRef, notes });
      await get().fetchQuote(id);
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  decideProof: async (proofId, decision, notes) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/proofs/${proofId}/decide`, { decision, notes });
      const current = get().current;
      if (current) await get().fetchQuote(current.id);
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  issueInvoice: async (id, poRef, terms) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/quotes/${id}/invoice`, { po_ref: poRef, terms });
      await get().fetchQuote(id);
    } catch (err) {
      set({ error: apiError(err) });
    }
  },

  payNow: async (id) => {
    set({ error: null });
    try {
      await ensureCsrf();
      const { data } = await api.post<{ checkout_url: string; paid: boolean }>(`/quotes/${id}/pay`);
      if (data.paid) {
        // Fixture/dev: captured immediately - refresh to show production status
        // and report success so the caller can confirm (no redirect happens).
        await get().fetchQuote(id);
        return true;
      }
      // Stripe: redirect to hosted checkout.
      window.location.href = data.checkout_url;
    } catch (err) {
      // Payment provider / gateway failure: surface friendly copy so the pay
      // button never freezes on an unhandled rejection.
      set({ error: apiError(err) });
    }
    return false;
  },

  cancelQuote: async (id, reason) => {
    set({ error: null });
    try {
      await ensureCsrf();
      await api.post(`/quotes/${id}/cancel`, { reason });
      await get().fetchQuote(id);
      return true;
    } catch (err) {
      set({ error: apiError(err) });
      return false;
    }
  },

  subscribeCompany: (companyId) => {
    if (get().subscribedCompany === companyId) return;
    get().unsubscribeCompany();

    // Reconcile after a socket reconnect: refresh the list and the open quote so
    // any state-changed/proof events missed while offline are picked up.
    offReconnect = onEchoReconnect(() => {
      // Carry the active search through the reconnect refetch, or a socket drop
      // silently resets the user's filtered list to every order while their term
      // sits in the box.
      void get().fetchQuotes(get().page, get().searchTerm);
      const current = get().current;
      if (current) void get().fetchQuote(current.id);
    });

    // Shared refcounted membership: other stores may listen on the same
    // company channel, so never tear it down directly (see lib/echo).
    joinSharedPrivate(`company.${companyId}`)
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
      offReconnect?.();
      offReconnect = null;
      leaveSharedPrivate(`company.${companyId}`);
      set({ subscribedCompany: null });
    }
  },
}));
