// Sticky (N): a click (a tap) places a 200 × 200 sticky in Miro's light yellow, centred there — in the frame under it, if any — as ONE
// create batch; the editor opens on it and the tool goes back to Select (proof 3: "pick Sticky, click → a sticky; type; click away").
// A finger that drags pans instead. placeItem() is every creating tool's.
import type { Board } from '../board';
import type { Data, Item, ItemType, Style } from '../model/types';
import { ITEM_SIZES, uuid } from '../model/types';
import { keyAfter } from '../model/fractional';
import { frameTopLeft, round2 } from '../model/geometry';
import type { Gesture, PointerInfo, Tool } from './input';
import { panGesture } from './select';

/** Make one item centred at a canvas point (in the frame under it), select it, and open its text when asked. */
export function placeItem(b: Board, type: ItemType, cx: number, cy: number, opts: { width?: number; height?: number; style?: Style; data?: Data; edit?: boolean } = {}): string {
  const [dw, dh] = ITEM_SIZES[type] ?? [200, 200];
  const w = opts.width ?? dw;
  const h = opts.height ?? dh;
  const frame = type === 'frame' ? null : b.frameAt(cx, cy);
  const tl = frame ? frameTopLeft(frame) : { x: 0, y: 0 };
  const item: Item = {
    id: uuid(), type, parent_id: frame ? frame.id : null, x: round2(cx - tl.x), y: round2(cy - tl.y), width: w, height: h, rotation: 0,
    z: keyAfter(b.store.highestZ()), style: opts.style ?? {}, data: { content: [], ...(opts.data ?? {}) },
    start_item_id: null, end_item_id: null, locked: false, version: 1,
  };
  b.commit([{ op: 'create', item }]);
  b.selection.set([item.id]);
  b.setTool('select');
  if (opts.edit !== false) b.editText(item.id, true);
  return item.id;
}

/** A press that places on release unless a finger dragged (then it panned). */
export function tapToPlace(b: Board, p: PointerInfo, place: (x: number, y: number) => void): Gesture {
  let moved = false;
  const pan = p.touch ? panGesture(b, p) : null;
  return {
    move: (q) => { if (Math.hypot(q.sx - p.sx, q.sy - p.sy) > 8) moved = true; if (moved) pan?.move(q); },
    up: () => { if (!moved || !p.touch) place(p.x, p.y); },
    cancel: () => undefined,
  };
}

export class StickyTool implements Tool {
  readonly name = 'sticky';
  readonly cursor = 'crosshair';
  constructor(private b: Board) {}
  down(p: PointerInfo): Gesture {
    return tapToPlace(this.b, p, (x, y) => placeItem(this.b, 'sticky_note', x, y, { style: { fillColor: 'light_yellow' } }));
  }
}
