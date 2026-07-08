import { afterEach, expect, it, vi } from 'vitest';
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
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
});

function seedQuote(state: QuoteState) {
  useQuoteStore.setState({
    current: {
      id: 42,
      company_id: 7,
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
  const issuePurchaseOrder = vi.fn(async () => {});
  seedQuote('PROOF_APPROVED');
  useQuoteStore.setState({ issuePurchaseOrder } as any);
  asStaff();
  renderPage();

  await userEvent.type(screen.getByLabelText(/po reference/i), '   ');
  await userEvent.click(screen.getByRole('button', { name: /issue po/i }));

  expect(issuePurchaseOrder).not.toHaveBeenCalled();
  expect(screen.getByText(/enter the po number/i)).toBeInTheDocument();
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
