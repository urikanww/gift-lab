export type SourceKind = 'marketplace' | 'local' | 'makerworld' | 'thingiverse' | 'cults3d' | 'manual';

export const SOURCE_KIND_LABELS: Record<SourceKind, string> = {
  marketplace: 'Marketplace',
  local: 'Local supplier',
  makerworld: 'MakerWorld',
  thingiverse: 'Thingiverse',
  cults3d: 'Cults3D',
  manual: 'Manual',
};
