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
  default: ({ label, value, onChange, trailing }: {
    label: string;
    value: string;
    onChange: (ref: string, name: string | null) => void;
    trailing?: React.ReactNode;
  }) => (
    <div>
      <button type="button" onClick={() => onChange('proofs/v1.pdf', 'v1.pdf')}>
        {`attach:${label}`}
      </button>
      <span data-testid={`ref:${label}`}>{value}</span>
      {trailing}
    </div>
  ),
}));

// The design-picker thumbnails exchange storage refs for signed preview URLs;
// stub the network so tests stay offline (placeholder thumbs are fine here).
vi.mock('../lib/uploadArtwork', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/uploadArtwork')>()),
  fetchArtworkPreview: async () => ({ ok: false as const }),
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
      // Staff-only notification picture (the API always includes it for staff).
      // Defaults to "nothing pending"; individual tests override as needed.
      reminder: {
        current_milestone: null,
        current_milestone_enabled: false,
        last_reminded_at: null,
        next: null,
      },
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

// A buyer proof is per-line now, so an open proof needs a matching customised
// line for the per-line review to compute a row for it.
function seedOpenProof() {
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        {
          id: 5,
          product_id: 5,
          qty: 10,
          line_state: 'PENDING',
          product: { name: 'Enamel Mug' },
          customization: { mode: 'designer', artwork_ref: 'artwork/mug.png' },
        },
      ],
      proofs: [
        {
          id: 9,
          quote_id: 42,
          line_item_id: 5,
          product_name: 'Enamel Mug',
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

  expect(decideProof).toHaveBeenCalledWith(9, 'request_changes', 'Use the darker blue.', undefined);
});

it('requires a reason before a buyer can send a change request', async () => {
  const decideProof = vi.fn(async () => {});
  seedQuote('PROOFING');
  seedOpenProof();
  useQuoteStore.setState({ decideProof } as any);
  asBuyer();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /request changes/i }));

  // Send stays disabled with no reason - the API rejects an empty note and
  // staff need to know what to revise.
  expect(screen.getByRole('button', { name: /send request/i })).toBeDisabled();

  await userEvent.type(screen.getByLabelText(/what should we change/i), 'Fix the logo.');
  expect(screen.getByRole('button', { name: /send request/i })).toBeEnabled();

  await userEvent.click(screen.getByRole('button', { name: /send request/i }));
  expect(decideProof).toHaveBeenCalledWith(9, 'request_changes', 'Fix the logo.', undefined);
});

it('sends an attached reference image with the change request', async () => {
  const decideProof = vi.fn(async () => {});
  seedQuote('PROOFING');
  seedOpenProof();
  useQuoteStore.setState({ decideProof } as any);
  asBuyer();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /request changes/i }));
  await userEvent.type(screen.getByLabelText(/what should we change/i), 'Match this.');
  // The stubbed uploader yields a ref on "attach".
  await userEvent.click(screen.getByRole('button', { name: 'attach:Attach a reference (optional)' }));
  await userEvent.click(screen.getByRole('button', { name: /send request/i }));

  expect(decideProof).toHaveBeenCalledWith(9, 'request_changes', 'Match this.', ['proofs/v1.pdf']);
});

// Two artwork lines, each with its own current proof, for the per-line review.
function seedTwoLineProofs(secondState: 'SENT' | 'CHANGES_REQUESTED') {
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        { id: 1, product_id: 5, qty: 10, line_state: 'PENDING', product: { name: 'Enamel Mug' }, customization: { mode: 'designer', artwork_ref: 'artwork/mug.png' } },
        { id: 2, product_id: 6, qty: 5, line_state: 'PENDING', product: { name: 'Tote Bag' }, customization: { mode: 'designer', artwork_ref: 'artwork/tote.png' } },
      ],
      proofs: [
        { id: 9, quote_id: 42, line_item_id: 1, product_name: 'Enamel Mug', version: 1, artwork_version_ref: 'proofs/v1.pdf', state: 'SENT', approved_by: null, approved_at: null, notes: null },
        { id: 10, quote_id: 42, line_item_id: 2, product_name: 'Tote Bag', version: 2, artwork_version_ref: 'proofs/v2.pdf', state: secondState, approved_by: null, approved_at: null, notes: secondState === 'CHANGES_REQUESTED' ? 'Move the logo up.' : null },
      ],
    },
  } as any);
}

it('reviews each artwork line independently — a SENT line is actionable, a CHANGES_REQUESTED line is passive', () => {
  seedQuote('PROOFING');
  seedTwoLineProofs('CHANGES_REQUESTED');
  asBuyer();
  renderPage();

  // Scope to the review card - product names also appear in the items table.
  const reviewCard = screen
    .getByRole('heading', { name: 'Review your proof' })
    .closest('[aria-labelledby="proof-review-heading"]') as HTMLElement;

  // The SENT line is actionable: named, with its own approve control.
  expect(within(reviewCard).getByText('Enamel Mug')).toBeInTheDocument();
  // The CHANGES_REQUESTED line is passive: named, with a "being revised" note
  // echoing the buyer's own note, and no approve/request-changes controls.
  expect(within(reviewCard).getByText('Tote Bag')).toBeInTheDocument();
  expect(within(reviewCard).getByText(/we’ll send you an updated proof/i)).toBeInTheDocument();
  expect(within(reviewCard).getByText(/Move the logo up\./)).toBeInTheDocument();
  // Only the one SENT line offers an approve control (plus the approve-all
  // shortcut, which matches a different name).
  expect(within(reviewCard).getAllByRole('button', { name: /approve proof/i })).toHaveLength(1);
});

it('shows a per-line progress banner across the artwork lines', () => {
  seedQuote('PROOFING');
  seedTwoLineProofs('CHANGES_REQUESTED');
  asBuyer();
  const { container } = renderPage();

  // 0 approved of 2, one still awaiting the buyer, one being revised.
  expect(container.textContent).toContain('0 of 2 approved');
  expect(container.textContent).toContain('1 awaiting you');
  expect(container.textContent).toContain('1 being revised');
});

it('offers "Approve all remaining" labelled with the SENT count, calling approveAllProofs', async () => {
  const approveAllProofs = vi.fn(async () => true);
  seedQuote('PROOFING');
  // Both lines SENT: two remaining to approve in one shot.
  seedTwoLineProofs('SENT');
  useQuoteStore.setState({ approveAllProofs } as any);
  asBuyer();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /approve all 2 remaining/i }));
  expect(approveAllProofs).toHaveBeenCalledWith(42);
});

it('hides "Approve all remaining" when no line is awaiting the buyer', () => {
  seedQuote('CHANGES_REQUESTED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        { id: 1, product_id: 5, qty: 10, line_state: 'PENDING', product: { name: 'Enamel Mug' }, customization: { mode: 'designer', artwork_ref: 'artwork/mug.png' } },
      ],
      // The only line is being revised, so there is nothing to approve.
      proofs: [
        { id: 9, quote_id: 42, line_item_id: 1, product_name: 'Enamel Mug', version: 1, artwork_version_ref: 'proofs/v1.pdf', state: 'CHANGES_REQUESTED', approved_by: null, approved_at: null, notes: 'Fix the crest.' },
      ],
    },
  } as any);
  asBuyer();
  renderPage();

  expect(screen.getByText(/we’ll send you an updated proof/i)).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: /approve all/i })).not.toBeInTheDocument();
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

// A customised DRAFT/proofing line that needs a proof, with its own design.
function customisedLine(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    product_id: 5,
    qty: 10,
    line_state: 'PENDING',
    product: { name: 'Enamel Mug' },
    customization: { mode: 'designer', artwork_ref: 'artwork/mug.png' },
    ...overrides,
  };
}

// A per-line proof for a given line + state.
function lineProof(overrides: Record<string, unknown> = {}) {
  return {
    id: 90,
    quote_id: 42,
    line_item_id: 1,
    product_name: 'Enamel Mug',
    version: 1,
    artwork_version_ref: 'proofs/staged.png',
    state: 'DRAFT',
    approved_by: null,
    approved_at: null,
    notes: null,
    ...overrides,
  };
}

it('stages a per-line proof from an uploaded ref on a customised line', async () => {
  const stageProof = vi.fn(async () => {});
  seedQuote('DRAFT');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, line_items: [customisedLine()] },
    stageProof,
  } as any);
  asStaff();
  renderPage();

  // The uploader on the line's row yields the ref the server returns; staging
  // is immediate and per-line.
  await userEvent.click(screen.getByRole('button', { name: 'attach:Proof for Enamel Mug' }));

  expect(stageProof).toHaveBeenCalledWith(42, 1, 'proofs/v1.pdf');
});

it('DRAFT "Send to buyer" is a plain param-less send, not a proof send', async () => {
  const send = vi.fn(async () => {});
  const sendProofs = vi.fn(async () => {});
  seedQuote('DRAFT');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, line_items: [customisedLine()] },
    send,
    sendProofs,
  } as any);
  asStaff();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /send to buyer/i }));

  expect(send).toHaveBeenCalledWith(42);
  expect(sendProofs).not.toHaveBeenCalled();
});

it('DRAFT staff panel: activating "Proof first" sets the approval order', async () => {
  const setApprovalOrder = vi.fn(async () => {});
  seedQuote('DRAFT');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, approval_order: 'price_first' },
    setApprovalOrder,
  } as any);
  asStaff();
  renderPage();

  // The segmented control renders both options; "Price first" is the active one.
  expect(screen.getByRole('radio', { name: /price first/i })).toBeInTheDocument();
  await userEvent.click(screen.getByRole('radio', { name: /proof first/i }));

  expect(setApprovalOrder).toHaveBeenCalledWith(42, 'proof_first');
});

it('approval-order control is disabled once the quote leaves DRAFT', () => {
  seedQuote('SENT');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, approval_order: 'price_first' },
  } as any);
  asStaff();
  renderPage();

  expect(screen.getByRole('radio', { name: /price first/i })).toBeDisabled();
  expect(screen.getByRole('radio', { name: /proof first/i })).toBeDisabled();
});

it('buyer proof_first ARTWORK_APPROVED: Next step accepts pricing, price stays visible', () => {
  seedQuote('ARTWORK_APPROVED');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, approval_order: 'proof_first' },
  } as any);
  asBuyer();
  renderPage();

  // Second step of the proof-first flow: artwork signed off, now accept pricing.
  expect(screen.getByRole('button', { name: /accept/i })).toBeInTheDocument();
  expect(screen.getByText(/review the pricing/i)).toBeInTheDocument();
  // A-1: the pricing block itself stays on the page (read-only), not hidden.
  expect(screen.getByText('Subtotal')).toBeInTheDocument();
});

it('stages a line proof from the buyer’s existing design via the picker', async () => {
  const stageProof = vi.fn(async () => {});
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, line_items: [customisedLine()] },
    stageProof,
  } as any);
  asStaff();
  renderPage();

  // Reuse the buyer's design instead of re-uploading: the button opens the
  // picker (even for a single option), and picking stages the proof immediately.
  await userEvent.click(screen.getByRole('button', { name: /use existing artwork/i }));
  const dialog = await screen.findByRole('dialog', { name: /use existing artwork/i });
  await userEvent.click(within(dialog).getByRole('button', { name: /buyer.s design/i }));

  expect(stageProof).toHaveBeenCalledWith(42, 1, 'artwork/mug.png');
});

it('lists line designs, change-request images and past proofs in a line’s picker', async () => {
  const stageProof = vi.fn(async () => {});
  seedQuote('CHANGES_REQUESTED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        { id: 1, product_id: 5, qty: 10, line_state: 'PENDING', product: { name: 'Enamel Mug' }, customization: { mode: 'designer', artwork_ref: 'artwork/mug.png' } },
        { id: 2, product_id: 6, qty: 5, line_state: 'PENDING', product: { name: 'Tote Bag' }, customization: { mode: 'designer', artwork_ref: 'artwork/tote.png' } },
      ],
      proofs: [
        { id: 9, quote_id: 42, line_item_id: 1, product_name: 'Enamel Mug', version: 1, artwork_version_ref: 'proofs/v1.pdf', state: 'CHANGES_REQUESTED', approved_by: null, approved_at: null, notes: 'Do it like this' },
        { id: 10, quote_id: 42, line_item_id: 1, product_name: 'Enamel Mug', version: 2, artwork_version_ref: 'proofs/v2.pdf', state: 'CHANGES_REQUESTED', approved_by: null, approved_at: null, notes: 'wrong image', change_attachments: [{ ref: 'artwork/wanted.png', url: null }] },
      ],
    },
    stageProof,
  } as any);
  asStaff();
  renderPage();

  // Two lines, so two reuse buttons - open the first line's (Enamel Mug) picker.
  await userEvent.click(screen.getAllByRole('button', { name: /use existing artwork/i })[0]);
  const dialog = await screen.findByRole('dialog', { name: /use existing artwork/i });

  // Every source is listed: both line designs, the buyer's change-request
  // attachment, and both previously issued proof versions.
  expect(within(dialog).getByRole('button', { name: /buyer.s design — enamel mug/i })).toBeInTheDocument();
  expect(within(dialog).getByRole('button', { name: /buyer.s design — tote bag/i })).toBeInTheDocument();
  expect(within(dialog).getByRole('button', { name: /change request image \(v2\)/i })).toBeInTheDocument();
  expect(within(dialog).getByRole('button', { name: /proof v1 artwork/i })).toBeInTheDocument();
  expect(within(dialog).getByRole('button', { name: /proof v2 artwork/i })).toBeInTheDocument();

  await userEvent.click(within(dialog).getByRole('button', { name: /change request image \(v2\)/i }));

  // Picking closes the picker and stages that ref onto the line it was opened for.
  await waitFor(() =>
    expect(screen.queryByRole('dialog', { name: /use existing artwork/i })).not.toBeInTheDocument(),
  );
  expect(stageProof).toHaveBeenCalledWith(42, 1, 'artwork/wanted.png');
});

it('shows the blocker breakdown across the lines that need a proof', () => {
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        { id: 1, product_id: 5, qty: 10, line_state: 'PENDING', product: { name: 'Enamel Mug' }, customization: { mode: 'designer', artwork_ref: 'artwork/mug.png' } },
        { id: 2, product_id: 6, qty: 5, line_state: 'PENDING', product: { name: 'Tote Bag' }, customization: { mode: 'designer', artwork_ref: 'artwork/tote.png' } },
      ],
      // Line 1 sent (awaiting buyer); line 2 has no proof (not prepared).
      proofs: [lineProof({ id: 90, line_item_id: 1, state: 'SENT' })],
    },
  } as any);
  asStaff();
  const { container } = renderPage();

  expect(container.textContent).toContain('Awaiting buyer 1');
  expect(container.textContent).toContain('In changes 0');
  expect(container.textContent).toContain('Not prepared 1');
  expect(container.textContent).toContain('Approved 0');
});

it('disables "Send proofs" with nothing staged and enables it once a line is staged', async () => {
  const sendProofs = vi.fn(async () => {});

  // Nothing staged: the Send button is present but disabled.
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, line_items: [customisedLine()] },
    sendProofs,
  } as any);
  asStaff();
  renderPage();

  expect(screen.getByRole('button', { name: /send proofs to buyer \(0 staged\)/i })).toBeDisabled();
  cleanup();

  // A staged (DRAFT) proof on the line: Send is enabled, the unsent warning
  // shows, and clicking it flips every DRAFT to SENT in one call.
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [customisedLine()],
      proofs: [lineProof({ state: 'DRAFT' })],
    },
    sendProofs,
  } as any);
  asStaff();
  renderPage();

  expect(screen.getByText(/has not been sent to the buyer yet/i)).toBeInTheDocument();
  const sendBtn = screen.getByRole('button', { name: /send proofs to buyer \(1 staged\)/i });
  expect(sendBtn).toBeEnabled();
  await userEvent.click(sendBtn);
  expect(sendProofs).toHaveBeenCalledWith(42);
});

it('offers no per-line proof row for a buyer_uploaded reference line’s reuse (still needs a proof)', () => {
  seedQuote('ACCEPTED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      // A finished-look reference photo still needs a proof, so the line gets a
      // row; but it carries no print-ready design of its own.
      line_items: [
        { id: 1, product_id: 5, qty: 1, line_state: 'PENDING', product: { name: 'Crest Polo' }, customization: { mode: 'buyer_uploaded', reference_refs: ['artwork/ref.png'] } },
      ],
    },
  } as any);
  asStaff();
  renderPage();

  // The line still needs a proof, so its uploader row renders.
  expect(screen.getByRole('button', { name: 'attach:Proof for Crest Polo' })).toBeInTheDocument();
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

it('shows the status note in the order-status card for a buyer in CHANGES_REQUESTED', () => {
  seedQuote('CHANGES_REQUESTED');
  asBuyer();
  renderPage();

  expect(screen.getByText(/received your change request/i)).toBeInTheDocument();
});

it('shows the status note in the order-status card for a buyer in PROCURING', () => {
  seedQuote('PROCURING');
  asBuyer();
  renderPage();

  expect(screen.getByText(/being prepared for production/i)).toBeInTheDocument();
});

it('does NOT show the passive note in an actionable buyer state (SENT)', () => {
  seedQuote('SENT');
  asBuyer();
  renderPage();

  // The actionable "Next step" card renders instead of the passive note.
  expect(screen.getByText('Next step')).toBeInTheDocument();
  expect(
    screen.queryByText(/received your change request|being prepared/i),
  ).not.toBeInTheDocument();
});

it('does NOT show the buyer note for staff (staff sees their own controls)', () => {
  seedQuote('PROCURING');
  asStaff();
  renderPage();

  expect(screen.queryByText(/being prepared for production/i)).not.toBeInTheDocument();
  // Staff see their own merged panel (folded into the status card) - anchored by
  // the buyer-notification section, which renders for every staff order.
  expect(screen.getByText('Buyer notifications')).toBeInTheDocument();
});

it('shows the Cancel order control to staff on a cancellable quote', () => {
  seedQuote('SENT');
  asStaff();
  renderPage();

  expect(screen.getByRole('button', { name: /cancel order/i })).toBeInTheDocument();
});

it('never shows the Cancel order control to a buyer', () => {
  seedQuote('SENT');
  asBuyer();
  renderPage();

  expect(screen.queryByRole('button', { name: /cancel order/i })).not.toBeInTheDocument();
});

it.each(['READY', 'CLOSED', 'CANCELLED'] as const)(
  'hides the Cancel order control once the quote is %s',
  (state) => {
    seedQuote(state);
    asStaff();
    renderPage();

    expect(screen.queryByRole('button', { name: /cancel order/i })).not.toBeInTheDocument();
  },
);

it('confirming the cancel modal calls cancelQuote with the trimmed reason and closes on success', async () => {
  const cancelQuote = vi.fn(async () => true);
  seedQuote('SENT');
  useQuoteStore.setState({ cancelQuote } as any);
  asStaff();
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: /cancel order/i }));
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
// reference material, so it follows the merged status/actions card; for a buyer
// it carries their proof sign-off, so it stays high on the page. These two tests
// pin that difference - a "simplification" back to one slot breaks one of them.
it('renders Proofs BELOW the merged staff panel for staff', () => {
  seedQuote('ACCEPTED');
  seedOpenProof();
  asStaff();
  renderPage();

  // The staff controls now live inside the status card (no "Staff actions"
  // heading); anchor on the buyer-notification section that closes that panel.
  expect(headingIndex('Proofs')).toBeGreaterThan(headingIndex('Buyer notifications'));

  // Same assertion via the DOM directly, independent of heading enumeration.
  const staffPanel = screen.getByRole('heading', { name: 'Buyer notifications' });
  const proofs = screen.getByRole('heading', { name: 'Proofs' });
  expect(
    staffPanel.compareDocumentPosition(proofs) & Node.DOCUMENT_POSITION_FOLLOWING,
  ).toBeTruthy();
});

it('singles out the approved artwork as the one for production across change rounds', () => {
  seedQuote('PROOF_APPROVED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      proofs: [
        { id: 1, quote_id: 42, version: 1, artwork_version_ref: 'proofs/v1.pdf', state: 'CHANGES_REQUESTED', approved_by: null, approved_at: null, notes: 'Do it like this' },
        { id: 2, quote_id: 42, version: 2, artwork_version_ref: 'proofs/v2.pdf', state: 'CHANGES_REQUESTED', approved_by: null, approved_at: null, notes: 'wrong image' },
        { id: 3, quote_id: 42, version: 3, artwork_version_ref: 'proofs/v3.pdf', state: 'APPROVED', approved_by: 2, approved_at: '2026-07-23T14:13:00Z', notes: null },
      ],
    },
  } as any);
  asStaff();
  renderPage();

  // The callout names the signed-off version explicitly...
  expect(screen.getByText('Approved artwork: v3')).toBeInTheDocument();
  expect(screen.getByText(/goes to production/i)).toBeInTheDocument();
  // ...and the approved row is tagged in place, unlike the rejected versions.
  expect(screen.getByText('Use for production')).toBeInTheDocument();
});

it('keeps the buyer’s proof review ABOVE the Next step card', () => {
  seedQuote('SENT');
  seedOpenProof();
  asBuyer();
  renderPage();

  // The buyer's sign-off is the primary action, so its review card sits high on
  // the page - never demoted below the rest the way the staff proof list is.
  expect(headingIndex('Review your proof')).toBeLessThan(headingIndex('Next step'));
  expect(screen.queryByRole('heading', { name: 'Staff actions' })).not.toBeInTheDocument();
  // The read-only "Proofs" history is suppressed while a proof is open for review.
  expect(screen.queryByRole('heading', { name: 'Proofs' })).not.toBeInTheDocument();

  const review = screen.getByRole('heading', { name: 'Review your proof' });
  const nextStep = screen.getByRole('heading', { name: 'Next step' });
  expect(
    review.compareDocumentPosition(nextStep) & Node.DOCUMENT_POSITION_FOLLOWING,
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

// Bug: the commit/confirm modal rendered no body at all, so a duplicate PO
// reference (backend `unique` -> 422) only ever surfaced on the page-top
// banner - behind the modal overlay staff were still looking at. The modal
// must show its own failure, not leave staff to re-click into the same 422.
it('shows the commit error inside the still-open modal, not only behind it on the page banner', async () => {
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
  await user.click(screen.getAllByRole('button', { name: 'Commit order' })[1]);

  // The modal stays open on failure - and now shows the failure itself.
  const dialog = await screen.findByRole('dialog', { name: /commit this order to production/i });
  expect(within(dialog).getByText('PO reference has already been used.')).toBeInTheDocument();
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
  await userEvent.setup().click(screen.getByRole('button', { name: 'Accept pricing' }));

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
// order had to be cancelled and rebuilt. Staging a revised proof per line and
// re-sending is that way.
it('offers staff the per-line proof controls on a changes-requested order', () => {
  asStaff();
  seedQuote('CHANGES_REQUESTED');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        { id: 1, product_id: 5, qty: 10, line_state: 'PENDING', product: { name: 'Enamel Mug' }, customization: { mode: 'designer', artwork_ref: 'artwork/mug.png' } },
      ],
    },
  } as any);
  renderPage();

  expect(screen.getByRole('button', { name: 'attach:Proof for Enamel Mug' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: /send proofs to buyer/i })).toBeInTheDocument();
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

// F8: the PO reference is required to raise the invoice, so the field is marked
// required at the commit step (mirrors the Commit button's disabled-when-empty
// guard and validatePoRef).
it('marks the PO reference field as required at the commit step', () => {
  asStaff();
  seedQuote('PROOF_APPROVED');
  renderPage();

  const po = screen.getByLabelText(/PO reference/i);
  expect(po).toBeRequired();
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

  // The trigger sits in the Items header; the trail opens as a dialog.
  expect(screen.getByRole('button', { name: 'History' })).toBeInTheDocument();
  expect(screen.queryByText(/Enamel Mug/)).not.toBeInTheDocument();

  fireEvent.click(screen.getByRole('button', { name: 'History' }));
  expect(screen.getByRole('dialog', { name: /edit history/i })).toBeInTheDocument();
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

  expect(screen.queryByRole('button', { name: 'History' })).not.toBeInTheDocument();
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

// "Drop item" just opens the line editor, which only renders when
// `canEditLines` is true (a plain staff_admin past DRAFT can't reach it).
// Product decision: keep the control visible but DISABLED with a reason, so
// staff know it exists and why it's unavailable, rather than a silent no-op or
// a vanished button.
it('disables the Drop item control (with a reason) for a plain staff_admin past DRAFT', () => {
  asStaff();
  seedQuote('PROOFING');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, line_items: [customisedLine()] },
  } as any);
  renderPage();

  expect(screen.getByRole('button', { name: /drop item/i })).toBeDisabled();
  expect(screen.getByText(/editable only on draft/i)).toBeInTheDocument();
});

it('offers an enabled Drop item control on a multi-line DRAFT quote (plain staff_admin can edit lines)', () => {
  asStaff();
  seedQuote('DRAFT');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [customisedLine(), customisedLine({ id: 2 })],
    },
  } as any);
  renderPage();

  expect(screen.getAllByRole('button', { name: /drop item/i })[0]).toBeEnabled();
});

it('offers an enabled Drop item control to a superadmin past DRAFT on a multi-line order', () => {
  asSuperadmin();
  seedQuote('PROOFING');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [customisedLine(), customisedLine({ id: 2 })],
    },
  } as any);
  renderPage();

  expect(screen.getAllByRole('button', { name: /drop item/i })[0]).toBeEnabled();
});

it('disables the Drop item control (with a reason) when only one line remains', () => {
  asSuperadmin();
  seedQuote('DRAFT');
  useQuoteStore.setState({
    current: { ...useQuoteStore.getState().current!, line_items: [customisedLine()] },
  } as any);
  renderPage();

  expect(screen.getByRole('button', { name: /drop item/i })).toBeDisabled();
  expect(screen.getByText(/keep at least one item/i)).toBeInTheDocument();
});

it('offers the buyer finished-look references as selectable proof artwork', async () => {
  asStaff();
  seedQuote('DRAFT');
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      line_items: [
        customisedLine({
          customization: { mode: 'buyer_uploaded', reference_refs: ['ref/look-a.png', 'ref/look-b.png'] },
        }),
      ],
    },
  } as any);
  renderPage();
  const user = userEvent.setup();

  // The reference photos are now artwork options, so the picker is offered.
  await user.click(screen.getByRole('button', { name: /use existing artwork/i }));
  expect(screen.getByText(/reference 1 —/i)).toBeInTheDocument();
  expect(screen.getByText(/reference 2 —/i)).toBeInTheDocument();
});

// Bug: the DRAFT-send helper told staff the buyer could "accept it or request
// changes" at the price-quote stage - but the SENT buyer card only offers
// "Accept quote"; request-changes exists only later, against the proof. Fix
// is copy-only (no new buyer button at this stage).
it('does not claim the buyer can request changes at the price-quote (DRAFT-send) stage', () => {
  asStaff();
  seedQuote('DRAFT');
  renderPage();

  expect(
    screen.queryByText(/they can then accept it or request changes/i),
  ).not.toBeInTheDocument();
  expect(screen.getByText(/they accept the price to move into proofing/i)).toBeInTheDocument();
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

// Staff arrive at an order from the /quotes console list, but the page never
// gave them a way back other than the browser Back button - the breadcrumb
// was buyer-only. A staff-visible link restores that path.
it('gives staff a back-to-Quotes link, since the buyer breadcrumb is hidden for them', () => {
  asStaff();
  seedQuote('SENT');
  renderPage();

  const back = screen.getByRole('link', { name: /back to quotes/i });
  expect(back).toHaveAttribute('href', '/quotes');
});

it('still shows the buyer breadcrumb, unchanged, for a buyer', () => {
  asBuyer();
  seedQuote('SENT');
  renderPage();

  expect(screen.getByRole('navigation', { name: 'Breadcrumb' })).toBeInTheDocument();
  expect(screen.queryByRole('link', { name: /back to quotes/i })).not.toBeInTheDocument();
});

// The header used to render its own state pill right above the OrderStatus
// card's badge, which leads with the same humanizeState(quote.state) - the same
// label appeared twice within ~40px. The card's badge is the one worth keeping:
// it carries the next-step/step-N-of-9 context the header pill lacked.
it('shows the order state badge once, not duplicated between the header and the status card', () => {
  asStaff();
  seedQuote('SENT');
  renderPage();

  // "Sent" is the humanized label for the SENT state.
  expect(screen.getAllByText('Sent')).toHaveLength(1);
});

// B2B invoice payment reconciliation: staff manually record a bank transfer /
// cheque / cash outcome against the invoice raised by `issueInvoice`. There is
// no Stripe path for B2B, so before this the invoice sat UNPAID forever.
function seedInvoice(paymentState: 'UNPAID' | 'PARTIAL' | 'PAID' | 'VOID' = 'UNPAID') {
  useQuoteStore.setState({
    current: {
      ...useQuoteStore.getState().current!,
      invoice: {
        id: 1,
        po_ref: 'PO-42',
        invoice_ref: null,
        amount: '105.00',
        amount_paid: null,
        balance_owed: 0,
        currency: 'SGD',
        payment_state: paymentState,
      },
    },
  } as any);
}

it('shows the Unpaid badge and Mark paid/partial/void controls for staff when an invoice exists', () => {
  asStaff();
  seedQuote('CONFIRMED');
  seedInvoice('UNPAID');
  renderPage();

  expect(screen.getByText('Invoice payment')).toBeInTheDocument();
  expect(screen.getByText('Unpaid')).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Mark paid' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Record partial payment' })).toBeInTheDocument();
  expect(screen.getByRole('button', { name: 'Void invoice' })).toBeInTheDocument();
});

it('never shows the invoice payment control to a buyer', () => {
  asBuyer();
  seedQuote('CONFIRMED');
  seedInvoice('UNPAID');
  renderPage();

  expect(screen.queryByText('Invoice payment')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Mark paid' })).not.toBeInTheDocument();
});

it('hides the invoice payment control for staff when the order has no invoice yet', () => {
  asStaff();
  seedQuote('PROOF_APPROVED');
  renderPage();

  expect(screen.queryByText('Invoice payment')).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Mark paid' })).not.toBeInTheDocument();
});

it('clicking Mark paid calls reconcilePayment with PAID, and the badge reflects Paid after success', async () => {
  asStaff();
  seedQuote('CONFIRMED');
  seedInvoice('UNPAID');
  const reconcilePayment = vi.fn(async (_id: number, paymentState: string) => {
    useQuoteStore.setState((s) => ({
      current: s.current
        ? { ...s.current, invoice: { ...s.current.invoice!, payment_state: paymentState as never } }
        : s.current,
    }) as any);
    return true;
  });
  useQuoteStore.setState({ reconcilePayment } as any);
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: 'Mark paid' }));

  expect(reconcilePayment).toHaveBeenCalledWith(42, 'PAID');
  await waitFor(() => expect(screen.getByText('Paid')).toBeInTheDocument());
  expect(screen.queryByText('Unpaid')).not.toBeInTheDocument();
});

it('recording a partial payment calls reconcilePayment with PARTIAL and the collected amount (H3/M21)', async () => {
  asStaff();
  seedQuote('CONFIRMED');
  seedInvoice('UNPAID');
  const reconcilePayment = vi.fn(async () => true);
  useQuoteStore.setState({ reconcilePayment } as any);
  renderPage();

  // Opens the amount field, then submits the collected figure.
  await userEvent.click(screen.getByRole('button', { name: 'Record partial payment' }));
  await userEvent.type(screen.getByLabelText('Partial amount received'), '40');
  await userEvent.click(screen.getByRole('button', { name: 'Record' }));

  expect(reconcilePayment).toHaveBeenCalledWith(42, 'PARTIAL', undefined, 40);
});

// Voiding is terminal (the backend refuses to reconcile a VOID invoice to any
// other state), so - like cancelling the order - it is confirmed rather than
// fired straight from the button.
it('requires confirmation before voiding an invoice, and sends the trimmed note', async () => {
  asStaff();
  seedQuote('CONFIRMED');
  seedInvoice('UNPAID');
  const reconcilePayment = vi.fn(async () => true);
  useQuoteStore.setState({ reconcilePayment } as any);
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: 'Void invoice' }));
  expect(reconcilePayment).not.toHaveBeenCalled();

  const dialog = await screen.findByRole('dialog', { name: /void this invoice/i });
  await userEvent.type(within(dialog).getByLabelText(/note/i), '  Buyer disputed the charge.  ');
  await userEvent.click(within(dialog).getByRole('button', { name: 'Void invoice' }));

  expect(reconcilePayment).toHaveBeenCalledWith(42, 'VOID', 'Buyer disputed the charge.');
});

it('disables all reconciliation controls once the invoice is Void', () => {
  asStaff();
  seedQuote('CONFIRMED');
  seedInvoice('VOID');
  renderPage();

  expect(screen.getByText('Void')).toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Mark paid' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Record partial payment' })).not.toBeInTheDocument();
  expect(screen.queryByRole('button', { name: 'Void invoice' })).not.toBeInTheDocument();
});

// A rejected write (illegal transition, or the backend's 422 for "no invoice
// yet" on a race) must surface inline, not blank the page - same contract as
// every other staff write on this page (see issueInvoice's actionError tests).
it('surfaces a 422 from reconcilePayment as an inline error, keeping the order on screen', async () => {
  asStaff();
  seedQuote('CONFIRMED');
  seedInvoice('UNPAID');
  useQuoteStore.setState({
    reconcilePayment: async () => {
      useQuoteStore.setState({ actionError: 'This invoice is VOID and cannot be reconciled to another state.' } as any);
      return false;
    },
  } as any);
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: 'Mark paid' }));

  const alert = await screen.findByRole('alert');
  expect(alert).toHaveTextContent('This invoice is VOID and cannot be reconciled to another state.');
  expect(screen.getByRole('heading', { name: /Order 9BWVKWCDXH/i })).toBeInTheDocument();
  // Still Unpaid: the rejected write never landed, so the badge did not move.
  expect(screen.getByText('Unpaid')).toBeInTheDocument();
});

// Same failure via the Void confirm modal: the modal itself must show the
// rejection (mirrors the commit modal's own 422 test), not only the page banner.
it('shows the void-reconciliation error inside the still-open modal', async () => {
  asStaff();
  seedQuote('CONFIRMED');
  seedInvoice('UNPAID');
  useQuoteStore.setState({
    reconcilePayment: async () => {
      useQuoteStore.setState({ actionError: 'This order has no invoice to reconcile yet.' } as any);
      return false;
    },
  } as any);
  renderPage();

  await userEvent.click(screen.getByRole('button', { name: 'Void invoice' }));
  const dialog = await screen.findByRole('dialog', { name: /void this invoice/i });
  await userEvent.click(within(dialog).getByRole('button', { name: 'Void invoice' }));

  expect(within(dialog).getByText('This order has no invoice to reconcile yet.')).toBeInTheDocument();
});
