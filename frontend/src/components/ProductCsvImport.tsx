import { useRef, useState } from 'react';
import api, { apiError, ensureCsrf } from '../lib/api';
import { Button, Modal, useToast } from '../ui';
import { UploadIcon } from './icons';

/** CSV columns the importer recognises — also the downloadable template header. */
const TEMPLATE_COLUMNS = [
  'name', 'class', 'category', 'description', 'base_cost', 'currency', 'min_order_qty',
  'dim_l', 'dim_w', 'dim_h', 'weight', 'print_method', 'stock_mode', 'allow_backorder',
  'license', 'creator_credit', 'is_printable', 'publish_state', 'image_url', 'source_url',
  'source_product_id', 'model_file_ref', 'filament_material', 'filament_color',
  'est_grams', 'est_print_minutes',
];

interface ImportError {
  line: number;
  name: string;
  errors: string[];
}
interface ImportWarning {
  line: number;
  name: string;
  warnings: string[];
}
interface ImportResult {
  total_rows: number;
  created: number;
  updated: number;
  skipped: number;
  errors: ImportError[];
  warnings: ImportWarning[];
}

/**
 * Superadmin CSV product importer. Uploads a scraper-format CSV; the API
 * validates every row before writing and returns a per-row report. Products
 * import PENDING — this never publishes anything.
 */
export default function ProductCsvImport({ onImported }: { onImported: () => void }) {
  const { toast } = useToast();
  const fileInput = useRef<HTMLInputElement>(null);
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<ImportResult | null>(null);

  const reset = () => {
    setResult(null);
    if (fileInput.current) fileInput.current.value = '';
  };

  const downloadTemplate = () => {
    const blob = new Blob([TEMPLATE_COLUMNS.join(',') + '\n'], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'products-template.csv';
    a.click();
    URL.revokeObjectURL(url);
  };

  const submit = async (file: File | undefined) => {
    if (!file) return;
    setBusy(true);
    setResult(null);
    try {
      await ensureCsrf();
      const form = new FormData();
      form.append('file', file);
      const { data } = await api.post<{ data: ImportResult }>('/admin/products/import', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setResult(data.data);
      onImported();
      toast({
        title: 'Import complete',
        description: `${data.data.created} created, ${data.data.updated} updated, ${data.data.skipped} skipped`,
        tone: data.data.skipped > 0 ? 'warning' : 'success',
      });
    } catch (err) {
      toast({ title: 'Import failed', description: apiError(err), tone: 'danger' });
    } finally {
      setBusy(false);
      if (fileInput.current) fileInput.current.value = '';
    }
  };

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)} aria-label="Import CSV" title="Import products from CSV">
        <UploadIcon />
      </Button>

      <Modal
        open={open}
        onClose={() => {
          setOpen(false);
          reset();
        }}
        title="Import products from CSV"
        size="lg"
        footer={
          <Button
            variant="ghost"
            onClick={() => {
              setOpen(false);
              reset();
            }}
          >
            Close
          </Button>
        }
      >
        <div className="flex flex-col gap-4">
          <p className="text-sm text-fg-muted">
            Upload a scraper-format CSV. Every row is validated before anything is written — invalid
            rows are skipped and reported. Products import as <strong>Pending</strong> and never
            publish automatically.
          </p>

          <div className="flex flex-wrap items-center gap-2">
            <input
              ref={fileInput}
              type="file"
              accept=".csv,text/csv"
              className="hidden"
              onChange={(e) => void submit(e.target.files?.[0])}
            />
            <Button loading={busy} disabled={busy} onClick={() => fileInput.current?.click()}>
              Choose CSV file
            </Button>
            <Button variant="ghost" onClick={downloadTemplate} disabled={busy}>
              Download template
            </Button>
          </div>

          {result && (
            <div className="flex flex-col gap-3 rounded-md border border-border bg-surface-2/50 p-3">
              <div className="flex flex-wrap gap-4 text-sm">
                <span className="text-fg">
                  <strong>{result.total_rows}</strong> rows
                </span>
                <span className="text-success">
                  <strong>{result.created}</strong> created
                </span>
                <span className="text-fg">
                  <strong>{result.updated}</strong> updated
                </span>
                <span className={result.skipped > 0 ? 'text-danger' : 'text-fg-subtle'}>
                  <strong>{result.skipped}</strong> skipped
                </span>
              </div>

              {result.errors.length > 0 && (
                <div className="flex flex-col gap-1">
                  <p className="text-xs font-semibold text-danger">Skipped rows</p>
                  <ul className="max-h-48 overflow-y-auto text-xs text-fg-muted">
                    {result.errors.map((e) => (
                      <li key={e.line} className="border-b border-border/50 py-1">
                        <span className="font-medium text-fg">Line {e.line}</span>
                        {e.name && <span> · {e.name}</span>}
                        <span className="text-danger"> — {e.errors.join('; ')}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              )}

              {result.warnings.length > 0 && (
                <div className="flex flex-col gap-1">
                  <p className="text-xs font-semibold text-warning">Warnings</p>
                  <ul className="max-h-32 overflow-y-auto text-xs text-fg-muted">
                    {result.warnings.map((w) => (
                      <li key={w.line} className="py-0.5">
                        <span className="font-medium text-fg">Line {w.line}</span> — {w.warnings.join('; ')}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
        </div>
      </Modal>
    </>
  );
}
