import api, { ensureCsrf } from './api';

export interface Candidate {
  source_product_id: string;
  name: string;
  price: number | null;
  currency: string;
  image_url: string | null;
  product_link: string;
  offer_link: string;
  sales: number;
  rating_star: number | null;
  shop_name: string | null;
  ip_flag: string | null;
  material_flag: string | null;
}

export async function searchCandidates(keyword: string, limit = 20): Promise<Candidate[]> {
  const { data } = await api.get<{ data: Candidate[] }>('/admin/blank-recommendations', {
    params: { keyword, limit },
  });
  return data.data;
}

export async function addBlank(c: Candidate): Promise<void> {
  await ensureCsrf();
  await api.post('/admin/blank-recommendations/add', {
    source_product_id: c.source_product_id,
    name: c.name,
    price: c.price,
    image_url: c.image_url,
    product_link: c.product_link,
  });
}

export async function featureCandidate(c: Candidate): Promise<void> {
  await ensureCsrf();
  await api.post('/admin/blank-recommendations/feature', {
    source_product_id: c.source_product_id,
    name: c.name,
    price: c.price,
    image_url: c.image_url,
    offer_link: c.offer_link,
    product_link: c.product_link,
    shop_name: c.shop_name,
    ip_flagged: c.ip_flag != null,
  });
}
