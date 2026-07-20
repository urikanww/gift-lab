import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import QuoteListPage from './QuoteListPage';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';
import api from '../lib/api';

// The store tests below drive the REAL fetchQuotes, which would otherwise fire
// an XHR; the page tests seed their own fetchQuotes and never touch this.
vi.mock('../lib/api', () => ({
  default: { get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, last_page: 1 } } })) },
  apiError: (e: unknown) => String(e),
  ensureCsrf: async () => {},
}));

// Only the websocket transport is faked. onEchoReconnect is a plain handler
// registry, so capturing what the store registers lets the reconnect test
// invoke the store's OWN closure - the asserted logic is real store code.
let capturedReconnect: (() => void) | null = null;
vi.mock('../lib/echo', () => ({
  onEchoReconnect: (handler: () => void) => {
    capturedReconnect = handler;
    return () => {
      capturedReconnect = null;
    };
  },
  joinSharedPrivate: () => {
    const channel = { listen: () => channel };
    return channel;
  },
  leaveSharedPrivate: () => {},
}));

const initialQuoteStore = useQuoteStore.getState();
const initialAuthStore = useAuthStore.getState();
afterEach(() => {
  // Unmount BEFORE restoring the real store: restoring first swaps the seeded
  // no-op fetchQuotes back to the real one while the page is mounted, re-running
  // the search effect (keyed on fetchQuotes identity) against the real API.
  cleanup();
  vi.useRealTimers();
  capturedReconnect = null;
  useQuoteStore.setState(initialQuoteStore, true);
  useAuthStore.setState(initialAuthStore, true);
});

const sampleQuote = {
  id: 42,
  reference: '9BWVKWCDXH',
  company_id: 7,
  company_name: 'Acme Gifts Pte Ltd',
  state: 'SENT',
  currency: 'SGD',
  subtotal: '100.00',
  delivery: '5.00',
  total: '105.00',
  price_snapshot_at: null,
  notes: null,
  created_at: '2026-07-01T00:00:00Z',
} as any;

function seedQuotes() {
  useQuoteStore.setState({
    quotes: [sampleQuote],
    loading: false,
    error: null,
    page: 1,
    lastPage: 1,
    fetchQuotes: async () => {},
  } as any);
}

function renderPage() {
  return render(
    <ThemeProvider>
      <MemoryRouter>
        <QuoteListPage />
      </MemoryRouter>
    </ThemeProvider>,
  );
}

it('shows the company column and staff copy for staff', () => {
  seedQuotes();
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'Ops', email: 'ops@x.test', role: 'staff_admin' },
    status: 'ready',
    error: null,
  });

  renderPage();

  expect(screen.getByText('Company')).toBeInTheDocument();
  // Rendered in both the desktop table and the mobile card list.
  expect(screen.getAllByText('Acme Gifts Pte Ltd').length).toBeGreaterThanOrEqual(1);
  expect(screen.getByText(/across every company/i)).toBeInTheDocument();
  // Staff keep the operational "Quotes" title.
  expect(screen.getByRole('heading', { name: 'Quotes' })).toBeInTheDocument();
});

it('hides the company column and keeps buyer copy for buyers', () => {
  seedQuotes();
  useAuthStore.setState({
    user: { id: 2, company_id: 7, name: 'Ada', email: 'ada@x.test', role: 'buyer' },
    status: 'ready',
    error: null,
  });

  renderPage();

  expect(screen.queryByText('Company')).not.toBeInTheDocument();
  expect(screen.queryByText('Acme Gifts Pte Ltd')).not.toBeInTheDocument();
  expect(screen.getByText(/track your gift orders/i)).toBeInTheDocument();
  // Buyers arrive via the "My Orders" nav item - the title matches it.
  expect(screen.getByRole('heading', { name: 'My Orders' })).toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: 'Quotes' })).not.toBeInTheDocument();
});

// userEvent is avoided in these two: its internal waits deadlock against fake
// timers. fireEvent drives one React state update per call, which is exactly
// the "one keystroke, one effect re-run" the debounce has to collapse.
function searchBox() {
  return screen.getByRole('searchbox', { name: /search orders/i });
}

async function tick(ms: number) {
  await act(async () => {
    vi.advanceTimersByTime(ms);
  });
}

it('passes the typed term to fetchQuotes', async () => {
  vi.useFakeTimers();
  const fetchQuotes = vi.fn(async () => {});
  seedQuotes();
  useQuoteStore.setState({ fetchQuotes } as any);
  renderPage();

  fireEvent.change(searchBox(), { target: { value: 'ABC123' } });
  await tick(300);

  expect(fetchQuotes).toHaveBeenCalledWith(1, 'ABC123');
});

it('debounces typing into one request rather than one per keystroke', async () => {
  vi.useFakeTimers();
  const fetchQuotes = vi.fn(async () => {});
  seedQuotes();
  useQuoteStore.setState({ fetchQuotes } as any);
  renderPage();

  // Six keystrokes inside the debounce window, plus the mount run. Every re-run
  // must CANCEL the pending timer rather than merely delay its own - without
  // the clearTimeout this is seven requests.
  for (const value of ['A', 'AB', 'ABC', 'ABC1', 'ABC12', 'ABC123']) {
    fireEvent.change(searchBox(), { target: { value } });
    await tick(50);
  }
  await tick(300);

  expect(fetchQuotes).toHaveBeenCalledTimes(1);
  expect(fetchQuotes).toHaveBeenCalledWith(1, 'ABC123');
});

// The effect keys on the TRIMMED term, so an edit that leaves it identical must
// not re-fetch. It always fetches page 1, so a stray re-run would bounce a user
// sitting on page 2 of filtered results back to page 1 for nothing.
it('does not re-fetch when an edit leaves the trimmed term unchanged', async () => {
  vi.useFakeTimers();
  const fetchQuotes = vi.fn(async () => {});
  seedQuotes();
  useQuoteStore.setState({ fetchQuotes } as any);
  renderPage();

  fireEvent.change(searchBox(), { target: { value: 'ABC' } });
  await tick(300);
  fireEvent.change(searchBox(), { target: { value: 'ABC ' } });
  await tick(300);

  expect(fetchQuotes).toHaveBeenCalledTimes(1);
  expect(fetchQuotes).toHaveBeenCalledWith(1, 'ABC');
});

// A filtered miss must not claim the order history is empty: a buyer with a
// dozen orders who mistypes a reference would otherwise read "No quotes yet -
// Once you request a quote from your cart, it will appear here."
it('shows search-specific empty copy when a term matches nothing', async () => {
  vi.useFakeTimers();
  const fetchQuotes = vi.fn(async () => {});
  seedQuotes();
  useQuoteStore.setState({ quotes: [], fetchQuotes } as any);
  renderPage();

  fireEvent.change(searchBox(), { target: { value: 'ZZZNOPE' } });
  await tick(300);

  expect(screen.getByText(/no orders match that search/i)).toBeInTheDocument();
  // Scoped to the description: the sr-only status region names the term too.
  expect(screen.getByText(/nothing matches "ZZZNOPE"/i)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /clear search/i })).toBeInTheDocument();
  // The no-orders-at-all copy must NOT appear - that is the false, alarming one.
  expect(screen.queryByText(/no quotes yet/i)).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /browse catalogue/i })).not.toBeInTheDocument();
});

// WCAG 4.1.3: focus stays in the input while the list is swapped underneath it,
// and the skeleton is aria-hidden, so the result count must be announced.
it('announces the result count to screen readers', async () => {
  vi.useFakeTimers();
  const fetchQuotes = vi.fn(async () => {});
  seedQuotes();
  useQuoteStore.setState({ fetchQuotes } as any);
  renderPage();

  // Continuously mounted, so it is already present before any search runs.
  expect(screen.getByRole('status')).toHaveTextContent('1 order');

  fireEvent.change(searchBox(), { target: { value: 'ZZZNOPE' } });
  await tick(300);
  // act() so the store update actually flushes to the DOM before asserting.
  await act(async () => {
    useQuoteStore.setState({ quotes: [] } as any);
  });

  expect(screen.getByRole('status')).toHaveTextContent('0 orders matching "ZZZNOPE"');
});

// Inverse, guarding against the condition being flipped: with no term, a
// genuinely empty list keeps the original onboarding copy.
it('keeps the no-orders-yet copy when the list is empty with no search term', () => {
  seedQuotes();
  useQuoteStore.setState({ quotes: [] } as any);
  renderPage();

  expect(screen.getByText(/no quotes yet/i)).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /browse catalogue/i })).toBeInTheDocument();
  expect(screen.queryByText(/match that search/i)).not.toBeInTheDocument();
});

// Paging must re-send the term. fetchQuotes writes its `term` argument to the
// store unconditionally, so paging without it does not merely show unfiltered
// rows - it wipes the stored term while the text is still in the input.
it('carries the active search term when paging to the next page', async () => {
  vi.useFakeTimers();
  const fetchQuotes = vi.fn(async () => {});
  seedQuotes();
  useQuoteStore.setState({ fetchQuotes, page: 1, lastPage: 2 } as any);
  renderPage();

  fireEvent.change(searchBox(), { target: { value: 'ABC123' } });
  await tick(300);
  fireEvent.click(screen.getByRole('button', { name: /next/i }));

  expect(fetchQuotes).toHaveBeenCalledWith(2, 'ABC123');
  expect(fetchQuotes).not.toHaveBeenCalledWith(2, undefined);
});

// The store must record the term at all, which is what makes the reconnect
// refetch below able to re-apply it.
it('records the search term in the store when fetching', async () => {
  await useQuoteStore.getState().fetchQuotes(1, 'ABC123');

  expect(useQuoteStore.getState().searchTerm).toBe('ABC123');
});

// The query string IS the contract with QuoteController::index. Nothing else in
// this branch exercises the real endpoint, so pin the shape here.
it('sends q only when a term is set', async () => {
  const apiGet = vi.mocked(api.get);
  apiGet.mockClear();

  await useQuoteStore.getState().fetchQuotes(2, 'ABC123');
  expect(apiGet).toHaveBeenLastCalledWith('/quotes', { params: { page: 2, q: 'ABC123' } });

  await useQuoteStore.getState().fetchQuotes(1);
  expect(apiGet).toHaveBeenLastCalledWith('/quotes', { params: { page: 1 } });
});

// Out-of-order responses. searchTerm is written at request START and rows at
// RESOLVE, so an old slow response landing after a newer one would leave the
// store holding rows that its own searchTerm did not produce.
it('ignores a stale response that resolves after a newer request', async () => {
  const apiGet = vi.mocked(api.get);
  let resolveOld!: (value: unknown) => void;
  let resolveNew!: (value: unknown) => void;
  const page = (id: number) => ({
    data: { data: [{ id }], meta: { current_page: 1, last_page: 1 } },
  });
  apiGet
    .mockReturnValueOnce(new Promise((resolve) => (resolveOld = resolve)) as never)
    .mockReturnValueOnce(new Promise((resolve) => (resolveNew = resolve)) as never);

  const oldFetch = useQuoteStore.getState().fetchQuotes(1, 'OLD');
  const newFetch = useQuoteStore.getState().fetchQuotes(1, 'NEW');

  // The newer request wins the race, then the older one finally lands.
  resolveNew(page(2));
  await newFetch;
  resolveOld(page(1));
  await oldFetch;

  expect(useQuoteStore.getState().quotes).toEqual([{ id: 2 }]);
  expect(useQuoteStore.getState().searchTerm).toBe('NEW');
});

// The reason the term lives in the store at all. subscribeCompany registers a
// refetch that runs when the socket reconnects after a drop; it reads page and
// term from store state, so a component-local term would be invisible to it and
// the user's filtered list would silently reset to every order.
it('re-applies the search term when the socket reconnects', async () => {
  const fetchQuotes = vi.fn(async () => {});
  useQuoteStore.setState({
    fetchQuotes,
    page: 2,
    searchTerm: 'ABC123',
    current: null,
    subscribedCompany: null,
  } as any);

  useQuoteStore.getState().subscribeCompany(7);
  expect(capturedReconnect).toBeTypeOf('function');

  // Fire the store's own reconnect closure, as lib/echo would on re-connect.
  capturedReconnect!();

  expect(fetchQuotes).toHaveBeenCalledWith(2, 'ABC123');
  expect(fetchQuotes).not.toHaveBeenCalledWith(2, undefined);
});

it('identifies orders by reference, never by the sequential id', () => {
  seedQuotes();
  useAuthStore.setState({
    user: { id: 2, company_id: 7, name: 'Ada', email: 'ada@x.test', role: 'buyer' },
    status: 'ready',
    error: null,
  });

  renderPage();

  // Positive control: the reference IS on screen (desktop row + mobile card),
  // so deleting the identifier outright could not pass this test.
  expect(screen.getAllByText(/9BWVKWCDXH/).length).toBeGreaterThan(0);
  // A stray "#42" anywhere means a surface was missed.
  expect(screen.queryByText(/#\d+/)).not.toBeInTheDocument();
});
