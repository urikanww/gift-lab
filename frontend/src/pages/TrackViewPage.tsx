import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import api, { apiError } from '../lib/api';
import { Card } from '../ui';
import { Motion, fadeInUp } from '../motion';
import { TrackResultView } from '../components/TrackResultView';
import type { TrackResult } from '../types';

/**
 * Signed one-click tracker. The buyer arrives from a bookmark/QR carrying
 * ?code=..&signature=..; we forward that exact query to the signed API route,
 * which validates the signature (no email needed) and returns the same payload
 * TrackPage renders. On any failure we point them back to the manual tracker.
 */
export default function TrackViewPage() {
  const { search } = useLocation();
  const [result, setResult] = useState<TrackResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api
      .get<TrackResult>(`/track/view${search}`)
      .then(({ data }) => {
        if (active) setResult(data);
      })
      .catch((err) => {
        if (active) setError(apiError(err) || 'This tracking link is invalid or has expired.');
      });
    return () => {
      active = false;
    };
  }, [search]);

  return (
    <Motion variants={fadeInUp} initial="hidden" animate="visible" className="mx-auto flex w-full max-w-xl flex-col gap-6">
      <h1 className="font-display text-3xl text-fg sm:text-4xl">Order status</h1>
      {error && (
        <Card padding="lg">
          <p className="text-sm text-danger" role="alert">
            {error}
          </p>
          <a href="/track" className="mt-2 inline-block text-sm text-primary underline">
            Track manually instead
          </a>
        </Card>
      )}
      {result && <TrackResultView result={result} />}
    </Motion>
  );
}
