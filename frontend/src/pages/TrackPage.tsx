import { useEffect, useState, type FormEvent } from 'react';
import { AxiosError } from 'axios';
import api, { apiError } from '../lib/api';
import { getEcho } from '../lib/echo';
import { Button, Card, Input } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { TrackResultView } from '../components/TrackResultView';
import type { TrackResult } from '../types';

/**
 * Login-free order tracking. Opaque code + first-5-of-email → read-only status.
 * No account, no pricing, no line detail - mirrors the public API contract.
 */
export default function TrackPage() {
  const [code, setCode] = useState(() => localStorage.getItem('gl.track.code') ?? '');
  const [email, setEmail] = useState(() => localStorage.getItem('gl.track.email') ?? '');
  const [result, setResult] = useState<TrackResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Live updates: once an order is found, subscribe to its PUBLIC tracking
  // channel (keyed by the opaque code - no auth) so the stage refreshes the
  // moment the floor advances the job. No polling.
  const reference = result?.reference ?? null;
  useEffect(() => {
    if (!reference) return;
    const channelName = `track.${reference}`;
    getEcho()
      .channel(channelName)
      .listen(
        '.order.tracking-updated',
        (e: { stage: string; stage_label: string; cancelled: boolean; updated_at: string | null }) => {
          setResult((prev) =>
            prev
              ? { ...prev, stage: e.stage, stage_label: e.stage_label, cancelled: e.cancelled, updated_at: e.updated_at }
              : prev,
          );
        },
      );
    return () => {
      getEcho().leaveChannel(channelName);
    };
  }, [reference]);

  const onSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setResult(null);
    try {
      const { data } = await api.post<TrackResult>('/track', {
        tracking_code: code.trim(),
        email: email.trim(),
      });
      setResult(data);
      localStorage.setItem('gl.track.code', code.trim());
      localStorage.setItem('gl.track.email', email.trim());
    } catch (err) {
      // 404 (generic anti-enumeration miss) and 422 (validation jargon) both
      // just mean "not found" to a visitor - always show the friendly line.
      // Keep the server's message only where it carries real signal (429
      // throttle, 5xx outage).
      const status = err instanceof AxiosError ? err.response?.status : undefined;
      if (status === 404 || status === 422) {
        setError('No order matches those details.');
      } else {
        setError(apiError(err) || 'No order matches those details.');
      }
    } finally {
      setBusy(false);
    }
  };

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mx-auto flex w-full max-w-xl flex-col gap-6">
      <header className="flex flex-col gap-2">
        <h1 className="font-display text-3xl leading-tight text-fg sm:text-4xl">Track your order</h1>
        <p className="text-fg-muted">
          Enter the tracking code from your order confirmation and the email it was sent to. No account needed.
        </p>
      </header>

      <Card padding="lg">
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Input
            label="Tracking code"
            placeholder="GL-XXXXXX"
            value={code}
            autoCapitalize="characters"
            maxLength={16}
            onChange={(e) => setCode(e.target.value)}
            required
          />
          <Input
            label="Order email"
            type="email"
            placeholder="you@company.com"
            value={email}
            maxLength={255}
            onChange={(e) => setEmail(e.target.value)}
            hint="We only check the first few characters."
            required
          />
          {error && (
            <p className="text-sm text-danger" role="alert">
              {error}
            </p>
          )}
          <Button type="submit" size="lg" loading={busy} disabled={busy || !code || !email}>
            {busy ? 'Checking…' : 'Track order'}
          </Button>
        </form>
      </Card>

      {result && <TrackResultView result={result} />}
    </Motion>
  );
}
