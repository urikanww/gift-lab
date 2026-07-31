import type { FilterField, FilterValues, RangeValue } from '../components/filters/types';
import type { JobTrack, PrintMethod, ProductionJob } from '../types';

/**
 * Filter config + client-side predicate for the Production queue board. Unlike
 * the Quotes list (which maps its values onto API params and refetches), this
 * page loads ALL jobs once via queueStore and splits them into tabs client-side.
 * So there is no params mapper here — the popup's values are turned into a
 * predicate that narrows the already-loaded jobs, and applying (or clearing a
 * badge) just re-runs that predicate. Same two-function shape, second function
 * adapted to a predicate.
 *
 * Value sets are pinned to the job type's own unions:
 *  - track        → JobTrack = 'UV' | '3D'
 *  - print_method → PrintMethod = 'UV' | 'FDM' | 'RESIN' (nullable on the job)
 */

const TRACKS: JobTrack[] = ['UV', '3D'];
const PRINT_METHODS: PrintMethod[] = ['UV', 'FDM', 'RESIN'];

export function productionFilterFields(): FilterField[] {
  return [
    {
      key: 'track',
      label: 'Track',
      type: 'multiselect',
      options: TRACKS.map((t) => ({ value: t, label: t })),
    },
    {
      key: 'print_method',
      label: 'Print method',
      type: 'multiselect',
      options: PRINT_METHODS.map((m) => ({ value: m, label: m })),
    },
    { key: 'ready', label: 'Ready date', type: 'daterange' },
  ];
}

/**
 * Whether a job passes the active filters. An empty/absent filter always passes,
 * so `{}` lets every job through. The date range compares the date portion of
 * `ready_at` (yyyy-mm-dd) against the from/to bounds, inclusive; a job with no
 * ready_at fails a set range (it has no date to fall inside it).
 */
export function matchesProductionFilters(job: ProductionJob, values: FilterValues): boolean {
  const track = values.track;
  if (Array.isArray(track) && track.length && !track.includes(job.track)) {
    return false;
  }

  const method = values.print_method;
  if (Array.isArray(method) && method.length) {
    // A job with no print_method can never be inside a non-empty method filter.
    if (!job.print_method || !method.includes(job.print_method)) return false;
  }

  const ready = values.ready as RangeValue | undefined;
  if (ready?.from || ready?.to) {
    if (!job.ready_at) return false;
    const day = job.ready_at.slice(0, 10); // yyyy-mm-dd portion, lexically comparable
    if (ready.from && day < ready.from) return false;
    if (ready.to && day > ready.to) return false;
  }

  return true;
}
