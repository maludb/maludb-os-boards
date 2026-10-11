// The island's copy of the board (canvas-core.md client/src/model/store.ts): items by id, the deleted ones kept for a restore, children
// by frame, every op applied PER PROPERTY exactly as bd_commit_ops() applies it (style and data merged by top-level key, a JSON null
// removes a key — bd_jsonb_merge's rule), and the inverse of an op computed from the state before it (a refused batch rolls back with it).
// The database is the referee; this is a mirror that runs ahead of it by one round trip.
import type { Box, Fields, Item, Op } from './types';
import { frameTopLeft, rotatedBox, union } from './geometry';

export interface Change {
  /** the items whose look or place changed */
  ids: Set<string>;
  /** an item came, went, changed frame or z — the draw order must be rebuilt */
  structural: boolean;
  /** everything changed (a load) */
  all?: boolean;
}
type Listener = (c: Change) => void;
/** Skip a field of a remote update (`style.fillColor`, `x` …) — the per-property last-writer rule of the sync layer. */
export type SkipField = (id: string, key: string) => boolean;

const JSON_FIELDS = new Set(['style', 'data']);

function clone<T>(v: T): T {
  return v === undefined ? v : JSON.parse(JSON.stringify(v));
}

export function normalise(raw: Partial<Item> & { id: string; type: Item['type'] }): Item {
  return {
    id: raw.id, type: raw.type, parent_id: raw.parent_id ?? null,
    x: Number(raw.x ?? 0), y: Number(raw.y ?? 0), width: Number(raw.width ?? 0), height: Number(raw.height ?? 0),
    rotation: Number(raw.rotation ?? 0), z: String(raw.z ?? 'a0'),
    style: raw.style && typeof raw.style === 'object' && !Array.isArray(raw.style) ? { ...raw.style } : {},
    data: raw.data && typeof raw.data === 'object' && !Array.isArray(raw.data) ? clone(raw.data) : {},
    start_item_id: raw.start_item_id ?? null, end_item_id: raw.end_item_id ?? null, locked: !!raw.locked,
    version: Number(raw.version ?? 1), created_by: raw.created_by ?? null, updated_by: raw.updated_by ?? null,
  };
}

/** Merge by top-level key; a null in the patch removes the key. */
export function mergeJson(base: Record<string, unknown>, patch: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = { ...base };
  for (const [k, v] of Object.entries(patch)) {
    if (v === null) delete out[k];
    else out[k] = clone(v);
  }
  return out;
}

export class Store {
  readonly items = new Map<string, Item>();
  readonly dead = new Map<string, Item>();
  private boxes = new Map<string, Box>();
  private listeners: Listener[] = [];
  private batching = 0;
  private pendingChange: Change | null = null;

  subscribe(fn: Listener): () => void {
    this.listeners.push(fn);
    return () => { this.listeners = this.listeners.filter((l) => l !== fn); };
  }

  load(items: Item[]): void {
    this.items.clear();
    this.dead.clear();
    this.boxes.clear();
    for (const raw of items) this.items.set(raw.id, normalise(raw));
    this.emit({ ids: new Set(this.items.keys()), structural: true, all: true });
  }

  get(id: string): Item | undefined { return this.items.get(id); }
  has(id: string): boolean { return this.items.has(id); }
  all(): Item[] { return [...this.items.values()]; }
  count(): number { return this.items.size; }

  children(frameId: string): Item[] {
    const out: Item[] = [];
    for (const i of this.items.values()) if (i.parent_id === frameId) out.push(i);
    return out;
  }

  /** The absolute centre: a frame's top-left + the relative centre under a frame. */
  absCentre(item: Item): { x: number; y: number } {
    if (item.parent_id) {
      const f = this.items.get(item.parent_id);
      if (f) {
        const tl = frameTopLeft(f);
        return { x: tl.x + item.x, y: tl.y + item.y };
      }
    }
    return { x: item.x, y: item.y };
  }

  /** The absolute, rotation-inclusive box (db/007's formula; a connector's spans its ends). Cached until the item or its frame changes. */
  box(id: string): Box | null {
    const hit = this.boxes.get(id);
    if (hit) return hit;
    const item = this.items.get(id);
    if (!item) return null;
    let b: Box;
    if (item.type === 'connector') {
      let acc: Box | null = null;
      for (const end of [item.start_item_id, item.end_item_id]) {
        const eb = end ? this.box(end) : null;
        if (eb) acc = union(acc, eb);
      }
      for (const key of ['startPoint', 'endPoint']) {
        const p = item.data[key] as { x?: number; y?: number } | undefined;
        if (p && typeof p === 'object' && p.x !== undefined && p.y !== undefined) acc = union(acc, { x1: p.x, y1: p.y, x2: p.x, y2: p.y });
      }
      b = acc ?? rotatedBox(item.x, item.y, item.width, item.height, 0);
    } else {
      const c = this.absCentre(item);
      b = rotatedBox(c.x, c.y, item.width, item.height, item.rotation);
    }
    this.boxes.set(id, b);
    return b;
  }

  /** Every live item's box joined, or null on an empty board. */
  extent(): Box | null {
    let acc: Box | null = null;
    for (const id of this.items.keys()) {
      const b = this.box(id);
      if (b) acc = union(acc, b);
    }
    return acc;
  }

  highestZ(): string | null {
    let z: string | null = null;
    for (const i of this.items.values()) if (z === null || i.z > z) z = i.z;
    return z;
  }
  lowestZ(): string | null {
    let z: string | null = null;
    for (const i of this.items.values()) if (z === null || i.z < z) z = i.z;
    return z;
  }

  /** The ids a delete op takes, as the database takes them: the named live items, a frame's children (unless keep_children), the connectors of any. */
  deleteSet(op: { id?: string; ids?: string[]; keep_children?: boolean; cascade?: boolean }): { all: string[]; kept: Item[] } {
    const named = (op.ids ?? (op.id ? [op.id] : [])).filter((id) => this.items.has(id));
    const kept: Item[] = [];
    if (op.keep_children) {
      for (const id of named) {
        const f = this.items.get(id)!;
        if (f.type === 'frame') kept.push(...this.children(id));
      }
    }
    let all = [...named];
    if (op.cascade !== false) {
      const keptIds = new Set(kept.map((k) => k.id));
      for (const i of this.items.values()) if (i.parent_id && named.includes(i.parent_id) && !keptIds.has(i.id)) all.push(i.id);
      const set = new Set(all);
      for (const i of this.items.values()) {
        if (i.type === 'connector' && ((i.start_item_id && set.has(i.start_item_id)) || (i.end_item_id && set.has(i.end_item_id)))) all.push(i.id);
      }
    }
    all = [...new Set(all)];
    return { all, kept };
  }

  /** The ops that undo `op` against the CURRENT state (call before applying it). */
  inverseOf(op: Op): Op[] {
    switch (op.op) {
      case 'create':
        return [{ op: 'delete', ids: [op.item.id], cascade: false }];
      case 'update': {
        const item = this.items.get(op.id);
        if (!item) return [];
        const old: Fields = {};
        for (const [k, v] of Object.entries(op.fields)) {
          if (JSON_FIELDS.has(k)) {
            const src = (item as unknown as Record<string, Record<string, unknown>>)[k];
            const patch: Record<string, unknown> = {};
            for (const sk of Object.keys(v as Record<string, unknown>)) patch[sk] = sk in src ? clone(src[sk]) : null;
            (old as Record<string, unknown>)[k] = patch;
          } else {
            (old as Record<string, unknown>)[k] = clone((item as unknown as Record<string, unknown>)[k]);
          }
        }
        return [{ op: 'update', id: op.id, fields: old }];
      }
      case 'delete': {
        const { all, kept } = this.deleteSet(op);
        if (all.length === 0) return [];
        const back: Op[] = [{ op: 'restore', ids: all }];
        for (const k of kept) back.push({ op: 'update', id: k.id, fields: { parent_id: k.parent_id, x: k.x, y: k.y } });
        return back;
      }
      case 'restore': {
        const ids = op.ids.filter((id) => this.dead.has(id) || (op.items ?? []).some((i) => i.id === id));
        return ids.length ? [{ op: 'delete', ids, cascade: false }] : [];
      }
    }
  }

  /** Apply one op; answers the ids it touched and whether the order or membership changed. */
  private applyOne(op: Op, skip?: SkipField): Change {
    const ids = new Set<string>();
    let structural = false;
    switch (op.op) {
      case 'create': {
        const item = normalise(op.item);
        this.items.set(item.id, item);
        this.dead.delete(item.id);
        ids.add(item.id);
        structural = true;
        break;
      }
      case 'update': {
        const item = this.items.get(op.id);
        if (!item) break;
        const rec = item as unknown as Record<string, unknown>;
        for (const [k, v] of Object.entries(op.fields)) {
          if (JSON_FIELDS.has(k)) {
            const patch: Record<string, unknown> = {};
            for (const [sk, sv] of Object.entries(v as Record<string, unknown>)) if (!skip || !skip(op.id, `${k}.${sk}`)) patch[sk] = sv;
            rec[k] = mergeJson(rec[k] as Record<string, unknown>, patch);
          } else if (!skip || !skip(op.id, k)) {
            rec[k] = k === 'x' || k === 'y' || k === 'width' || k === 'height' || k === 'rotation' ? Number(v) : clone(v);
            if (k === 'z' || k === 'parent_id') structural = true;
          }
        }
        if (op.version !== undefined && op.version > item.version) item.version = op.version;
        ids.add(op.id);
        if (item.type === 'frame') for (const c of this.children(op.id)) ids.add(c.id);
        break;
      }
      case 'delete': {
        const { all, kept } = this.deleteSet(op);
        for (const k of kept) {
          const f = this.items.get(k.parent_id!)!;
          const tl = frameTopLeft(f);
          k.x = tl.x + k.x;
          k.y = tl.y + k.y;
          k.parent_id = null;
          ids.add(k.id);
        }
        for (const id of all) {
          const it = this.items.get(id);
          if (!it) continue;
          this.items.delete(id);
          this.dead.set(id, it);
          ids.add(id);
        }
        structural = all.length > 0 || kept.length > 0;
        break;
      }
      case 'restore': {
        const fresh = new Map((op.items ?? []).map((i) => [i.id, normalise(i)]));
        // a restored item's deleted frame comes back with it (the database's rule)
        const want = new Set(op.ids);
        for (const id of op.ids) {
          const it = fresh.get(id) ?? this.dead.get(id);
          if (it?.parent_id && this.dead.has(it.parent_id)) want.add(it.parent_id);
        }
        for (const id of want) {
          const it = fresh.get(id) ?? this.dead.get(id);
          if (!it) continue;
          this.dead.delete(id);
          this.items.set(id, it);
          ids.add(id);
        }
        structural = ids.size > 0;
        break;
      }
    }
    return { ids, structural };
  }

  /** Apply ops in order and tell the listeners once. */
  apply(ops: Op[], skip?: SkipField): Change {
    const total: Change = { ids: new Set(), structural: false };
    for (const op of ops) {
      const c = this.applyOne(op, skip);
      c.ids.forEach((i) => total.ids.add(i));
      total.structural ||= c.structural;
    }
    this.invalidate(total.ids);
    this.emit(total);
    return total;
  }

  /** Each op's inverse computed just before it is applied (so a batch's later ops see its earlier ones): answers the batch's inverse, last op first. */
  applyWithInverse(ops: Op[]): Op[] {
    let inverse: Op[] = [];
    const total: Change = { ids: new Set(), structural: false };
    for (const op of ops) {
      inverse = [...this.inverseOf(op), ...inverse];
      const c = this.applyOne(op);
      c.ids.forEach((i) => total.ids.add(i));
      total.structural ||= c.structural;
    }
    this.invalidate(total.ids);
    this.emit(total);
    return inverse;
  }

  setVersions(versions: Record<string, number> | undefined): void {
    if (!versions) return;
    for (const [id, v] of Object.entries(versions)) {
      const it = this.items.get(id) ?? this.dead.get(id);
      if (it && v > it.version) it.version = v;
    }
  }

  snapshot(): Item[] {
    return [...this.items.values()].map((i) => clone(i));
  }

  /** Forget cached boxes of these items, their frames' children and every connector touching them. */
  private invalidate(ids: Set<string>): void {
    if (ids.size > 200) { this.boxes.clear(); return; }
    for (const id of ids) {
      this.boxes.delete(id);
      const it = this.items.get(id);
      if (it?.type === 'frame') for (const c of this.children(id)) this.boxes.delete(c.id);
    }
    for (const i of this.items.values()) {
      if (i.type === 'connector' && ((i.start_item_id && ids.has(i.start_item_id)) || (i.end_item_id && ids.has(i.end_item_id)))) this.boxes.delete(i.id);
    }
  }

  /** Group several applies into one notification. */
  batch(fn: () => void): void {
    this.batching++;
    try { fn(); } finally {
      this.batching--;
      if (this.batching === 0 && this.pendingChange) { const c = this.pendingChange; this.pendingChange = null; this.emit(c); }
    }
  }

  private emit(c: Change): void {
    if (this.batching > 0) {
      if (!this.pendingChange) this.pendingChange = { ids: new Set(), structural: false };
      c.ids.forEach((i) => this.pendingChange!.ids.add(i));
      this.pendingChange.structural ||= c.structural;
      this.pendingChange.all ||= c.all;
      return;
    }
    for (const l of this.listeners) l(c);
  }
}
