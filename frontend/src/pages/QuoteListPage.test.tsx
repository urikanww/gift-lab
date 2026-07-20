import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ThemeProvider } from '../ui';
import QuoteListPage from './QuoteListPage';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';

// The store test below drives the REAL fetchQuotes, which would otherwise fire
// an XHR; the page tests seed their own fetchQuotes and never touch this.
vi.mock('../lib/api', () => ({
  default: { get: vi.fn(async () => ({ data: { data: [], meta: { current_page: 1, last_page: 1 } } })) },
  apiError: (e: unknown) => String(e),
  ensureCsrf: async () => {},
}));

const initialQuoteStore = useQuoteStore.getState();
const initialAuthStore = useAuthStore.getState();
afterEach(() => {
  // Unmount BEFORE restoring the real store: restoring first swaps the seeded
  // no-op fetchQuotes back to the real one while the page is mounted, re-running
  // the search effect (keyed on fetchQuotes identity) against the real API.
  cleanup();
  vi.useRealTimers();
  useQuoteStore.setState(initialQuoteStore, true);
  useAuthStore.setState(initialAuthStore, true);
});

const sampleQuote = {
  id: 42,
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

// Regression guard: the post-mutation refresh in the store re-fetches from
// store state. If the term lived only in the component it would be dropped
// there, silently resetting the user's filtered list back to everything.
it('keeps the search term in the store across a post-mutation refresh', async () => {
  await useQuoteStore.getState().fetchQuotes(1, 'ABC123');

  expect(useQuoteStore.getState().searchTerm).toBe('ABC123');
});
