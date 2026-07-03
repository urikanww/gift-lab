import api from './api';

export interface BrandKit {
  colors: string[];
  logo: string | null;
  has_logo: boolean;
}

export async function fetchBrandKit(): Promise<BrandKit> {
  const { data } = await api.get<BrandKit>('/company/brand-kit');
  return data;
}

export async function saveBrandKit(payload: { colors: string[]; logo?: string | null }): Promise<BrandKit> {
  const { data } = await api.put<BrandKit>('/company/brand-kit', payload);
  return data;
}
