// Culling (canvas-core.md "Rendering and performance"): on every camera change (one per animation frame) and store change, only what meets
// the viewport plus 25 % is drawn. The renderer keeps every item in z order (rebuilt only when an item comes, goes or changes z or frame);
// a frame filters that list in order against the store's cached boxes — 5,000 box tests, no sort, well under a millisecond.
import type { Box, Item } from '../model/types';
import type { Store } from '../model/store';
import { intersects } from '../model/geometry';

/** The viewport grown by a quarter of its size on every side. */
export function cullBox(viewport: Box): Box {
  const mx = (viewport.x2 - viewport.x1) * 0.25;
  const my = (viewport.y2 - viewport.y1) * 0.25;
  return { x1: viewport.x1 - mx, y1: viewport.y1 - my, x2: viewport.x2 + mx, y2: viewport.y2 + my };
}

/** Frames and the rest, each in z order (byte order — the column's collation; JS compares these keys the same way). */
export function sortedByZ(store: Store): { frames: Item[]; items: Item[] } {
  const frames: Item[] = [];
  const items: Item[] = [];
  for (const it of store.items.values()) (it.type === 'frame' ? frames : items).push(it);
  const byZ = (a: Item, b: Item) => (a.z < b.z ? -1 : a.z > b.z ? 1 : a.id < b.id ? -1 : a.id > b.id ? 1 : 0);
  frames.sort(byZ);
  items.sort(byZ);
  return { frames, items };
}

/** The part of an ordered list that meets the area (and whatever must stay drawn), in the same order. */
export function inView(list: Item[], store: Store, area: Box, keep: Set<string>): Item[] {
  const out: Item[] = [];
  for (const it of list) {
    const b = store.box(it.id);
    if (b && (keep.has(it.id) || intersects(b, area))) out.push(it);
  }
  return out;
}
