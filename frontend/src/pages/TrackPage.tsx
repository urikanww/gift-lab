import { useEffect, useState, type FormEvent } from 'react';
import { AxiosError } from 'axios';
import api, { apiError } from '../lib/api';
import { getEcho } from '../lib/echo';
import { Badge, Button, Card, Input, cn } from '../ui';
import { Motion, fadeInUp } from '../motion';

interface TrackStage {
  code: string;
  label: string;
}

interface TrackResult {
  reference: string;
  stage: string;
  stage_label: string;
  cancelled: boolean;
  stages: TrackStage[];
  placed_at: string | null;
  updated_at: string | null;
}

/**
 * Login-free order tracking. Opaque code + first-5-of-email → read-only status.
 * No account, no pricing, no line detail — mirrors the public API contract.
 */
export default function TrackPage() {
  const [code, setCode] = useState('');
  const [email, setEmail] = useState('');
  const [result, setResult] = useState<TrackResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  // Live updates: once an order is found, subscribe to its PUBLIC tracking
  // channel (keyed by the opaque code — no auth) so the stage refreshes the
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
    } catch (err) {
      // 404 (generic anti-enumeration miss) and 422 (validation jargon) both
      // just mean "not found" to a visitor — always show the friendly line.
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

function TrackResultView({ result }: { result: TrackResult }) {
  const currentIdx = result.stages.findIndex((s) => s.code === result.stage);

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible">
      <Card padding="lg" className="flex flex-col gap-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-2xs uppercase tracking-wide text-fg-subtle">Order</p>
            <p className="font-display text-xl text-fg">{result.reference}</p>
          </div>
          <Badge tone={result.cancelled ? 'danger' : 'brand'} size="md" dot>
            {result.stage_label}
          </Badge>
        </div>

        {result.cancelled ? (
          <p className="text-sm text-fg-muted">This order was cancelled. Contact us if you think this is wrong.</p>
        ) : (
          <ol className="flex flex-col gap-3" aria-label="Order progress">
            {result.stages.map((step, i) => {
              const done = i < currentIdx;
              const active = i === currentIdx;
              return (
                <li key={step.code} className="flex items-center gap-3">
                  <span
                    className={cn(
                      'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                      active
                        ? 'bg-primary text-primary-fg'
                        : done
                          ? 'bg-success text-white'
                          : 'bg-surface-2 text-fg-subtle',
                    )}
                    aria-hidden="true"
                  >
                    {done ? '✓' : i + 1}
                  </span>
                  <span
                    className={cn(
                      'text-sm',
                      active ? 'font-semibold text-fg' : done ? 'text-fg-muted' : 'text-fg-subtle',
                    )}
                  >
                    {step.label}
                    {active && <span className="sr-only"> (current status)</span>}
                  </span>
                </li>
              );
            })}
          </ol>
        )}

        {result.updated_at && (
          <p className="text-xs text-fg-subtle">
            Last updated {new Date(result.updated_at).toLocaleString()}
          </p>
        )}
      </Card>
    </Motion>
  );
}
