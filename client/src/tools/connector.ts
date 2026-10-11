// Connector (C) (slice 4; connectors-frames.md): from an item's side handle (the select tool's hover) or, with this tool, from an item or an
// empty point, drag to another item (its side chosen `auto`) or to empty space (a free end, its point stored) → ONE create batch; then the
// tool goes back to Select with the connector selected. A selected connector's end handle drags that end to another item (its free point
// cleared) or into empty space (freed) → ONE update. Elbowed, an arrow at the end — the Properties panel changes the rest.
import Konva from 'konva';
import type { Board } from '../board';
import type { Fields, Item } from '../model/types';
import { uuid } from '../model/types';
import { keyAfter } from '../model/fractional';
import { round2 } from '../model/geometry';
import type { Side } from '../render/items/connector';
import type { Gesture, PointerInfo, Tool } from './input';
import { hitTest } from './select';

/** An item a connector may end on: anything but a connector (the database's rule) and not the one it starts from. */
function target(b: Board, x: number, y: number, not: string | null): Item | null {
  const hit = hitTest(b, x, y);
  return hit && hit.type !== 'connector' && hit.id !== not ? hit : null;
}

function preview(b: Board): Konva.Arrow {
  const z = b.view.camera.zoom;
  const a = new Konva.Arrow({ points: [0, 0, 0, 0], stroke: '#3454d1', fill: '#3454d1', strokeWidth: 2 / z, pointerLength: 10 / z, pointerWidth: 9 / z,
    dash: [6 / z, 4 / z], listening: false });
  b.renderer.selection.add(a);
  return a;
}

/** Draw a new connector from an item's side (or `auto`) or from a free point. */
export function connectorGesture(b: Board, from: { id?: string; snap?: Side | 'auto'; point?: { x: number; y: number } }, p: PointerInfo): Gesture {
  const line = preview(b);
  const start = () => {
    if (from.id) { const bx = b.renderer.boxOf(from.id); if (bx) return { x: (bx.x1 + bx.x2) / 2, y: (bx.y1 + bx.y2) / 2 }; }
    return from.point ?? { x: p.x, y: p.y };
  };
  let moved = false;
  return {
    move: (q) => {
      if (Math.hypot(q.sx - p.sx, q.sy - p.sy) > 6) moved = true;
      const s = start();
      line.points([s.x, s.y, q.x, q.y]);
      b.selection.setHover(target(b, q.x, q.y, from.id ?? null)?.id ?? null);
      b.renderer.request();
    },
    up: (q) => {
      line.destroy();
      b.selection.setHover(null);
      if (!moved) { b.renderer.request(); return; }
      const to = target(b, q.x, q.y, from.id ?? null);
      const data: Record<string, unknown> = { shape: 'elbowed', startSnap: from.id ? (from.snap ?? 'auto') : 'auto', endSnap: 'auto', captions: [] };
      if (!from.id) data.startPoint = { x: round2(start().x), y: round2(start().y) };
      if (!to) data.endPoint = { x: round2(q.x), y: round2(q.y) };
      const id = uuid();
      b.commit([{ op: 'create', item: { id, type: 'connector', parent_id: null, x: 0, y: 0, width: 0, height: 0, rotation: 0, z: keyAfter(b.store.highestZ()),
        style: { strokeColor: '#1a1a1a', strokeWidth: 2, strokeStyle: 'normal', startStrokeCap: 'none', endStrokeCap: 'arrow' }, data,
        start_item_id: from.id ?? null, end_item_id: to?.id ?? null, locked: false, version: 1 } }]);
      b.selection.set([id]);
      b.setTool('select');
    },
    cancel: () => { line.destroy(); b.selection.setHover(null); b.renderer.request(); },
  };
}

/** Move one end of a selected connector: onto an item (attached, its point cleared) or into empty space (a free point). */
export function endpointGesture(b: Board, k: Item, which: 'start' | 'end', p: PointerInfo): Gesture {
  const line = preview(b);
  const otherId = which === 'start' ? k.end_item_id : k.start_item_id;
  let moved = false;
  return {
    move: (q) => {
      if (Math.hypot(q.sx - p.sx, q.sy - p.sy) > 4) moved = true;
      line.points([p.x, p.y, q.x, q.y]);
      b.selection.setHover(target(b, q.x, q.y, otherId)?.id ?? null);
      b.renderer.request();
    },
    up: (q) => {
      line.destroy();
      b.selection.setHover(null);
      if (!moved) { b.renderer.request(); return; }
      const to = target(b, q.x, q.y, otherId);
      const key = which === 'start' ? 'startPoint' : 'endPoint';
      const f: Fields = which === 'start' ? { start_item_id: to?.id ?? null } : { end_item_id: to?.id ?? null };
      f.data = to ? { [key]: null, [which === 'start' ? 'startSnap' : 'endSnap']: 'auto' } : { [key]: { x: round2(q.x), y: round2(q.y) } };
      b.commit([{ op: 'update', id: k.id, fields: f }]);
    },
    cancel: () => { line.destroy(); b.selection.setHover(null); b.renderer.request(); },
  };
}

export class ConnectorTool implements Tool {
  readonly name = 'connector';
  readonly cursor = 'crosshair';
  constructor(private b: Board) {}

  down(p: PointerInfo): Gesture {
    const hit = hitTest(this.b, p.x, p.y);
    if (hit && hit.type !== 'connector') return connectorGesture(this.b, { id: hit.id, snap: 'auto' }, p);
    return connectorGesture(this.b, { point: { x: p.x, y: p.y } }, p);
  }
}
