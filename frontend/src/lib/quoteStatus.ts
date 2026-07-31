import type { BadgeTone } from '../ui';
import type { LineItemState, ProofState, QuoteState } from '../types';

/**
 * Maps domain state enums to design-system Badge tones so status pills read
 * consistently across the quote list and quote detail surfaces. Deny-by-default
 * to `neutral` for any unmapped/future value.
 */
const quoteToneMap: Partial<Record<QuoteState, BadgeTone>> = {
  DRAFT: 'neutral',
  SENT: 'info',
  CHANGES_REQUESTED: 'warning',
  ACCEPTED: 'info',
  PROOFING: 'info',
  ARTWORK_APPROVED: 'info',
  PROOF_APPROVED: 'brand',
  INVOICED: 'brand',
  CONFIRMED: 'brand',
  PROCURING: 'brand',
  READY: 'success',
  CLOSED: 'success',
  CANCELLED: 'danger',
};

export function quoteStateTone(state: QuoteState): BadgeTone {
  return quoteToneMap[state] ?? 'neutral';
}

const lineToneMap: Partial<Record<LineItemState, BadgeTone>> = {
  PENDING: 'neutral',
  PROCURING: 'info',
  PURCHASED: 'info',
  INBOUND: 'info',
  RECEIVED: 'brand',
  READY: 'success',
  AWAITING_RECONFIRM: 'warning',
  AMENDED: 'warning',
  DROPPED: 'danger',
  CANCELLED: 'danger',
  DELIVERED: 'success',
};

export function lineStateTone(state: LineItemState): BadgeTone {
  return lineToneMap[state] ?? 'neutral';
}

const proofToneMap: Record<ProofState, BadgeTone> = {
  DRAFT: 'neutral',
  SENT: 'info',
  CHANGES_REQUESTED: 'warning',
  APPROVED: 'success',
};

export function proofStateTone(state: ProofState): BadgeTone {
  return proofToneMap[state] ?? 'neutral';
}

/** Human-friendly label: SNAKE_CASE enum → "Snake case". */
export function humanizeState(state: string): string {
  return state
    .toLowerCase()
    .replace(/_/g, ' ')
    .replace(/^\w/, (c) => c.toUpperCase());
}

/**
 * Buyer-facing plain-language labels (L1). The staff surfaces keep the raw
 * humanized enum (staff know the pipeline vocabulary), but a buyer shouldn't
 * meet internal words like "Procuring" or "Invoiced" - they get outcome-focused
 * wording instead. Anything unmapped falls back to the humanized enum so a new
 * state never renders blank.
 */
const buyerLabelMap: Partial<Record<QuoteState, string>> = {
  SENT: 'Quote sent',
  CHANGES_REQUESTED: 'Changes requested',
  ACCEPTED: 'Accepted — preparing your proof',
  PROOFING: 'Preparing your proof',
  ARTWORK_APPROVED: 'Artwork approved',
  PROOF_APPROVED: 'Approved — next up: payment',
  INVOICED: 'Invoice issued',
  CONFIRMED: 'Order confirmed',
  PROCURING: 'Preparing your order',
  READY: 'Ready',
  CLOSED: 'Delivered',
  CANCELLED: 'Cancelled',
};

export function buyerStateLabel(state: QuoteState): string {
  return buyerLabelMap[state] ?? humanizeState(state);
}
