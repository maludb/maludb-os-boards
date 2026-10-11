// Pen (P) (slice 5; pen-files.md "Drawing"): a stroke drawn with the mouse, a pen or a finger (one finger draws; two pinch), previewed as
// Konva's tension line while it is drawn, then on release simplified (Ramer–Douglas–Peucker at 1.5 / zoom, the tolerance doubled until the
// stroke has at most drawing_max_points points), boxed (its points' bounds + half the stroke) and committed as ONE create — a `drawing`
// whose points are relative to its top-left, rounded to 0.1; in a frame when its centre lands in one. The tool stays on for the next
// stroke. The highlighter (highlighter.ts) is this tool with its own width, colour and blend.
import Konva from 'konva';
import type { Board } from '../board';
import type { Op } from '../model/types';
import { uuid } from '../model/types';
import { keyAfter } from '../model/fractional';
import { frameTopLeft, round2 } from '../model/geometry';
import { HIGHLIGHTER_ALPHA, TENSION, simplify } from '../render/items/drawing';
import type { Gesture, PointerInfo, Tool } from './input';

export interface StrokeStyle { tool: 'pen' | 'highlighter'; color: string; width: number }

/** The stroke's create op (or null for nothing drawn): simplified, boxed, frame-relative when its centre is in a frame. */
export function strokeOp(b: Board, raw: number[], st: StrokeStyle, zoom: number): Op | null {
  if (raw.length < 2) return null;
  let pts = raw.length === 2 ? [raw[0], raw[1], raw[0] + 0.1, raw[1]] : simplify(raw, 1.5 / zoom, Math.max(2, b.drawingMaxPoints));
  if (pts.length / 2 > b.drawingMaxPoints) pts = pts.slice(0, b.drawingMaxPoints * 2);
  let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
  for (let k = 0; k + 1 < pts.length; k += 2) { x1 = Math.min(x1, pts[k]); y1 = Math.min(y1, pts[k + 1]); x2 = Math.max(x2, pts[k]); y2 = Math.max(y2, pts[k + 1]); }
  const pad = st.width / 2;
  const left = x1 - pad, top = y1 - pad;
  const rel = pts.map((v, k) => Math.round((v - (k % 2 === 0 ? left : top)) * 10) / 10);
  const cx = (x1 + x2) / 2, cy = (y1 + y2) / 2;
  const frame = b.frameAt(cx, cy);
  const tl = frame ? frameTopLeft(frame) : { x: 0, y: 0 };
  return { op: 'create', item: { id: uuid(), type: 'drawing', parent_id: frame?.id ?? null, x: round2(cx - tl.x), y: round2(cy - tl.y),
    width: round2(Math.max(1, x2 - x1 + st.width)), height: round2(Math.max(1, y2 - y1 + st.width)), rotation: 0, z: keyAfter(b.store.highestZ()),
    style: { strokeColor: st.color, strokeWidth: st.width }, data: { tool: st.tool, points: rel }, start_item_id: null, end_item_id: null, locked: false, version: 1 } };
}

export class PenTool implements Tool {
  readonly name: string = 'pen';
  readonly cursor = 'crosshair';
  constructor(protected b: Board) {}

  protected style(): StrokeStyle { return { tool: 'pen', color: this.b.pen.color, width: this.b.pen.width }; }

  down(p: PointerInfo): Gesture {
    const b = this.b;
    const st = this.style();
    const zoom = b.view.camera.zoom;
    const raw = [p.x, p.y];
    const line = new Konva.Line({ points: [p.x, p.y, p.x + 0.01, p.y], stroke: st.color, strokeWidth: st.width, lineCap: 'round', lineJoin: 'round',
      tension: TENSION, listening: false, opacity: st.tool === 'highlighter' ? HIGHLIGHTER_ALPHA : 1,
      globalCompositeOperation: st.tool === 'highlighter' ? 'multiply' : 'source-over' });
    b.renderer.selection.add(line);
    b.renderer.request();
    const minStep = 0.75 / zoom;
    return {
      move: (q) => {
        const lx = raw[raw.length - 2], ly = raw[raw.length - 1];
        if (Math.hypot(q.x - lx, q.y - ly) < minStep) return;
        raw.push(q.x, q.y);
        line.points(raw);
        b.renderer.request();
      },
      up: () => {
        line.destroy();
        const op = strokeOp(b, raw, st, zoom);
        if (op) b.commit([op]); else b.renderer.request();
      },
      cancel: () => { line.destroy(); b.renderer.request(); },
    };
  }
}
