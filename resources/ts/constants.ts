import type { ColorOption, DateFilter, RssUrlExample } from './types';

// 利用可能なテーマカラー（白や明るすぎる色は除外して視認性を確保）
export const AVAILABLE_COLORS: ColorOption[] = [
  { id: 'bg-red-500', name: '赤', hex: '#EF4444' },
  { id: 'bg-orange-500', name: 'オレンジ', hex: '#F97316' },
  { id: 'bg-amber-600', name: '琥珀', hex: '#D97706' },
  { id: 'bg-green-500', name: '緑', hex: '#22C55E' },
  { id: 'bg-emerald-500', name: 'エメラルド', hex: '#10B981' },
  { id: 'bg-teal-500', name: 'ティール', hex: '#14B8A6' },
  { id: 'bg-cyan-600', name: 'シアン', hex: '#0891B2' },
  { id: 'bg-sky-500', name: 'スカイ', hex: '#0EA5E9' },
  { id: 'bg-blue-500', name: '青', hex: '#3B82F6' },
  { id: 'bg-indigo-500', name: 'インディゴ', hex: '#6366F1' },
  { id: 'bg-violet-500', name: 'バイオレット', hex: '#8B5CF6' },
  { id: 'bg-purple-500', name: '紫', hex: '#A855F7' },
  { id: 'bg-fuchsia-500', name: 'フューシャ', hex: '#D946EF' },
  { id: 'bg-pink-500', name: 'ピンク', hex: '#EC4899' },
  { id: 'bg-rose-500', name: 'ローズ', hex: '#F43F5E' },
];

// デフォルトカラー（色が未設定の場合のフォールバック）
export const DEFAULT_JOURNAL_COLOR = 'bg-gray-500';

export const DATE_FILTERS: DateFilter[] = [
  { value: 'week', label: '過去7日', days: 7 },
  { value: 'month', label: '過去30日', days: 30 },
  { value: 'quarter', label: '過去90日', days: 90 },
  { value: 'all', label: 'すべて', days: null },
];

export const RSS_URL_EXAMPLES: RssUrlExample[] = [
  {
    publisher: 'Springer',
    format: 'https://link.springer.com/search.rss?facet-content-type=Article&facet-journal-id=[JOURNAL_ID]',
    example: 'https://link.springer.com/search.rss?facet-content-type=Article&facet-journal-id=40593',
  },
  {
    publisher: 'Wiley',
    format: 'https://onlinelibrary.wiley.com/action/showFeed?jc=[ISSN]&type=etoc&feed=rss',
    example: 'https://onlinelibrary.wiley.com/action/showFeed?jc=15516709&type=etoc&feed=rss',
  },
  {
    publisher: 'Elsevier (ScienceDirect)',
    format: 'https://rss.sciencedirect.com/publication/science/[ISSN]',
    example: 'https://rss.sciencedirect.com/publication/science/03601315',
  },
  {
    publisher: 'SAGE',
    format: 'https://journals.sagepub.com/action/showFeed?ui=0&mi=ehikzz&ai=2b4&jc=[JOURNAL_CODE]&type=etoc&feed=rss',
    example: 'https://journals.sagepub.com/action/showFeed?ui=0&mi=ehikzz&ai=2b4&jc=jeca&type=etoc&feed=rss',
  },
];
