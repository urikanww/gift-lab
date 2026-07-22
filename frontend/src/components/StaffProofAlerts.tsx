import { useEffect } from 'react';
import { joinSharedPrivate, leaveSharedPrivate } from '../lib/echo';
import { useToast } from '../ui';

/**
 * Live staff toast for buyer actions that need a hand. Renders nothing - it is a
 * side-effect-only subscriber, mounted once inside the staff shell.
 *
 * Today it surfaces "buyer requested changes": the same `proof.changes-requested`
 * event the dashboard store listens to for the Quotes badge, turned into a
 * transient toast so an operator sees it even when not on the dashboard. The
 * badge (persistent count) and this toast (momentary nudge) are deliberately
 * both wired - one survives a refresh, the other catches the eye.
 */
interface ProofChangesRequestedPush {
  quote_reference?: string | null;
  version?: number;
  notes?: string | null;
}

export default function StaffProofAlerts() {
  const { toast } = useToast();

  useEffect(() => {
    const channel = joinSharedPrivate('staff.queue');

    const onChangesRequested = (e: ProofChangesRequestedPush) => {
      const ref = e.quote_reference ?? 'an order';
      toast({
        title: `Changes requested on ${ref}`,
        description: e.notes ? `“${e.notes}”` : 'The buyer sent the proof back for changes.',
        tone: 'warning',
        // Sticky-ish: a buyer nudge is worth a longer read than a routine save.
        duration: 10000,
      });
    };

    channel.listen('.proof.changes-requested', onChangesRequested);

    return () => {
      channel.stopListening('.proof.changes-requested', onChangesRequested);
      leaveSharedPrivate('staff.queue');
    };
  }, [toast]);

  return null;
}
