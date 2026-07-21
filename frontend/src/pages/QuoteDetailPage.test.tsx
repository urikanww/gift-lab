import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';

// StatusHistory renders inside this page and fetches on mount. Stub the network
// so every test here is offline, and so the refetch test below can count calls.
const fetchQuoteHistory = vi.fn(async (_reference: string) => [] as unknown[]);
vi.mock('../lib/quotes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/quotes')>()),
  fetchQuoteHistory: (reference: string) => fetchQuoteHistory(reference),
}));

import { ThemeProvider, ToastProvider } from '../ui';
import QuoteDetailPage from './QuoteDetailPage';
import { useAuthStore } from '../stores/authStore';
import { useQuoteStore } from '../stores/quoteStore';
import type { QuoteState } from '../types';

const initialQuoteStore = useQuoteStore.getState();
const initialAuthStore = useAuthStore.getState();
afterEach(() => {
  // Unmount BEFORE restoring the real store. Restoring first swaps the seeded
  // no-op fetchQuote back to the real one while the page is still mounted; its
  // effect (keyed on fetchQuote identity) re-runs and fires a real XHR whose
  // late rejection pollutes the next test with a store-level error.
  cleanup();
  useQuoteStore.setState(initialQuoteStore, true);
  useAuthStore.setState(initialAuthStore, true);
  fetchQuoteHistory.mockClear();
});

function seedQuote(state: QuoteState) {
  useQuoteStore.setState({
    current: {
      id: 42,
      company_id: 7,
      reference: '9BWVKWCDXH',
      state,
      currency: 'SGD',
      subtotal: '100.00',
      delivery: '5.00',
      total: '105.00',
      line_items: [],
      proofs: [],
      created_at: '2026-07-01T00:00:00Z',
    },
    loading: false,
    error: null,
    fetchQuote: async () => {},
  } as any);
}

function asBuyer() {
  useAuthStore.setState({
    user: { id: 2, company_id: 7, name: 'Ada', email: 'ada@x.test', role: 'buyer' },
    status: 'ready',
    error: null,
  } as any);
}

function asStaff() {
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'Ops', email: 'ops@x.test', role: 'staff_admin' },
    status: 'ready',
    error: null,
  } as any);
}

function renderPage() {
  return render(
    <ThemeProvider>
      <ToastProvider>
        <MemoryRouter initialEntries={['/quotes/42']}>
          <Routes>
            <Route path="/quotes/:id" element={<QuoteDetailPage />} />
          </Routes>
        </MemoryRouter>
      </ToastProvider>
    </ThemeProvider>,
  );
}

function seedOpenProof() {
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      proofs: [
        {
          id: 9,
          quote_id: 42,
          version: 1,
          artwork_version_ref: 'proofs/v1.pdf',
          state: 'SENT',
          approved_by: null,
          approved_at: null,
          notes: null,
        },
      ],
    },
  } as any);
}

it('lets the buyer say what to change when requesting proof changes', async () => {
  const decideProof = vi.fn(async () => {});
  seedQuote('PROOFING');
  seedOpenProof();
  useQuoteStore.setState({ decideProof } as any);
  asBuyer();
  renderPage();

  // The note is not sent until the buyer confirms in the inline reveal.
  await userEvent.click(screen.getByRole('button', { name: /request changes/i }));
  expect(decideProof).not.toHaveBeenCalled();

  await userEvent.type(screen.getByLabelText(/what should we change/i), 'Use the darker blue.');
  await userEvent.click(screen.getByRole('button', { name: /send request/i }));

  expect(decideProof).toHaveBeenCalledWith(9, 'request_changes', 'Use the darker blue.');
});

it('falls back to a generic note when the change reason is left blank', async () => {
  const decideProof = vi.fn(async () => {});
  seedQuote('PROOFING');
  seedOpenProof();
  useQuoteStore.setState({ decideProof } as any);
  asBuyer();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /request changes/i }));
  await userEvent.click(screen.getByRole('button', { name: /send request/i }));

  // API requires a note with request_changes - the UI supplies one.
  expect(decideProof).toHaveBeenCalledWith(9, 'request_changes', 'Please revise.');
});

it('toasts "Payment received" when payment captures immediately', async () => {
  seedQuote('PROOF_APPROVED');
  useQuoteStore.setState({ payNow: async () => true } as any);
  asBuyer();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /pay now/i }));

  expect(await screen.findByText('Payment received')).toBeInTheDocument();
});

it('rejects an artwork storage key containing spaces without calling the API', async () => {
  const issueProof = vi.fn(async () => {});
  seedQuote('ACCEPTED');
  useQuoteStore.setState({ issueProof } as any);
  asStaff();
  renderPage();

  await userEvent.type(screen.getByLabelText(/artwork reference/i), 'proofs/my file.pdf');
  await userEvent.click(screen.getByRole('button', { name: /issue proof/i }));

  expect(issueProof).not.toHaveBeenCalled();
  expect(screen.getByText(/cannot contain spaces/i)).toBeInTheDocument();
});

it('rejects a whitespace-only PO reference without calling the API', async () => {
  const issueInvoice = vi.fn(async () => {});
  seedQuote('PROOF_APPROVED');
  useQuoteStore.setState({ issueInvoice } as any);
  asStaff();
  renderPage();

  await userEvent.type(screen.getByLabelText(/po reference/i), '   ');
  await userEvent.click(screen.getByRole('button', { name: /issue invoice/i }));

  expect(issueInvoice).not.toHaveBeenCalled();
  expect(screen.getByText(/enter the po number/i)).toBeInTheDocument();
});

it('sends a plain quote when staff leaves the artwork reference blank on DRAFT', async () => {
  const send = vi.fn(async () => {});
  seedQuote('DRAFT');
  useQuoteStore.setState({ send } as any);
  asStaff();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  expect(send).toHaveBeenCalledWith(42);
});

it('posts the artwork ref when sending with a proof from DRAFT', async () => {
  const send = vi.fn(async () => {});
  seedQuote('DRAFT');
  useQuoteStore.setState({ send } as any);
  asStaff();
  renderPage();

  await userEvent.type(screen.getByLabelText(/attach proof/i), 'proofs/v1.pdf');
  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  expect(send).toHaveBeenCalledWith(42, { artwork_version_ref: 'proofs/v1.pdf' });
});

it('clears the DRAFT proof field after a successful send-with-proof', async () => {
  const send = vi.fn(async () => {});
  seedQuote('DRAFT');
  useQuoteStore.setState({ send } as any);
  asStaff();
  renderPage();

  const field = screen.getByLabelText(/attach proof/i);
  await userEvent.type(field, 'proofs/v1.pdf');
  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  await waitFor(() => expect(field).toHaveValue(''));
});

it('keeps the DRAFT proof field when the send-with-proof fails', async () => {
  // send() swallows errors into store.error and never rejects; the field must
  // survive so the user can retry without re-typing.
  const send = vi.fn(async () => {
    useQuoteStore.setState({ error: 'nope' } as any);
  });
  seedQuote('DRAFT');
  useQuoteStore.setState({ send } as any);
  asStaff();
  renderPage();

  const field = screen.getByLabelText(/attach proof/i);
  await userEvent.type(field, 'proofs/v1.pdf');
  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  expect(send).toHaveBeenCalledWith(42, { artwork_version_ref: 'proofs/v1.pdf' });
  expect(field).toHaveValue('proofs/v1.pdf');
});

it('rejects a DRAFT artwork reference containing spaces without calling send', async () => {
  const send = vi.fn(async () => {});
  seedQuote('DRAFT');
  useQuoteStore.setState({ send } as any);
  asStaff();
  renderPage();

  await userEvent.type(screen.getByLabelText(/attach proof/i), 'proofs/my file.pdf');
  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  expect(send).not.toHaveBeenCalled();
  expect(screen.getByText(/cannot contain spaces/i)).toBeInTheDocument();
});

it('hides the "proof being prepared" note for a buyer once a proof is open in PROOFING', () => {
  seedQuote('PROOFING');
  seedOpenProof();
  asBuyer();
  renderPage();

  expect(screen.queryByText(/proof is being prepared/i)).not.toBeInTheDocument();
  expect(screen.getByRole('button', { name: /approve proof/i })).toBeInTheDocument();
});

it('shows the "proof being prepared" note for a buyer in PROOFING with no open proof yet', () => {
  seedQuote('PROOFING');
  asBuyer();
  renderPage();

  expect(screen.getByText(/proof is being prepared/i)).toBeInTheDocument();
});

it('surfaces the buyer-uploaded finished-look callout on a line so staff proof before printing', () => {
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        {
          id: 5,
          quote_id: 42,
          job_id: null,
          product_id: 3,
          variant_id: null,
          qty: 10,
          unit_price: '10.00',
          currency: 'SGD',
          line_total: '100.00',
          line_state: 'PENDING',
          procured_qty: null,
          procured_price: null,
          lead_time_days: null,
          customization: {
            mode: 'buyer_uploaded',
            reference_refs: ['artwork/a.png', 'artwork/b.png'],
            placement_notes: 'Centre the crest on the left chest.',
          },
        },
      ],
    },
  } as any);
  asStaff();
  renderPage();

  // Both desktop and mobile views render the callout, so scope with getAllByText.
  expect(screen.getAllByText(/our team proofs this before printing/i).length).toBeGreaterThan(0);
  expect(screen.getAllByText(/Centre the crest on the left chest/i).length).toBeGreaterThan(0);
  expect(screen.getAllByText(/2 reference image\(s\) attached/i).length).toBeGreaterThan(0);
});

// Regression: a designer line's saved artwork never reached this page, so a
// buyer who customised in the designer saw their work in the cart and then
// nothing on the order. Mirrors a real line from order 9BWVKWCDXH - note it
// carries NO `mode` key at all, which is why a mode-keyed check missed it.
it('shows the product image and the saved design on a customised line', async () => {
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        {
          id: 5,
          quote_id: 42,
          job_id: null,
          product_id: 6,
          variant_id: null,
          qty: 10,
          unit_price: '10.00',
          currency: 'SGD',
          line_total: '100.00',
          line_state: 'PENDING',
          procured_qty: null,
          procured_price: null,
          lead_time_days: null,
          customization: {
            logo_size: 'S',
            artwork_ref: 'https://cdn.test/artwork/design.png',
          },
          product: {
            id: 6,
            name: 'FL Cap Baseball',
            image_url: 'https://cdn.test/product/cap.jpg',
          },
        },
      ],
    },
  } as any);
  asBuyer();
  renderPage();

  await waitFor(() =>
    expect(
      screen.getAllByRole('button', { name: /preview your design for FL Cap Baseball/i }).length,
    ).toBeGreaterThan(0),
  );
  expect(
    document.querySelectorAll('img[src="https://cdn.test/product/cap.jpg"]').length,
  ).toBeGreaterThan(0);
});

it('shows a "what happens next" note for a buyer in CHANGES_REQUESTED', () => {
  seedQuote('CHANGES_REQUESTED');
  asBuyer();
  renderPage();

  expect(screen.getByText('What happens next')).toBeInTheDocument();
  expect(screen.getByText(/received your change request/i)).toBeInTheDocument();
});

it('shows a "what happens next" note for a buyer in PROCURING', () => {
  seedQuote('PROCURING');
  asBuyer();
  renderPage();

  expect(screen.getByText('What happens next')).toBeInTheDocument();
  expect(screen.getByText(/being prepared for production/i)).toBeInTheDocument();
});

it('does NOT show the passive note in an actionable buyer state (SENT)', () => {
  seedQuote('SENT');
  asBuyer();
  renderPage();

  // The actionable "Next step" card renders instead of the passive note.
  expect(screen.getByText('Next step')).toBeInTheDocument();
  expect(screen.queryByText('What happens next')).not.toBeInTheDocument();
});

it('does NOT show the buyer note for staff (staff sees their own controls)', () => {
  seedQuote('PROCURING');
  asStaff();
  renderPage();

  expect(screen.queryByText('What happens next')).not.toBeInTheDocument();
  expect(screen.getByText('Staff actions')).toBeInTheDocument();
});

it('shows the Cancel quote control to staff on a cancellable quote', () => {
  seedQuote('SENT');
  asStaff();
  renderPage();

  expect(screen.getByRole('button', { name: /cancel quote/i })).toBeInTheDocument();
});

it('never shows the Cancel quote control to a buyer', () => {
  seedQuote('SENT');
  asBuyer();
  renderPage();

  expect(screen.queryByRole('button', { name: /cancel quote/i })).not.toBeInTheDocument();
});

it.each(['READY', 'CLOSED', 'CANCELLED'] as const)(
  'hides the Cancel quote control once the quote is %s',
  (state) => {
    seedQuote(state);
    asStaff();
    renderPage();

    expect(screen.queryByRole('button', { name: /cancel quote/i })).not.toBeInTheDocument();
  },
);

it('confirming the cancel modal calls cancelQuote with the trimmed reason and closes on success', async () => {
  const cancelQuote = vi.fn(async () => true);
  seedQuote('SENT');
  useQuoteStore.setState({ cancelQuote } as any);
  asStaff();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /cancel quote/i }));
  await userEvent.type(screen.getByLabelText(/reason/i), '  Buyer changed their mind.  ');
  await userEvent.click(screen.getByRole('button', { name: /confirm cancellation/i }));

  expect(cancelQuote).toHaveBeenCalledWith(42, 'Buyer changed their mind.');
  // Modal closes on success - its confirm button is no longer in the document.
  await waitFor(() =>
    expect(screen.queryByRole('button', { name: /confirm cancellation/i })).not.toBeInTheDocument(),
  );
});

it('identifies the order by reference, never by the sequential id', async () => {
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, id: 42, reference: '9BWVKWCDXH' },
  } as any);
  asBuyer();
  renderPage();

  expect(screen.getAllByText(/9BWVKWCDXH/).length).toBeGreaterThan(0);
  // A stray "#42" anywhere means a surface was missed.
  expect(screen.queryByText(/#\d+/)).not.toBeInTheDocument();
});

/** Index of a section heading in document order, for asserting relative position. */
function headingIndex(name: string): number {
  const headings = screen.getAllByRole('heading');
  const i = headings.findIndex((h) => h.textContent?.trim() === name);
  if (i < 0) throw new Error(`heading "${name}" is not rendered`);
  return i;
}

// The Proofs card is deliberately positioned per role. For staff it is
// reference material, so it follows the controls they act with; for a buyer it
// carries their proof sign-off, so it stays high on the page. These two tests
// pin that difference - a "simplification" back to one slot breaks one of them.
it('renders Proofs BELOW Staff actions for staff', () => {
  seedQuote('ACCEPTED');
  seedOpenProof();
  asStaff();
  renderPage();

  expect(headingIndex('Proofs')).toBeGreaterThan(headingIndex('Staff actions'));

  // Same assertion via the DOM directly, independent of heading enumeration.
  const staffCard = screen.getByRole('heading', { name: 'Staff actions' });
  const proofs = screen.getByRole('heading', { name: 'Proofs' });
  expect(
    staffCard.compareDocumentPosition(proofs) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
});

it('keeps Proofs ABOVE the buyer’s Next step card for a buyer', () => {
  seedQuote('SENT');
  seedOpenProof();
  asBuyer();
  renderPage();

  // The buyer's sign-off controls live in the Proofs card, so it must not be
  // demoted below the rest of the page the way it is for staff.
  expect(headingIndex('Proofs')).toBeLessThan(headingIndex('Next step'));
  expect(screen.queryByRole('heading', { name: 'Staff actions' })).not.toBeInTheDocument();

  const proofs = screen.getByRole('heading', { name: 'Proofs' });
  const nextStep = screen.getByRole('heading', { name: 'Next step' });
  expect(
    proofs.compareDocumentPosition(nextStep) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
});

/** The status-history card only, so the timeline's own labels can't be mistaken for it. */
function historyCard(): HTMLElement {
  const el = document.querySelector<HTMLElement>('[aria-labelledby="history-heading"]');
  if (!el) throw new Error('status history card is not rendered');
  return el;
}

// Regression: the history is fetched once per `reference`, which never changes
// for the life of the page - so a buyer who clicked Accept watched the badge and
// timeline advance to Accepted while the "authoritative record" directly beneath
// them still ended at Sent. Two components disagreeing on screen, with the stale
// one claiming to be the record.
it('refreshes the status history when the buyer accepts and the order moves', async () => {
  fetchQuoteHistory.mockResolvedValueOnce([
    { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00+00:00', actor_name: 'Bo Staff' },
  ]);

  seedQuote('SENT');
  // Stand in for the store's accept(): it POSTs, then fetchQuote() writes the
  // new state onto `current`. That write is the thing this page re-renders on.
  useQuoteStore.setState({
    accept: async () => {
      useQuoteStore.setState((s) => ({ current: { ...s.current!, state: 'ACCEPTED' } }) as any);
    },
  } as any);
  asBuyer();
  renderPage();

  // Positive control: the pre-accept history is genuinely on the page.
  expect(await within(historyCard()).findByText('Sent')).toBeInTheDocument();
  expect(within(historyCard()).queryByText('Accepted')).not.toBeInTheDocument();
  expect(fetchQuoteHistory).toHaveBeenCalledTimes(1);

  fetchQuoteHistory.mockResolvedValueOnce([
    { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00+00:00', actor_name: 'Bo Staff' },
    { from: 'SENT', to: 'ACCEPTED', changed_at: '2026-07-21T10:00:00+00:00', actor_name: 'Ada' },
  ]);

  await userEvent.click(screen.getByRole('button', { name: /accept quote/i }));

  // The record now agrees with the badge: newest entry is Accepted.
  expect(await within(historyCard()).findByText('Accepted')).toBeInTheDocument();
  expect(within(historyCard()).getByText('Ada')).toBeInTheDocument();
  expect(fetchQuoteHistory).toHaveBeenCalledTimes(2);
});

// The other route a state change arrives by: the `.quote.state-changed`
// broadcast, which mutates current.state in place without any refetch. Mirrors
// quoteStore's listener exactly - no action handler is involved.
it('refreshes the status history when a broadcast moves the order underneath it', async () => {
  fetchQuoteHistory.mockResolvedValueOnce([
    { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00+00:00', actor_name: 'Bo Staff' },
  ]);

  seedQuote('SENT');
  asBuyer();
  renderPage();

  expect(await within(historyCard()).findByText('Sent')).toBeInTheDocument();
  expect(fetchQuoteHistory).toHaveBeenCalledTimes(1);

  // Hold the refetch open so the in-between render is observable.
  let resolve!: (rows: unknown[]) => void;
  fetchQuoteHistory.mockReturnValueOnce(new Promise((r) => { resolve = r; }));

  await act(async () => {
    useQuoteStore.setState((s) => ({
      current: s.current ? { ...s.current, state: 'CANCELLED', total: '105.00' } : s.current,
    }) as any);
  });

  expect(fetchQuoteHistory).toHaveBeenCalledTimes(2);
  // Mid-refetch the card must go busy rather than keep showing the pre-change
  // trail. Holding the old entries here would be the same staleness in
  // miniature: a record that disagrees with the badge already above it.
  expect(historyCard()).toHaveAttribute('aria-busy', 'true');
  expect(within(historyCard()).queryByText('Sent')).not.toBeInTheDocument();

  await act(async () => {
    resolve([
      { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00+00:00', actor_name: 'Bo Staff' },
      { from: 'SENT', to: 'CANCELLED', changed_at: '2026-07-21T10:00:00+00:00', actor_name: null },
    ]);
  });

  expect(await within(historyCard()).findByText('Cancelled')).toBeInTheDocument();
  expect(within(historyCard()).getByText('Sent')).toBeInTheDocument();
});

// A rejected write used to route through the store's `error`, which this page
// renders as a full-page ErrorState. The staffer lost the order, the controls
// and their typed input, and had to navigate back to find out what was wrong.
// Write failures now land in `actionError` and render inline.
it('keeps the order on screen when a write is rejected, and explains why', async () => {
  asStaff();
  seedQuote('PROOF_APPROVED');
  useQuoteStore.setState({
    issueInvoice: async () => {
      useQuoteStore.setState({ actionError: 'PO reference has already been used.' } as any);
    },
  } as any);
  renderPage();

  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/PO reference/i), 'PO-1');
  await user.click(screen.getByRole('button', { name: /Issue invoice/i }));

  const alert = await screen.findByRole('alert');
  expect(alert).toHaveTextContent('PO reference has already been used.');

  // The order itself is still there - the whole point of the change.
  expect(screen.getByRole('heading', { name: /Order 9BWVKWCDXH/i })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /Issue invoice/i })).toBeInTheDocument();
});

it('dismisses the inline write error without touching the order', async () => {
  asStaff();
  seedQuote('PROOF_APPROVED');
  renderPage();

  // Set after mount: the page clears actionError on navigation, so a value
  // seeded before render is correctly wiped by that effect.
  await act(async () => {
    useQuoteStore.setState({ actionError: 'Something went wrong.' } as any);
  });

  expect(await screen.findByRole('alert')).toBeInTheDocument();
  await userEvent.setup().click(screen.getByRole('button', { name: 'Dismiss' }));

  expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  expect(screen.getByRole('heading', { name: /Order 9BWVKWCDXH/i })).toBeInTheDocument();
});

// A load failure is different in kind: there is no order to show, so the
// full-page error is correct and must survive the split.
it('still shows a full-page error when the order itself fails to load', () => {
  asStaff();
  useQuoteStore.setState({ current: null, loading: false, error: 'Network unreachable.' } as any);
  renderPage();

  expect(screen.getByText('Network unreachable.')).toBeInTheDocument();
  expect(screen.queryByRole('heading', { name: /Order 9BWVKWCDXH/i })).not.toBeInTheDocument();
});
