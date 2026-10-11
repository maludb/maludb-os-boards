// Frame (F) (slice 4; connectors-frames.md): drag a rectangle — or click for an 800 × 600 frame centred there — and the frame is made with
// every item whose centre falls inside it taken in, ONE batch (the create, then each item's `update {parent_id, x, y}`, frame-relative);
// its title opens for renaming. A locked item, a connector or another frame is never taken in (the database would refuse the first two; frames
// do not nest).
import Konva from 'konva';
import type { Board } from '../board';
import type { Item, Op } from '../model/types';
import { uuid } from '../model/types';
import { keyAfter } from '../model/fractional';
import { boxFromPoints, round2 } from '../model/geometry';
import type { Gesture, PointerInfo, Tool } from './input';
import { panGesture } from './select';

const CLICK_SIZE: [number, number] = [800, 600];

/** Make a frame over a box and take in what it holds — the ops of ONE batch, and the frame's id. */
export function frameOps(b: Board, x1: number, y1: number, x2: number, y2: number, title: string): { ops: Op[]; id: string } {
  const id = uuid();
  const w = x2 - x1, h = y2 - y1;
  const frames = b.store.all().filter((i) => i.type === 'frame');
  const z = keyAfter(frames.reduce<string | null>((m, f) => (m === null || f.z > m ? f.z : m), null));
  const ops: Op[] = [{ op: 'create', item: { id, type: 'frame', parent_id: null, x: round2(x1 + w / 2), y: round2(y1 + h / 2), width: round2(w), height: round2(h),
    rotation: 0, z, style: { fillColor: '#ffffff' }, data: { title }, start_item_id: null, end_item_id: null, locked: false, version: 1 } }];
  for (const it of b.store.all()) {
    if (it.type === 'frame' || it.type === 'connector' || it.locked) continue;
    const c = b.store.absCentre(it);
    if (c.x < x1 || c.x > x2 || c.y < y1 || c.y > y2) continue;
    ops.push({ op: 'update', id: it.id, fields: { parent_id: id, x: round2(c.x - x1), y: round2(c.y - y1) } });
  }
  return { ops, id };
}

export class FrameTool implements Tool {
  readonly name = 'frame';
  readonly cursor = 'crosshair';
  constructor(private b: Board) {}

  down(p: PointerInfo): Gesture {
    const b = this.b;
    if (p.touch) {
      // a finger: a tap makes a frame there, a drag pans
      let moved = false;
      const pan = panGesture(b, p);
      return { move: (q) => { if (Math.hypot(q.sx - p.sx, q.sy - p.sy) > 8) moved = true; if (moved) pan.move(q); },
        up: () => { if (!moved) this.make(p.x - CLICK_SIZE[0] / 2, p.y - CLICK_SIZE[1] / 2, p.x + CLICK_SIZE[0] / 2, p.y + CLICK_SIZE[1] / 2); }, cancel: () => undefined };
    }
    const z = b.view.camera.zoom;
    const ghost = new Konva.Rect({ x: p.x, y: p.y, width: 0, height: 0, fill: 'rgba(255,255,255,0.6)', stroke: '#868e96', strokeWidth: 1 / z, listening: false });
    b.renderer.selection.add(ghost);
    let box = boxFromPoints(p.x, p.y, p.x, p.y);
    let dragged = false;
    return {
      move: (q) => {
        if (Math.hypot(q.sx - p.sx, q.sy - p.sy) > 8) dragged = true;
        box = boxFromPoints(p.x, p.y, q.x, q.y);
        ghost.setAttrs({ x: box.x1, y: box.y1, width: box.x2 - box.x1, height: box.y2 - box.y1 });
        b.renderer.request();
      },
      up: () => {
        ghost.destroy();
        if (dragged && box.x2 - box.x1 >= 40 && box.y2 - box.y1 >= 40) this.make(box.x1, box.y1, box.x2, box.y2);
        else this.make(p.x - CLICK_SIZE[0] / 2, p.y - CLICK_SIZE[1] / 2, p.x + CLICK_SIZE[0] / 2, p.y + CLICK_SIZE[1] / 2);
      },
      cancel: () => { ghost.destroy(); b.renderer.request(); },
    };
  }

  private make(x1: number, y1: number, x2: number, y2: number): void {
    const b = this.b;
    const n = b.store.all().filter((i: Item) => i.type === 'frame').length + 1;
    const { ops, id } = frameOps(b, x1, y1, x2, y2, `Frame ${n}`);
    b.commit(ops);
    b.selection.set([id]);
    b.setTool('select');
    b.editInline(id);
  }
}
