// The wire's shapes (canvas-core.md "The op protocol"): an item is bd_item_json (db/007) — Miro's REST item as a row: x, y the CENTRE,
// frame-relative under a frame; ops are exactly bd_commit_ops()'s.

export type Level = 'view' | 'comment' | 'edit' | 'full';
export const LEVEL_RANK: Record<string, number> = { view: 1, comment: 2, edit: 3, full: 4 };

export type ItemType = 'sticky_note' | 'shape' | 'text' | 'connector' | 'frame' | 'drawing' | 'image' | 'document';

export interface RichRun {
  type: string;
  text?: { content: string; link: string | null };
  plain_text?: string;
  annotations?: Record<string, unknown>;
  href?: string | null;
  [k: string]: unknown;
}

export type Style = Record<string, unknown>;
export type Data = Record<string, unknown>;

export interface Item {
  id: string;
  type: ItemType;
  parent_id: string | null;
  x: number;
  y: number;
  width: number;
  height: number;
  rotation: number;
  z: string;
  style: Style;
  data: Data;
  start_item_id: string | null;
  end_item_id: string | null;
  locked: boolean;
  version: number;
  created_by?: number | null;
  updated_by?: number | null;
}

export interface Fields {
  x?: number;
  y?: number;
  width?: number;
  height?: number;
  rotation?: number;
  z?: string;
  parent_id?: string | null;
  style?: Record<string, unknown>;
  data?: Record<string, unknown>;
  start_item_id?: string | null;
  end_item_id?: string | null;
  locked?: boolean;
}

export type CreateOp = { op: 'create'; item: Item };
export type UpdateOp = { op: 'update'; id: string; fields: Fields; base_version?: number; version?: number };
export type DeleteOp = { op: 'delete'; id?: string; ids?: string[]; keep_children?: boolean; cascade?: boolean };
export type RestoreOp = { op: 'restore'; ids: string[]; items?: Item[] };
export type Op = CreateOp | UpdateOp | DeleteOp | RestoreOp;

export interface CommitAnswer {
  ok?: boolean;
  seq: number;
  duplicate: boolean;
  noop?: boolean;
  versions?: Record<string, number>;
  replaced?: { id: string; by: number | null }[];
  item_count?: number;
  warnings?: string[];
  inverse?: Op[];
}

export interface RemoteBatch {
  seq: number;
  member_id: number | null;
  client_id: string | null;
  source: string;
  ops: Op[];
  summary: { bbox?: number[] | null; counts?: Record<string, number>; types?: Record<string, number> };
  at?: string;
}

export interface Box { x1: number; y1: number; x2: number; y2: number }
/** The view: the canvas point at the viewport's centre, and the zoom (the board's start_view has the same meaning). */
export interface Camera { x: number; y: number; zoom: number }
export interface Person { name: string; kind: string }

export interface BoardState {
  board: { id: string; title: string; level: Level; is_locked: boolean; background: { pattern?: string; color?: string }; start_view: Camera | null; item_count: number };
  items: Item[];
  last_seq: number;
  people: Record<string, Person>;
  server_time: string;
}

/** Runs of plain text (bd_rich_text's shape) ↔ a string. */
export function runsOf(text: string): RichRun[] {
  if (text === '') return [];
  return [{ type: 'text', text: { content: text, link: null },
    annotations: { bold: false, italic: false, strikethrough: false, underline: false, code: false, color: 'default' }, plain_text: text, href: null }];
}
export function plainOf(item: Item): string {
  const c = item.data?.content;
  if (!Array.isArray(c)) return '';
  return (c as RichRun[]).map((r) => r.plain_text ?? r.text?.content ?? '').join('');
}

export function uuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
  const b = new Uint8Array(16);
  crypto.getRandomValues(b);
  b[6] = (b[6] & 0x0f) | 0x40;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** Miro's sticky colours (the REST API's fillColor names) and the hex they are painted in — the PHP twin is STICKY_COLORS. */
export const STICKY_COLORS: Record<string, string> = {
  gray: '#f5f6f8', light_yellow: '#fff9b1', yellow: '#f5d128', orange: '#ff9d48', light_green: '#d5f692', green: '#c9df56', dark_green: '#93d275',
  cyan: '#67c6c0', light_pink: '#ffcee0', pink: '#ea94bb', violet: '#c6a2d2', red: '#f0939d', light_blue: '#a6ccf5', blue: '#6cd8fa',
  dark_blue: '#9ea9ff', black: '#000000',
};
export const ITEM_SIZES: Record<string, [number, number]> = { sticky_note: [200, 200], shape: [200, 120], text: [200, 60] };
export const LAYOUT_GAP = 24;
export const DUPLICATE_OFFSET = 24;
