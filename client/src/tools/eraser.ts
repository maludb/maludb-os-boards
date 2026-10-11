// Eraser (E) (slice 5; pen-files.md "Eraser"): a stroke-eraser — every drawing its path touches (hit-tested on the drawn line, the eraser 10
// px across on screen) disappears while the press lasts and is deleted in ONE batch on release; Escape or a second finger cancels and brings
// them back. Only drawings: a sticky or an image is never erased. Partial erasing is out of scope.
import Konva from 'konva';
import type { Board } from '../board';
import type { Item } from '../model/types';
import { drawingDistanceAt, drawingReach } from '../render/items/drawing';
import type { Gesture, PointerInfo, Tool } from './input';

const RADIUS_PX = 10;

/** The drawings within reach of a canvas point (not locked). */
export function drawingsAt(b: Board, x: number, y: number, radius: number): Item[] {
  const hit: Item[] = [];
  for (const it of b.store.items.values()) {
    if (it.type !== 'drawing' || it.locked) continue;
    const box = b.renderer.boxOf(it.id);
    const reach = drawingReach(it) + radius;
    if (!box || x < box.x1 - reach || x > box.x2 + reach || y < box.y1 - reach || y > box.y2 + reach) continue;
    if (drawingDistanceAt(it, b.store.absCentre(it), x, y) <= reach) hit.push(it);
  }
  return hit;
}

export class EraserTool implements Tool {
  readonly name = 'eraser';
  readonly cursor = 'cell';
  constructor(private b: Board) {}

  down(p: PointerInfo): Gesture {
    const b = this.b;
    const zoom = b.view.camera.zoom;
    const r = RADIUS_PX / zoom;
    const touched = new Set<string>();
    const ring = new Konva.Circle({ x: p.x, y: p.y, radius: r, stroke: '#868e96', strokeWidth: 1 / zoom, dash: [3 / zoom, 3 / zoom], listening: false });
    b.renderer.selection.add(ring);
    let last = { x: p.x, y: p.y };
    const touch = (x: number, y: number) => {
      // along the segment since the last move, a sample every radius: a fast swipe skips nothing
      const n = Math.max(1, Math.ceil(Math.hypot(x - last.x, y - last.y) / r));
      for (let k = 1; k <= n; k++) {
        const sx = last.x + ((x - last.x) * k) / n, sy = last.y + ((y - last.y) * k) / n;
        for (const it of drawingsAt(b, sx, sy, r)) if (!touched.has(it.id)) { touched.add(it.id); b.renderer.hide(it.id, true); }
      }
      last = { x, y };
      ring.position({ x, y });
      b.renderer.request();
    };
    for (const it of drawingsAt(b, p.x, p.y, r)) { touched.add(it.id); b.renderer.hide(it.id, true); }
    b.renderer.request();
    const done = () => { ring.destroy(); for (const id of touched) b.renderer.hide(id, false); };
    return {
      move: (q) => touch(q.x, q.y),
      up: () => {
        const ids = [...touched].filter((id) => b.store.has(id));
        done();
        if (ids.length) b.commit([{ op: 'delete', ids }]); else b.renderer.request();
      },
      cancel: () => { done(); b.renderer.request(); },
    };
  }
}
