import type { DashboardActivity } from './dashboard';

export type ActivityCategory = 'order' | 'catalogue' | 'user' | 'production' | 'system';

/** Category from the event prefix. Unknown prefixes read as "system". */
export function activityCategory(event: string): ActivityCategory {
  const prefix = event.split('.')[0];
  switch (prefix) {
    case 'quote':
    case 'invoice':
    case 'credit_note':
    case 'payment':
      return 'order';
    case 'product':
    case 'variant':
      return 'catalogue';
    case 'user':
      return 'user';
    case 'proof':
    case 'production_job':
    case 'supplier_reorder':
    case 'line_item':
      return 'production';
    default:
      return 'system';
  }
}

const VERBS: Record<string, string> = {
  'quote.amended': 'amended',
  'quote.approval_order_changed': 'reordered approvals on',
  'quote.cancelled': 'cancelled',
  'quote.chase_exhausted': 'exhausted chase reminders on',
  'quote.stock_confirmed': 'confirmed stock on',
  'invoice.issued': 'issued an invoice for',
  'invoice.voided': 'voided the invoice for',
  'invoice.retotaled': 'retotaled the invoice for',
  'invoice.parcel_returned': 'logged a returned parcel for',
  'credit_note.issued': 'issued a credit note for',
  'payment.captured': 'recorded a payment on',
  'payment.reconciled': 'reconciled payment on',
  'product.created': 'created',
  'product.updated': 'updated',
  'product.archived': 'archived',
  'product.restored': 'restored',
  'product.blockers_resolved': 'resolved blockers on',
  'product.gate_deleted': 'deleted',
  'product.image_updated': 'updated the image of',
  'product.image_removed': 'removed the image of',
  'variant.created': 'created',
  'variant.updated': 'updated',
  'variant.archived': 'archived',
  'variant.bulk_created': 'bulk-created variants of',
  'user.created': 'created',
  'user.updated': 'updated',
  'user.deactivated': 'deactivated',
  'user.reactivated': 'reactivated',
  'user.password_reset': 'reset the password for',
  'proof.approved': 'approved a proof on',
  'proof.resent': 'resent a proof on',
  'production_job.manually_delivered': 'marked delivered',
  'production_job.return_resolved': 'resolved a return on',
  'supplier_reorder.received': 'received a reorder for',
  'line_item.bought': 'bought a line item on',
  'line_item.procured': 'procured a line item on',
  'courier_config.updated': 'updated courier config',
  'pricing_config.updated': 'updated pricing config',
  'notification_setting.updated': 'updated a notification setting',
  'notification_cadence.updated': 'updated notification cadence',
};

/** Relative time via Intl; empty string for a null timestamp. */
export function timeAgo(iso: string | null, now: Date = new Date()): string {
  if (!iso) return '';
  const then = new Date(iso).getTime();
  const diffSec = Math.round((then - now.getTime()) / 1000);
  const abs = Math.abs(diffSec);
  const rtf = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
  const units: [Intl.RelativeTimeFormatUnit, number][] = [
    ['year', 31536000], ['month', 2592000], ['day', 86400],
    ['hour', 3600], ['minute', 60], ['second', 1],
  ];
  for (const [unit, secs] of units) {
    if (abs >= secs || unit === 'second') {
      return rtf.format(Math.round(diffSec / secs), unit);
    }
  }
  return '';
}

export interface HumanizedActivity {
  category: ActivityCategory;
  text: string;
  when: string;
  title: string;
}

/**
 * Events whose verb already names the thing acted on - config/settings
 * singletons. Appending the generic "Type #id" label would just double the
 * noun ("updated pricing config PricingConfig #1"), so drop the label for these.
 */
const LABELLESS_EVENTS = new Set<string>([
  'courier_config.updated',
  'pricing_config.updated',
  'notification_setting.updated',
  'notification_cadence.updated',
]);

export function humanizeActivity(a: DashboardActivity, now: Date = new Date()): HumanizedActivity {
  const actor = a.actor ?? 'System';
  const verb = VERBS[a.event] ?? a.event.replace(/[._]/g, ' ');
  const body = LABELLESS_EVENTS.has(a.event) ? verb : `${verb} ${a.auditableLabel}`;
  return {
    category: activityCategory(a.event),
    text: `${actor} ${body}`.trim(),
    when: timeAgo(a.at, now),
    title: a.at ? new Date(a.at).toLocaleString() : '',
  };
}
