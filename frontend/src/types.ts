// Domain types mirroring the Laravel API resources + enum string values.

export type UserRole = 'buyer' | 'staff_admin' | 'superadmin';
export type ProductClass = 'CORE' | 'SCRAPED_UV' | 'MODEL_3D';
export type PrintMethod = 'UV' | 'FDM' | 'RESIN';

export type QuoteState =
  | 'DRAFT'
  | 'SENT'
  | 'CHANGES_REQUESTED'
  | 'ACCEPTED'
  | 'PROOFING'
  | 'PROOF_APPROVED'
  | 'PO_ISSUED'
  | 'CONFIRMED'
  | 'PROCURING'
  | 'READY'
  | 'CLOSED'
  | 'CANCELLED';

export type LineItemState =
  | 'PENDING'
  | 'PROCURING'
  | 'PURCHASED'
  | 'INBOUND'
  | 'RECEIVED'
  | 'READY'
  | 'AWAITING_RECONFIRM'
  | 'AMENDED'
  | 'DROPPED'
  | 'CANCELLED';

export type JobTrack = 'UV' | '3D';
export type JobState = 'READY' | 'IN_PRODUCTION' | 'SHIPPED' | 'CLOSED';
export type ProofState = 'SENT' | 'CHANGES_REQUESTED' | 'APPROVED';

export interface User {
  id: number;
  company_id: number | null;
  name: string;
  email: string;
  role: UserRole;
}

export interface Variant {
  id: number;
  attributes: Record<string, string>;
  sku: string | null;
  price_delta: string;
  currency: string;
  in_stock: boolean;
}

export interface Product {
  id: number;
  name: string;
  description: string | null;
  class: ProductClass;
  from_price: number;
  currency: string;
  dimensions: Record<string, number | string> | null;
  weight: string | null;
  print_method: PrintMethod | null;
  stock_mode: string;
  image_url: string | null;
  is_printable: boolean;
  creator_credit: string | null;
  variants?: Variant[];
}

export interface Customization {
  logo_size?: string | null;
  name_text?: string | null;
  artwork_ref?: string | null;
}

export interface LineItem {
  id: number;
  quote_id: number;
  job_id: number | null;
  product_id: number;
  variant_id: number | null;
  qty: number;
  unit_price: string;
  currency: string;
  line_total: string;
  customization: Customization | null;
  line_state: LineItemState;
  procured_qty: number | null;
  procured_price: string | null;
  lead_time_days: number | null;
  product?: Product;
}

export interface Proof {
  id: number;
  quote_id: number;
  version: number;
  artwork_version_ref: string;
  state: ProofState;
  approved_by: number | null;
  approved_at: string | null;
  notes: string | null;
}

export interface Quote {
  id: number;
  company_id: number;
  state: QuoteState;
  currency: string;
  subtotal: string;
  delivery: string;
  total: string;
  price_snapshot_at: string | null;
  notes: string | null;
  line_items?: LineItem[];
  proofs?: Proof[];
  created_at: string | null;
}

export interface ProductionJob {
  id: number;
  quote_id: number;
  track: JobTrack;
  state: JobState;
  ready_at: string | null;
  artwork_ref: string | null;
  print_method: PrintMethod | null;
  qty: number;
}

export interface PriceEstimateLine {
  unit_price: number;
  line_total: number;
}

export interface PriceEstimate {
  currency: string;
  lines: PriceEstimateLine[];
  subtotal: number;
  delivery: number;
  total: number;
}

// A designer cart line held client-side before a quote is requested.
export interface CartLine {
  key: string;
  product: Product;
  variant: Variant | null;
  qty: number;
  customization: Customization;
}

export type PublishState = 'PENDING' | 'READY_TO_APPROVE' | 'PUBLISHED' | 'CANNOT_PUBLISH';

export interface AdminCatalogueItem {
  id: number;
  name: string;
  class: ProductClass;
  publish_state: PublishState;
  cannot_publish_reasons: string[] | null;
  base_cost: string;
  currency: string;
  creator_credit: string | null;
  image_url: string | null;
  source_url: string | null;
}

export interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number };
}
