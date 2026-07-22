import { afterEach, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';

// StatusHistory renders inside this page and fetches on mount. Stub the network
// so every test here is offline, and so the refetch test below can count calls.
const fetchQuoteHistory = vi.fn(async (_reference: string) => [] as unknown[]);
vi.mock('../lib/quotes', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/quotes')>()),
  fetchQuoteHistory: (reference: string) => fetchQuoteHistory(reference),
}));

// The proof uploader has its own test; here it is stubbed so these tests stay
// about the page's own logic. Attaching yields the ref the server would return.
vi.mock('../components/quote/ProofFileInput', () => ({
  default: ({ label, value, onChange }: {
    label: string;
    value: string;
    onChange: (ref: string, name: string | null) => void;
  }) => (
    <div>
      <button type="button" onClick={() => onChange('proofs/v1.pdf', 'v1.pdf')}>
        {`attach:${label}`}
      </button>
      <span data-testid={`ref:${label}`}>{value}</span>
    </div>
  ),
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

function asSuperadmin() {
  useAuthStore.setState({
    user: { id: 1, company_id: null, name: 'Root', email: 'root@x.test', role: 'superadmin' },
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
  // Pay now only renders where buyer payment is actually available; it used to
  // render for everyone and always failed on a B2B tenant.
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, pay_now_enabled: true },
    payNow: async () => true,
  } as any);
  asBuyer();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /pay now/i }));

  expect(await screen.findByText('Payment received')).toBeInTheDocument();
});


it('rejects a whitespace-only PO reference without calling the API', async () => {
  const issueInvoice = vi.fn(async () => {});
  seedQuote('PROOF_APPROVED');
  useQuoteStore.setState({ issueInvoice } as any);
  asStaff();
  renderPage();

  await userEvent.type(screen.getByLabelText(/po reference/i), '   ');
  await userEvent.click(screen.getByRole('button', { name: 'Commit order' }));

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

  await userEvent.click(screen.getByRole('button', { name: 'attach:Attach proof (optional)' }));
  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  expect(send).toHaveBeenCalledWith(42, { artwork_version_ref: 'proofs/v1.pdf' });
});

it('clears the DRAFT proof field after a successful send-with-proof', async () => {
  const send = vi.fn(async () => {});
  seedQuote('DRAFT');
  useQuoteStore.setState({ send } as any);
  asStaff();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: 'attach:Attach proof (optional)' }));
  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  await waitFor(() =>
    expect(screen.getByTestId('ref:Attach proof (optional)')).toHaveTextContent(''),
  );
});

it('keeps the attached proof when the send-with-proof fails', async () => {
  // send() swallows failures into actionError and never rejects. The attached
  // ref must survive so the staffer can retry without uploading the file again.
  const send = vi.fn(async () => {
    useQuoteStore.setState({ actionError: 'nope' } as any);
  });
  seedQuote('DRAFT');
  useQuoteStore.setState({ send } as any);
  asStaff();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: 'attach:Attach proof (optional)' }));
  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  expect(send).toHaveBeenCalledWith(42, { artwork_version_ref: 'proofs/v1.pdf' });
  expect(screen.getByTestId('ref:Attach proof (optional)')).toHaveTextContent('proofs/v1.pdf');
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

/**
 * The status-history ledger region inside the merged Order status card. Only
 * present once the disclosure is open, and scoped so the card's own current-state
 * badge (which repeats a state label) can't be mistaken for a ledger entry.
 */
function statusRegion(): HTMLElement {
  const el = document.querySelector<HTMLElement>('[role="region"][aria-label="Status history"]');
  if (!el) throw new Error('status history region is not open');
  return el;
}

/** Open the status-history disclosure on the Order status card. */
async function openStatusHistory() {
  await userEvent.click(screen.getByRole('button', { name: /show history/i }));
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
  await openStatusHistory();

  // Positive control: the pre-accept history is genuinely on the page.
  expect(await within(statusRegion()).findByText('Sent')).toBeInTheDocument();
  expect(within(statusRegion()).queryByText('Accepted')).not.toBeInTheDocument();
  expect(fetchQuoteHistory).toHaveBeenCalledTimes(1);

  fetchQuoteHistory.mockResolvedValueOnce([
    { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00+00:00', actor_name: 'Bo Staff' },
    { from: 'SENT', to: 'ACCEPTED', changed_at: '2026-07-21T10:00:00+00:00', actor_name: 'Ada' },
  ]);

  await userEvent.click(screen.getByRole('button', { name: /accept quote/i }));

  // The record now agrees with the badge: newest entry is Accepted. (Disclosure
  // stays open across the re-render.)
  expect(await within(statusRegion()).findByText('Accepted')).toBeInTheDocument();
  expect(within(statusRegion()).getByText('Ada')).toBeInTheDocument();
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
  await openStatusHistory();

  expect(await within(statusRegion()).findByText('Sent')).toBeInTheDocument();
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
  // Mid-refetch the ledger must go busy rather than keep showing the pre-change
  // trail. Holding the old entries here would be the same staleness in
  // miniature: a record that disagrees with the badge already above it.
  expect(statusRegion()).toHaveAttribute('aria-busy', 'true');
  expect(within(statusRegion()).queryByText('Sent')).not.toBeInTheDocument();

  await act(async () => {
    resolve([
      { from: 'DRAFT', to: 'SENT', changed_at: '2026-07-20T10:00:00+00:00', actor_name: 'Bo Staff' },
      { from: 'SENT', to: 'CANCELLED', changed_at: '2026-07-21T10:00:00+00:00', actor_name: null },
    ]);
  });

  expect(await within(statusRegion()).findByText('Cancelled')).toBeInTheDocument();
  expect(within(statusRegion()).getByText('Sent')).toBeInTheDocument();
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
  await user.click(screen.getByRole('button', { name: 'Commit order' }));
  // Confirmation first: committing opens production and cannot be walked back.
  await user.click(screen.getAllByRole('button', { name: 'Commit order' })[1]);

  const alert = await screen.findByRole('alert');
  expect(alert).toHaveTextContent('PO reference has already been used.');

  // The order itself is still there - the whole point of the change.
  expect(screen.getByRole('heading', { name: /Order 9BWVKWCDXH/i })).toBeInTheDocument();
  expect(screen.getAllByRole('button', { name: 'Commit order' })[0]).toBeInTheDocument();
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

// Two approvals, neither standing in for the other. On the artwork-first route
// the buyer signs off artwork first and must still be shown the price; the old
// behaviour back-filled acceptance silently, committing them to a figure they
// had never seen.
it('asks the buyer to agree the price after they approve artwork', async () => {
  asBuyer();
  seedQuote('ARTWORK_APPROVED');
  const accept = vi.fn();
  useQuoteStore.setState({ accept } as any);
  renderPage();

  expect(screen.getByText(/Your artwork is approved/i)).toBeInTheDocument();
  await userEvent.setup().click(screen.getByRole('button', { name: 'Accept quote' }));

  expect(accept).toHaveBeenCalledWith(42);
});

// Staff must not read "artwork approved" as "ready to invoice" - the order is
// waiting on the buyer, and PROOF_APPROVED is the state that means both are in.
it('tells staff an artwork-approved order is waiting on the buyer', () => {
  asStaff();
  seedQuote('ARTWORK_APPROVED');
  renderPage();

  expect(screen.getByText(/Waiting for the buyer to accept the price/i)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /Issue invoice/i })).not.toBeInTheDocument();
});

// CHANGES_REQUESTED was unrecoverable: no control performed a way out, so the
// order had to be cancelled and rebuilt. Issuing a revised proof is that way.
it('offers staff the issue-proof control on a changes-requested order', () => {
  asStaff();
  seedQuote('CHANGES_REQUESTED');
  renderPage();

  expect(screen.getByRole('button', { name: 'attach:Proof artwork' })).toBeInTheDocument();
  expect(screen.queryByText(/No staff action available/i)).not.toBeInTheDocument();
});

// Wave 3: the production gate. Jobs used to be built the moment the system
// believed every line was resolved — a belief resting on stock figures nobody
// maintains, since most goods are bought in after the order is placed.
function seedProcuringQuote(lineState: string, procurementNote: string | null = null) {
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      state: 'PROCURING',
      line_items: [
        {
          id: 1,
          product_id: 10,
          qty: 5,
          line_state: lineState,
          procurement_note: procurementNote,
          product: { name: 'Enamel Mug' },
        },
      ],
    },
  } as any);
}

it('offers the production gate once every line is resolved', async () => {
  asStaff();
  seedQuote('PROCURING');
  seedProcuringQuote('READY');
  const confirmStock = vi.fn(async () => {});
  useQuoteStore.setState({ confirmStock } as any);
  renderPage();

  // The lines are listed to be checked off against what actually arrived.
  expect(screen.getByText('5 × Enamel Mug')).toBeInTheDocument();
  expect(screen.getByText(/Your name and the time are recorded/i)).toBeInTheDocument();

  await userEvent
    .setup()
    .click(screen.getByRole('button', { name: /Confirm stock and start production/i }));

  expect(confirmStock).toHaveBeenCalledWith(42);
});

// The gate asserts everything is in hand, which is not yet true while a line is
// still awaiting a decision.
it('withholds the production gate while a line still needs a decision', () => {
  asStaff();
  seedQuote('PROCURING');
  seedProcuringQuote('AWAITING_RECONFIRM');
  renderPage();

  expect(
    screen.queryByRole('button', { name: /Confirm stock and start production/i }),
  ).not.toBeInTheDocument();
  expect(screen.getByText(/need a stock or price decision/i)).toBeInTheDocument();
});

// A quantity shortfall no longer stops the order, so the gate is the moment it
// gets seen — someone is looking at the goods right then.
it('shows the advisory shortfall against the line at the gate', () => {
  asStaff();
  seedQuote('PROCURING');
  seedProcuringQuote('READY', 'Only 2 of 5 on hand.');
  renderPage();

  expect(screen.getByText(/Only 2 of 5 on hand/i)).toBeInTheDocument();
  // Advisory, not blocking: the gate is still offered.
  expect(
    screen.getByRole('button', { name: /Confirm stock and start production/i }),
  ).toBeInTheDocument();
});

// Issuing the invoice also drives the order to CONFIRMED, the production gate.
// The button said "Issue invoice" and gave no hint of that, so staff committed
// orders without being told they had.
it('confirms before committing an order to production', async () => {
  asStaff();
  seedQuote('PROOF_APPROVED');
  const issueInvoice = vi.fn(async () => {});
  useQuoteStore.setState({ issueInvoice } as any);
  renderPage();

  const user = userEvent.setup();
  await user.type(screen.getByLabelText(/PO reference/i), 'PO-9');
  await user.click(screen.getByRole('button', { name: 'Commit order' }));

  // Nothing has happened yet - the confirmation explains what is about to.
  expect(issueInvoice).not.toHaveBeenCalled();
  expect(screen.getByText(/Production can begin/i)).toBeInTheDocument();

  await user.click(screen.getAllByRole('button', { name: 'Commit order' })[1]);
  expect(issueInvoice).toHaveBeenCalledWith(42, 'PO-9', null);
});

it('shows the staff-only Edit history when the order carries an amendment log', () => {
  asStaff();
  seedQuote('DRAFT');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      amendment_log: [
        {
          batch: 'b1', action: 'edited', by: 1, by_name: 'Ops', at: '2026-07-21T06:02:00Z',
          product_name: 'Enamel Mug', from: { unit_price: 10, qty: 4 }, to: { unit_price: 12.5, qty: 6 },
        },
      ],
    },
  } as any);
  renderPage();

  // Heading always shows; the trail is collapsed until opened.
  expect(screen.getByRole('heading', { name: /edit history/i })).toBeInTheDocument();
  expect(screen.queryByText(/Enamel Mug/)).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: /show 1 edit/i }));
  expect(screen.getByText(/Enamel Mug: 4 × SGD 10.00 → 6 × SGD 12.50/)).toBeInTheDocument();
});

it('never renders the Edit history for a buyer', () => {
  asBuyer();
  seedQuote('DRAFT');
  // A buyer payload would not carry this; belt-and-braces, the page guards too.
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      amendment_log: [
        { batch: 'b1', action: 'edited', by: 1, by_name: 'Ops', at: '2026-07-21T06:02:00Z',
          product_name: 'Enamel Mug', from: { unit_price: 10, qty: 4 }, to: { unit_price: 12.5, qty: 6 } },
      ],
    },
  } as any);
  renderPage();

  expect(screen.queryByRole('heading', { name: /edit history/i })).not.toBeInTheDocument();
});

it('lets a superadmin edit items on a non-draft order', () => {
  asSuperadmin();
  seedQuote('CONFIRMED');
  renderPage();

  // The superadmin override: line editing is offered past DRAFT.
  expect(screen.getByRole('button', { name: /edit items/i })).toBeInTheDocument();
});

it('does not offer a plain staff_admin the editor past draft', () => {
  asStaff();
  seedQuote('CONFIRMED');
  renderPage();

  expect(screen.queryByRole('button', { name: /edit items/i })).not.toBeInTheDocument();
});

it('still offers a staff_admin the editor on a draft', () => {
  asStaff();
  seedQuote('DRAFT');
  renderPage();

  expect(screen.getByRole('button', { name: /edit items/i })).toBeInTheDocument();
});

it('gives a superadmin resend + approve-on-behalf actions while a proof is open', async () => {
  asSuperadmin();
  seedQuote('PROOFING');
  const resendProof = vi.fn().mockResolvedValue(true);
  const decideProof = vi.fn().mockResolvedValue(undefined);
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      proofs: [{ id: 9, version: 1, state: 'SENT', artwork_version_ref: null }],
    },
    resendProof,
    decideProof,
  } as any);
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /resend proof email/i }));
  expect(resendProof).toHaveBeenCalledWith(9);

  await userEvent.click(screen.getByRole('button', { name: /approve on behalf/i }));
  // On behalf, but recorded server-side against the superadmin (approved_by).
  expect(decideProof).toHaveBeenCalledWith(9, 'approve', null);
});

it('does not show on-behalf proof actions to a plain staff_admin', () => {
  asStaff();
  seedQuote('PROOFING');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      proofs: [{ id: 9, version: 1, state: 'SENT', artwork_version_ref: null }],
    },
  } as any);
  renderPage();

  expect(screen.queryByRole('button', { name: /approve on behalf/i })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /resend proof email/i })).not.toBeInTheDocument();
});

it('hides Pay now where buyer payment is not available', () => {
  asBuyer();
  seedQuote('PROOF_APPROVED');
  renderPage();

  expect(screen.queryByRole('button', { name: /pay now/i })).not.toBeInTheDocument();
  expect(screen.getByText(/We’ll send your invoice/i)).toBeInTheDocument();
});
