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
  | 'ARTWORK_APPROVED'
  | 'PROOF_APPROVED'
  | 'INVOICED'
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

/**
 * A quote's delivery address as returned by GET /quotes/:id/shipping-address
 * (the saved address, or a company-defaulted one when none is stored yet).
 */
export interface ShippingAddress {
  recipient_name: string;
  phone: string;
  email: string | null;
  line1: string;
  line2: string | null;
  city: string | null;
  state: string | null;
  postal_code: string;
  country: string | null;
  notes: string | null;
}

/**
 * The writable subset sent to PUT /quotes/:id/shipping-address.
 * recipient_name, phone, line1, postal_code are required; the rest optional.
 */
export interface ShippingAddressInput {
  recipient_name: string;
  phone: string;
  line1: string;
  postal_code: string;
  email?: string | null;
  line2?: string | null;
  city?: string | null;
  state?: string | null;
  country?: string | null;
  notes?: string | null;
}

/**
 * Structured ship-to captured at checkout / stored on a quote. No id.
 * Distinct from ShippingAddressInput (the PUT /quotes/:id/shipping-address
 * payload, where country is optional): saved addresses always require a
 * country (StoreSavedAddressRequest defaults it to 'SG' when blank, and the
 * saved_addresses.country column is NOT NULL).
 */
export interface SavedAddressInput {
  recipient_name: string;
  phone: string;
  email?: string | null;
  line1: string;
  line2?: string | null;
  city?: string | null;
  state?: string | null;
  postal_code: string;
  country: string;
  notes?: string | null;
}

/** A buyer's saved address book entry (max 3 per user). */
export interface SavedAddress extends SavedAddressInput {
  id: number;
  label: string | null;
}

/**
 * Order-level delivery-window estimate from POST /lead-time-estimate: the
 * earliest/latest arrival for a set of products (gated by the slowest track and
 * current queue depth), plus an optional rush window/fee. Informational only —
 * no charge is applied from it.
 */
export interface LeadTimeEstimate {
  earliest: string;
  latest: string;
  rush_available: boolean;
  rush_earliest: string | null;
  rush_fee: number | null;
}

/** Buyer dashboard counts from GET /quotes/summary (scoped to the company). */
export interface QuoteSummary {
  active: number;
  awaiting: number;
  in_production: number;
  completed: number;
  total: number;
  /** Orders waiting on a buyer decision (accept / approve proof / pay). */
  awaiting_orders: { id: number; reference: string; state: QuoteState }[];
}

/** Result of POST /production-jobs/:id/create-shipment (NinjaVan). */
export interface ShipmentResult {
  state: string;
  carrier: string | null;
  consignment_ref: string | null;
  tracking_url: string | null;
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
  /**
   * Effective granular access ("section.action"). Superadmin resolves to every
   * key, a grandfathered staff_admin likewise; a restricted staff_admin to their
   * allowlist; a buyer to none. Drives what the console shows. Optional so an
   * older payload without it degrades gracefully (treated as no restriction for
   * staff by the isStaffRole fallback path).
   */
  permissions?: string[];
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
  /** Display identity. quote_id remains the key realtime updates match on. */
  quote_reference?: string | null;
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
  /**
   * Advisory finding from procurement — a shortfall that no longer blocks the
   * order, since it is measured against stock figures nobody maintains. Staff
   * check it at the production gate.
   */
  procurement_note?: string | null;
  lead_time_days: number | null;
  product?: Product;
}

export interface Proof {
  id: number;
  quote_id: number;
  /** Display identity. quote_id remains the key realtime updates match on. */
  quote_reference?: string | null;
  version: number;
  artwork_version_ref: string;
  /**
   * Server-resolved viewing link: a short-lived signed URL for an uploaded
   * file, the value itself when staff pasted a real URL, null when it is
   * neither (legacy rows hold arbitrary strings).
   */
  artwork_url?: string | null;
  state: ProofState;
  approved_by: number | null;
  approved_at: string | null;
  notes: string | null;
  /**
   * Buyer's "request changes" reference images, each with a resolved viewing
   * link (url null on a non-presigning local disk). Empty when none attached.
   */
  change_attachments?: { ref: string; url: string | null }[];
}

export interface Quote {
  id: number;
  company_id: number;
  /** Opaque order reference used in buyer/public URLs (/orders/{reference}). */
  reference: string;
  /** Opaque code for login-free tracking (share with the recipient). */
  tracking_code?: string | null;
  /** Relative signed path for login-free tracking (e.g. /track/view?code=...&signature=...). */
  tracking_link?: string | null;
  /** Only present on staff listings (relation-loaded server-side). */
  company_name?: string;
  state: QuoteState;
  currency: string;
  subtotal: string;
  delivery: string;
  /**
   * Free-form staff adjustments applied after delivery (discount/tax/fee).
   * Signed: negative pulls the total down, positive pushes it up. Buyer-visible
   * - always an array, possibly empty.
   */
  adjustments?: Adjustment[];
  total: string;
  price_snapshot_at: string | null;
  /** The production gate: null until a person confirms the goods are in hand. */
  stock_confirmed_at?: string | null;
  stock_confirmed_by?: number | null;
  /**
   * Whether buyer self-service payment is actually available. The Pay now
   * button used to render regardless and always failed on a B2B tenant.
   */
  pay_now_enabled?: boolean;
  notes: string | null;
  /** Buyer's requested delivery deadline (Y-m-d); null when unset. */
  needed_by: string | null;
  line_items?: LineItem[];
  proofs?: Proof[];
  created_at: string | null;
  /**
   * Staff-only edit trail for DRAFT amendments. Absent from buyer payloads
   * entirely (it carries internal prices/margins); present - possibly empty -
   * only for staff. Entries from one save share a `batch`.
   */
  amendment_log?: AmendmentLogEntry[];
}

/** A free-form money adjustment after delivery. Signed amount (see Quote). */
export interface Adjustment {
  label: string;
  /** Number over the wire; a string while being typed in the editor. */
  amount: number | string;
}

/** One recorded change from a staff DRAFT amendment. See QuoteService::amend. */
export interface AmendmentLogEntry {
  /** Shared across every entry produced by a single save, for grouping. */
  batch?: string;
  /** What changed. `edited`/`added`/`removed` are line changes. */
  action?: 'edited' | 'added' | 'removed' | 'delivery' | 'notes' | 'adjustments';
  /** Editor's user id, and their name snapshotted at edit time. */
  by?: number | null;
  by_name?: string | null;
  /** Staff's mandatory reason for the save, shared across the batch. */
  remark?: string | null;
  /** ISO-8601 instant of the save. */
  at?: string | null;
  /** Line changes only: the affected line and its product name at the time. */
  line_item_id?: number;
  product_name?: string | null;
  /** Prior/new values. Shape depends on `action` (line price+qty, delivery, notes). */
  from?: Record<string, unknown> | null;
  to?: Record<string, unknown> | null;
}

export interface ProductionJob {
  id: number;
  quote_id: number;
  /** Display identity. quote_id remains the key realtime updates match on. */
  quote_reference?: string | null;
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
    /** Print-floor production file (H2S `.3mf`); null → floor prints the STL. */
    production_file_ref?: string | null;
    /** Canonical STL path - the production-file fallback when the above is null. */
    model_file_ref?: string | null;
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
  // False when a line is missing trustworthy weight/dimensions, so the derived
  // delivery fee would understate the real cost. The storefront then hides the
  // number and defers to the staff-confirmed quote.
  delivery_reliable: boolean;
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
  /** MODEL_3D filament grams/unit - an input to the dynamic 3D base cost. */
  est_grams?: string | number | null;
  /** MODEL_3D print minutes/unit - an input to the dynamic 3D base cost. */
  est_print_minutes?: string | number | null;
  /** Storage path of the canonical mesh file for MODEL_3D items; null when unset. */
  model_file_ref?: string | null;
  /**
   * Storage path of the print-floor production file (e.g. an H2S-ready `.3mf`);
   * null → the floor falls back to `model_file_ref` (the STL).
   */
  production_file_ref?: string | null;
  /** IP-screen hit - a surfaced, non-blocking risk tag (item can still publish). */
  ip_flagged?: boolean;
  /** Why the IP screen flagged it (matched franchise/keyword); null when unset. */
  ip_flag_reason?: string | null;
  /** Original source listing URL (MakerWorld/Thingiverse); null for CORE/manual. */
  source_url?: string | null;
  /** Source's own id for the listing; null for CORE/manual products. */
  source_product_id?: string | null;
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
  source_links: import('./lib/sourceLinks').SourceLink[];
  created_at: string | null;
}

export interface AdminCatalogueItem {
  id: number;
  name: string;
  class: ProductClass;
  publish_state: PublishState;
  cannot_publish_reasons: string[] | null;
  /** Prefill for the blocker-resolution popup. decimal casts arrive as strings. */
  weight: string | null;
  dimensions: { l?: number; w?: number; h?: number; unit?: string } | null;
  print_method: 'UV' | 'FDM' | 'RESIN' | null;
  is_printable: boolean;
  base_cost: string;
  currency: string;
  creator_credit: string | null;
  image_url: string | null;
  source_url: string | null;
  source_kind: import('./lib/sourceKind').SourceKind | null;
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
  /** Effective granted access; the access table checks these boxes. */
  permissions?: string[];
  /** Only a staff_admin can be restricted, so only they get an editable table. */
  permissions_editable?: boolean;
}

/** One section of grantable permissions, from /admin/permissions/catalog. */
export interface PermissionSection {
  label: string;
  /** action key -> human description, e.g. { view: 'View orders', edit: '...' }. */
  actions: Record<string, string>;
}

/** section key -> section, e.g. { quotes: {...}, production: {...} }. */
export type PermissionCatalog = Record<string, PermissionSection>;
