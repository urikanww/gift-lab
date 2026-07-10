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

/**
 * Model-space print zone: where + how big the decoration surface is (mm).
 * Structurally identical to the interface in `lib/printZone.ts`, re-declared
 * here so pages/components can type props without importing three.
 */
export interface PrintZone {
  normal: [number, number, number];
  center: [number, number, number];
  up: [number, number, number];
  width_mm: number;
  height_mm: number;
}

export type JobTrack = 'UV' | '3D';
export type JobState = 'READY' | 'IN_PRODUCTION' | 'SHIPPED' | 'CLOSED';
export type ProofState = 'SENT' | 'CHANGES_REQUESTED' | 'APPROVED';

export type Carrier = 'SINGPOST' | 'NINJAVAN' | 'JNT' | 'QXPRESS' | 'DHL' | 'FEDEX' | 'OTHER';

export interface Shipment {
  carrier_label: string | null;
  tracking_url: string | null;
  ref: string;
}

/** One step in the order tracking timeline (code + human label). */
export interface TrackStage {
  code: string;
  label: string;
}

/** Login-free order tracking payload, mirroring the backend OrderTracker::payload() shape. */
export interface TrackResult {
  reference: string;
  stage: string;
  stage_label: string;
  cancelled: boolean;
  stages: TrackStage[];
  placed_at: string | null;
  updated_at: string | null;
  needed_by: string | null;
  items_total: number;
  items_completed: number;
  shipments: Shipment[];
}

/** Minimal company summary embedded on the authed user (delivery destination). */
export interface CompanySummary {
  id: number;
  name: string;
  address: string | null;
}

export interface User {
  id: number;
  company_id: number | null;
  name: string;
  email: string;
  role: UserRole;
  /** The buyer's company - reused as the read-only shipping address at checkout. */
  company?: CompanySummary | null;
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
  /** Canonical public URL key - link by slug, never by numeric id. */
  slug?: string | null;
  description: string | null;
  class: ProductClass;
  /** Public marketplace category slug (drinkware, bags, …); null pre-backfill. */
  category?: string | null;
  from_price: number;
  currency: string;
  dimensions: Record<string, number | string> | null;
  weight: string | null;
  print_method: PrintMethod | null;
  stock_mode: string;
  /** Customer-facing availability, honest about on-demand items. */
  availability: 'in_stock' | 'made_to_order' | 'out_of_stock';
  image_url: string | null;
  is_printable: boolean;
  creator_credit: string | null;
  /** True when an interactive 3D model stream is available for this item. */
  has_model?: boolean;
  /** Staff gate for the public 3D viewer: only verified models preview on the PDP. */
  model_preview_verified?: boolean;
  /** Admin-authored decoration zone (model-space mm); null when unset. */
  print_zone?: PrintZone | null;
  /** Minimum order quantity (superadmin-set); default 1. */
  min_order_qty?: number;
  /** True when an authored GLB is stored (preferred preview mesh). */
  has_glb?: boolean;
  variants?: Variant[];
}

export interface Customization {
  logo_size?: string | null;
  artwork_ref?: string | null;
  /**
   * UV-flattened production print file (MODEL_3D zoned items): the decal
   * unwrapped to its print space. Additive to artwork_ref (the buyer proof).
   */
  print_file_ref?: string | null;
  /** MODEL_3D filament colour chosen in the designer (Black/White/Grey). */
  filament_color?: string | null;
  /** Name/text personalisation content rendered into the artwork (audit D9). */
  text?: string | null;
  /**
   * Machine-readable placement record captured with the artwork (position,
   * size, rotation as canvas fractions + export pixel mapping) so production
   * can read the layout without opening the PNG.
   */
  layout?: object | null;
  /** Customization mode: in-app designer output, or buyer-uploaded intent. */
  mode?: 'designer' | 'buyer_uploaded';
  /** Fallback: reference images of the desired finished look (storage refs). */
  reference_refs?: string[];
  /** Fallback: free-text placement notes for production. */
  placement_notes?: string | null;
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
  /** Opaque code for login-free tracking (share with the recipient). */
  tracking_code?: string | null;
  /** Only present on staff listings (relation-loaded server-side). */
  company_name?: string;
  state: QuoteState;
  currency: string;
  subtotal: string;
  delivery: string;
  total: string;
  price_snapshot_at: string | null;
  notes: string | null;
  /** Buyer's requested delivery deadline (Y-m-d); null when unset. */
  needed_by: string | null;
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
  consignment_ref?: string | null;
  carrier?: Carrier | null;
  print_method: PrintMethod | null;
  qty: number;
  /**
   * Line items this job produces, with their saved customization + the product's
   * model/zone - lets the floor view and visualize the decorated final product.
   * Present on the queue fetch; carried across realtime updates.
   */
  line_items?: JobLineItem[];
}

/** One line of a production job, as surfaced to the production floor. */
export interface JobLineItem {
  id: number;
  qty: number;
  product: {
    id: number;
    name: string;
    slug: string | null;
    class: ProductClass;
    /** True when a printable 3D mesh is stored (enables the decorated preview). */
    has_model: boolean;
    print_zone: PrintZone | null;
    /** Every printable part (head/body/limbs) the floor can download; empty for single-mesh. */
    model_parts: ModelPart[];
  } | null;
  customization: Customization | null;
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

export type LicenseTier = 'standard' | 'extended' | 'high_risk';

/** A variant as serialized on the admin product resource. */
export interface AdminVariant {
  id: number;
  attributes: Record<string, string>;
  sku: string | null;
  stock_on_hand: number;
  reorder_threshold: number;
  price_delta: string | number;
}

/**
 * Full product shape as returned by the /admin/products endpoints - a superset
 * of the public Product with the internal cost/publish/licence fields the staff
 * console edits. Kept distinct from the public `Product` (which never exposes
 * base_cost, publish_state, or licence tier).
 */
export interface AdminProduct {
  id: number;
  name: string;
  slug?: string | null;
  description: string | null;
  class: ProductClass;
  base_cost: string | number;
  /**
   * Superadmin fixed per-unit price that supersedes dynamic pricing; null =
   * dynamic. Covers the product price only (delivery is still weight-based).
   */
  price_override: string | number | null;
  /** Computed sell price (qty 1, no variant) - what a customer pays. */
  selling_price: string | number;
  currency: string;
  dimensions: { l?: number; w?: number; h?: number; unit?: string } | null;
  weight: string | number | null;
  print_method: PrintMethod | null;
  stock_mode: string | null;
  allow_backorder: boolean;
  category: string | null;
  image_url: string | null;
  is_printable: boolean;
  publish_state: string;
  license_tier: LicenseTier;
  archived: boolean;
  variants: AdminVariant[] | null;
  sold_count: number;
  stock_total: number;
  /** True when a canonical 3D mesh is stored for this item (MODEL_3D). */
  has_model?: boolean;
  /** Staff gate for the public 3D viewer on the PDP. */
  model_preview_verified?: boolean;
  /** True when an authored GLB is stored (preferred for preview). */
  has_glb?: boolean;
  /** Persisted admin print zone for MODEL_3D items; null when unset. */
  print_zone?: PrintZone | null;
  /** Minimum order quantity (superadmin-set); default 1. */
  min_order_qty?: number;
  /** Storage path of the canonical mesh file for MODEL_3D items; null when unset. */
  model_file_ref?: string | null;
  /**
   * Individual printable parts of a multi-part figure (e.g. Groot: head, body,
   * arms, legs). Empty for single-mesh products - the primary model covers them.
   */
  model_parts?: ModelPart[];
}

/** One printable part of a multi-part MODEL_3D product. */
export interface ModelPart {
  id: number;
  /** Human label (from the source filename, e.g. "Head"). */
  label: string | null;
  /** Mesh complexity - shown so staff can spot an empty/placeholder part. */
  triangle_count: number | null;
  /** The largest part; mirrors products.model_file_ref. */
  is_primary: boolean;
  sort: number;
}

export interface AdminReorder {
  id: number;
  state: 'DRAFT' | 'APPROVED' | 'ORDERED' | 'RECEIVED';
  qty: number;
  sku: string | null;
  kind: 'variant' | 'filament';
  item: string;
  variant_id: number | null;
  product_id: number | null;
  stock_on_hand: number | null;
  source_url: string | null;
  created_at: string | null;
}

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
  filament_material: string | null;
  filament_color: string | null;
  est_grams: string | null;
  estimates_verified: boolean;
  model_file_ref: string | null;
}

export interface Paginated<T> {
  data: T[];
  meta?: { current_page: number; last_page: number; total: number };
}

/** A single audit-trail entry as returned by GET /admin/products/:id/history. */
export interface HistoryEntry {
  id: number;
  event: string;
  entity: string;
  user: string | null;
  old_values: Record<string, unknown> | null;
  new_values: Record<string, unknown> | null;
  created_at: string;
}

/** A company reference as returned by GET /admin/companies. */
export interface AdminCompany {
  id: number;
  name: string;
}

/** A user as returned by the superadmin-only /admin/users endpoints. */
export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  company: { id: number; name: string } | null;
  active: boolean;
  created_at: string;
}
