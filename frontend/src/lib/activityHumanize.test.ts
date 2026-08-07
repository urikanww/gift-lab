import { describe, expect, it } from 'vitest';
import { humanizeActivity, activityCategory, timeAgo } from './activityHumanize';
import type { DashboardActivity } from './dashboard';

function act(partial: Partial<DashboardActivity>): DashboardActivity {
  return {
    id: 1,
    actor: 'Jane',
    event: 'quote.amended',
    auditableType: 'Quote',
    auditableId: 1,
    auditableLabel: 'Order 9BWVKW',
    at: '2026-08-07T10:00:00Z',
    ...partial,
  };
}

describe('activityCategory', () => {
  it('derives category from the event prefix', () => {
    expect(activityCategory('quote.amended')).toBe('order');
    expect(activityCategory('payment.captured')).toBe('order');
    expect(activityCategory('product.created')).toBe('catalogue');
    expect(activityCategory('variant.updated')).toBe('catalogue');
    expect(activityCategory('user.deactivated')).toBe('user');
    expect(activityCategory('proof.approved')).toBe('production');
    expect(activityCategory('pricing_config.updated')).toBe('system');
    expect(activityCategory('totally.unknown')).toBe('system');
  });
});

describe('humanizeActivity', () => {
  it('renders a known event as an actor-first sentence', () => {
    const r = humanizeActivity(act({ event: 'quote.amended', actor: 'Jane', auditableLabel: 'Order 9BWVKW' }));
    expect(r.text).toBe('Jane amended Order 9BWVKW');
    expect(r.category).toBe('order');
  });

  it('uses "System" when there is no actor', () => {
    const r = humanizeActivity(act({ actor: null, event: 'quote.chase_exhausted', auditableLabel: 'Order 9BWVKW' }));
    expect(r.text.startsWith('System ')).toBe(true);
  });

  it('falls back to a readable phrase for an unknown event (no raw dotted token)', () => {
    const r = humanizeActivity(act({ event: 'weird.new_thing', actor: 'Jane', auditableLabel: 'Product #5' }));
    expect(r.text).toBe('Jane weird new thing Product #5');
    expect(r.category).toBe('system');
    expect(r.text).not.toContain('weird.new_thing');
  });

  it('provides a relative "when" and an absolute "title"', () => {
    const r = humanizeActivity(act({ at: '2026-08-07T10:00:00Z' }));
    expect(typeof r.when).toBe('string');
    expect(r.when.length).toBeGreaterThan(0);
    expect(r.title).toContain('2026');
  });

  it('handles a null timestamp without throwing', () => {
    const r = humanizeActivity(act({ at: null }));
    expect(r.when).toBe('');
    expect(r.title).toBe('');
  });
});

describe('timeAgo', () => {
  const now = new Date('2026-08-07T12:00:00Z');
  it('formats past and future with correct unit and direction', () => {
    expect(timeAgo('2026-08-07T10:00:00Z', now)).toBe('2 hours ago');
    expect(timeAgo('2026-08-07T11:59:30Z', now)).toBe('30 seconds ago');
    expect(timeAgo('2026-08-07T14:00:00Z', now)).toBe('in 2 hours');
    expect(timeAgo(null, now)).toBe('');
  });
});
