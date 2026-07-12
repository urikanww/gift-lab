import { useState } from 'react';
import { Badge, Button, Card, Input, useOptionalToast } from '../ui';
import { apiError } from '../lib/api';
import { addBlank, featureCandidate, searchCandidates, type Candidate } from '../lib/recommendations';

export default function BlankRecommendationPage() {
  const { toast } = useOptionalToast();
  const [keyword, setKeyword] = useState('');
  const [loading, setLoading] = useState(false);
  const [candidates, setCandidates] = useState<Candidate[]>([]);
  const [busy, setBusy] = useState<string | null>(null);

  const run = async () => {
    if (!keyword.trim() || loading) return;
    setLoading(true);
    try {
      setCandidates(await searchCandidates(keyword.trim()));
    } catch (err) {
      toast({ title: 'Search failed', description: apiError(err), tone: 'danger' });
    } finally {
      setLoading(false);
    }
  };

  const act = async (c: Candidate, kind: 'add' | 'feature') => {
    setBusy(`${kind}:${c.source_product_id}`);
    try {
      if (kind === 'add') await addBlank(c);
      else await featureCandidate(c);
      toast({ title: kind === 'add' ? 'Added to gate' : 'Featured', description: c.name, tone: 'success' });
    } catch (err) {
      toast({ title: 'Action failed', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(null);
    }
  };

  return (
    <section className="flex flex-col gap-6">
      <div>
        <h1 className="font-display text-3xl text-fg">Blank recommendations</h1>
        <p className="mt-1 text-sm text-fg-muted">
          Search Shopee for UV-printable blanks. Add promising ones to the gate, or feature them on the public gift-ideas page.
        </p>
      </div>

      <div className="flex items-end gap-2">
        <div className="flex-1">
          <Input label="Keyword" value={keyword} onChange={(e) => setKeyword(e.target.value)} placeholder="ceramic mug, acrylic keychain…" />
        </div>
        <Button loading={loading} onClick={() => void run()}>Search</Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {candidates.map((c) => (
          <Card key={c.source_product_id} padding="md" className="flex flex-col gap-2">
            {c.image_url && <img src={c.image_url} alt="" className="aspect-square w-full rounded object-cover" referrerPolicy="no-referrer" />}
            <p className="line-clamp-2 text-sm font-medium text-fg">{c.name}</p>
            <div className="flex flex-wrap gap-1.5 text-xs text-fg-subtle">
              <span className="font-semibold text-fg">{c.currency} {c.price ?? '—'}</span>
              <span>{`· ${c.sales} sold`}</span>
              {c.rating_star != null && <span>· ★ {c.rating_star}</span>}
            </div>
            <div className="flex flex-wrap gap-1.5">
              {c.ip_flag && <Badge tone="danger" size="sm">IP: {c.ip_flag}</Badge>}
              {c.material_flag && <Badge tone="warning" size="sm">{c.material_flag}</Badge>}
            </div>
            <div className="mt-auto flex gap-2 pt-2">
              <Button size="sm" loading={busy === `add:${c.source_product_id}`} onClick={() => void act(c, 'add')}>Add as blank</Button>
              <Button size="sm" variant="outline" loading={busy === `feature:${c.source_product_id}`} onClick={() => void act(c, 'feature')}>Feature</Button>
            </div>
          </Card>
        ))}
      </div>
    </section>
  );
}
