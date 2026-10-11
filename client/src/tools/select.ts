// Select (canvas.md: select, multi-select with shift, marquee, move, resize and rotate with Konva's Transformer, lock): the selection
// itself, the hit test, and the select tool's gestures. A drag commits ONCE, on drop — one update per moved item, one batch — re-parenting
// an item whose centre lands in a frame (canvas-core "Into and out of a frame"); resize and rotate commit on transform end. A locked item
// is selected but never moved or transformed; a viewer's or commenter's selection has no Transformer at all.
import Konva from 'konva';
import type { Board } from '../board';
import type { Fields, Item, Op } from '../model/types';
import { plainOf } from '../model/types';
import { boxFromPoints, containsBox, frameTopLeft, hitsRotated, normaliseRotation, round2 } from '../model/geometry';
import { textFontSize, textHeightFor } from '../render/items/text';
import type { Gesture, PointerInfo, Tool } from './input';

const DRAG_THRESHOLD = 4;

export class Selection {
  readonly ids = new Set<string>();
  readonly tr: Konva.Transformer;
  private outline = new Konva.Group({ listening: false });
  private listeners: (() => void)[] = [];
  private startAttrs = new Map<string, { x: number; y: number; w: number; h: number; tlx: number; tly: number }>();

  constructor(private b: Board) {
    this.tr = new Konva.Transformer({
      rotateAnchorOffset: 28, anchorSize: 10, anchorCornerRadius: 5, borderStroke: '#3454d1', anchorStroke: '#3454d1', anchorFill: '#ffffff',
      ignoreStroke: true, flipEnabled: false, rotationSnaps: [0, 45, 90, 135, 180, 225, 270, 315], rotationSnapTolerance: 4,
      boundBoxFunc: (oldBox, newBox) => (Math.abs(newBox.width) < 20 || Math.abs(newBox.height) < 20 ? oldBox : newBox),
    });
    b.renderer.selection.add(this.outline, this.tr);
    this.tr.on('transformstart', () => this.onTransformStart());
    this.tr.on('transform', () => { b.renderer.request(); this.sendGhost(); });
    this.tr.on('transformend', () => { b.live?.drag([]); this.onTransformEnd(); });
    b.renderer.onFrame(() => this.refresh());
    b.store.subscribe(() => {
      let gone = false;
      for (const id of [...this.ids]) if (!b.store.has(id)) { this.ids.delete(id); gone = true; }
      if (gone) this.changed();
    });
  }

  onChange(fn: () => void): void { this.listeners.push(fn); }
  list(): string[] { return [...this.ids]; }
  has(id: string): boolean { return this.ids.has(id); }

  set(ids: string[]): void {
    const next = new Set(ids.filter((i) => this.b.store.has(i)));
    if (next.size === this.ids.size && [...next].every((i) => this.ids.has(i))) return;
    this.ids.clear();
    next.forEach((i) => this.ids.add(i));
    this.changed();
  }
  add(id: string): void { if (!this.ids.has(id)) { this.ids.add(id); this.changed(); } }
  toggle(id: string): void { if (this.ids.has(id)) this.ids.delete(id); else this.ids.add(id); this.changed(); }
  clear(): void { if (this.ids.size) { this.ids.clear(); this.changed(); } }

  private changed(): void {
    this.b.renderer.keep(this.ids);
    for (const l of this.listeners) l();
  }

  /** Called after every frame: the Transformer holds the selected, unlocked, attached nodes; locked ones get an outline. */
  refresh(): void {
    const items = this.list().map((id) => this.b.store.get(id)).filter((i): i is Item => !!i);
    const movable = this.b.editable ? items.filter((i) => !i.locked) : [];
    const nodes = movable.map((i) => this.b.renderer.nodeOf(i.id)).filter((n): n is Konva.Shape => !!n && !!n.getLayer());
    const current = this.tr.nodes();
    if (nodes.length !== current.length || nodes.some((n, k) => n !== current[k])) {
      const one = movable.length === 1 ? movable[0] : null;
      const anyFrame = movable.some((i) => i.type === 'frame');
      this.tr.setAttrs({
        rotateEnabled: !anyFrame,
        keepRatio: one ? one.type === 'sticky_note' : true,
        enabledAnchors: one?.type === 'sticky_note' ? ['top-left', 'top-right', 'bottom-left', 'bottom-right']
          : one?.type === 'text' ? ['middle-left', 'middle-right', 'top-left', 'top-right', 'bottom-left', 'bottom-right']
          : one ? ['top-left', 'top-center', 'top-right', 'middle-right', 'middle-left', 'bottom-left', 'bottom-center', 'bottom-right']
          : ['top-left', 'top-right', 'bottom-left', 'bottom-right'],
      });
      this.tr.nodes(nodes);
    } else {
      this.tr.forceUpdate();
    }
    // outlines: every selected item the Transformer does not hold (locked, or a read-only level)
    this.outline.destroyChildren();
    const z = this.b.view.camera.zoom;
    for (const i of items) {
      if (movable.includes(i)) continue;
      const c = this.b.store.absCentre(i);
      this.outline.add(new Konva.Rect({ x: c.x, y: c.y, width: i.width, height: i.height, offsetX: i.width / 2, offsetY: i.height / 2,
        rotation: i.type === 'frame' ? 0 : i.rotation, stroke: i.locked ? '#f59f00' : '#3454d1', strokeWidth: 2 / z, dash: i.locked ? [6 / z, 4 / z] : undefined,
        listening: false }));
    }
  }

  /** What the Transformer holds, as the peers' ghost: centre, size and rotation mid-gesture. */
  private sendGhost(): void {
    if (!this.b.live) return;
    this.b.live.drag(this.tr.nodes().map((n) => {
      const it = this.b.store.get(n.getAttr('itemId') as string);
      return { id: n.getAttr('itemId') as string, x: n.x(), y: n.y(), width: (it?.width ?? 0) * Math.abs(n.scaleX()), height: (it?.height ?? 0) * Math.abs(n.scaleY()), rotation: n.rotation() };
    }));
  }

  private onTransformStart(): void {
    this.startAttrs.clear();
    for (const n of this.tr.nodes()) {
      const id = n.getAttr('itemId') as string;
      const it = this.b.store.get(id);
      if (!it) continue;
      const c = this.b.store.absCentre(it);
      this.startAttrs.set(id, { x: c.x, y: c.y, w: it.width, h: it.height, tlx: c.x - it.width / 2, tly: c.y - it.height / 2 });
    }
  }

  /** Resize and rotate commit once, at the end: size, centre, rotation per item (a frame's children keep their place on the canvas). */
  private onTransformEnd(): void {
    const ops: Op[] = [];
    for (const n of this.tr.nodes()) {
      const id = n.getAttr('itemId') as string;
      const it = this.b.store.get(id);
      if (!it) continue;
      const sx = Math.abs(n.scaleX());
      const sy = Math.abs(n.scaleY());
      let w = round2(Math.max(20, it.width * sx));
      let h = round2(Math.max(20, it.height * sy));
      if (it.type === 'text') { w = round2(Math.max(20, it.width * sx)); h = textHeightFor(plainOf(it), w, textFontSize(it) * (sy !== 1 && sx !== 1 ? Math.min(sx, sy) : 1)); }
      const f: Fields = {};
      const rot = it.type === 'frame' ? 0 : normaliseRotation(n.rotation());
      if (w !== it.width) f.width = w;
      if (h !== it.height) f.height = h;
      if (rot !== normaliseRotation(it.rotation)) f.rotation = rot;
      if (it.type === 'text' && sx !== 1 && sy !== 1 && Math.abs(sx - sy) < 0.02) f.style = { fontSize: Math.max(6, Math.round(textFontSize(it) * Math.min(sx, sy))) };
      // the centre after the transform (the node is drawn around its centre), back to frame-relative under a frame
      let cx = n.x();
      let cy = n.y();
      if (it.parent_id) { const fr = this.b.store.get(it.parent_id); if (fr) { const tl = frameTopLeft(fr); cx -= tl.x; cy -= tl.y; } }
      cx = round2(cx); cy = round2(cy);
      if (cx !== round2(it.x)) f.x = cx;
      if (cy !== round2(it.y)) f.y = cy;
      if (Object.keys(f).length) ops.push({ op: 'update', id, fields: f });
      // a frame resized from its left or top: its children keep their place on the canvas
      if (it.type === 'frame') {
        const s = this.startAttrs.get(id);
        const nx = n.x() - w / 2;
        const ny = n.y() - h / 2;
        if (s && (Math.abs(nx - s.tlx) > 0.01 || Math.abs(ny - s.tly) > 0.01)) {
          for (const ch of this.b.store.children(id)) ops.push({ op: 'update', id: ch.id, fields: { x: round2(ch.x - (nx - s.tlx)), y: round2(ch.y - (ny - s.tly)) } });
        }
      }
      n.scale({ x: 1, y: 1 });
    }
    if (ops.length) this.b.commit(ops);
    else this.b.renderer.markDirty(this.list());
  }
}

/** The topmost item under a canvas point: items above frames, each by z, highest first. */
export function hitTest(b: Board, x: number, y: number): Item | null {
  let best: Item | null = null;
  let bestFrame: Item | null = null;
  const slop = 4 / b.view.camera.zoom;
  for (const it of b.store.items.values()) {
    const box = b.store.box(it.id);
    if (!box || x < box.x1 - slop || x > box.x2 + slop || y < box.y1 - slop || y > box.y2 + slop) continue;
    const c = b.store.absCentre(it);
    if (!hitsRotated(x, y, c.x, c.y, it.width, it.height, it.type === 'frame' ? 0 : it.rotation, slop)) continue;
    if (it.type === 'frame') { if (!bestFrame || it.z > bestFrame.z) bestFrame = it; }
    else if (!best || it.z > best.z) best = it;
  }
  return best ?? bestFrame;
}

/** The ops of a drop: the moved items' new centres, re-parented by the frame their centre lands in. */
export function dropOps(b: Board, ids: string[], dx: number, dy: number): Op[] {
  const moving = new Set(ids);
  const ops: Op[] = [];
  for (const id of ids) {
    const it = b.store.get(id);
    if (!it || it.locked) continue;
    if (it.parent_id && moving.has(it.parent_id)) continue;         // it moves with its frame
    if (it.type === 'frame') { ops.push({ op: 'update', id, fields: { x: round2(it.x + dx), y: round2(it.y + dy) } }); continue; }
    const c = b.store.absCentre(it);
    const ax = c.x + dx;
    const ay = c.y + dy;
    const frame = b.frameAt(ax, ay, moving);
    const f: Fields = {};
    if (frame) { const tl = frameTopLeft(frame); f.x = round2(ax - tl.x); f.y = round2(ay - tl.y); }
    else { f.x = round2(ax); f.y = round2(ay); }
    const parent = frame ? frame.id : null;
    if (parent !== it.parent_id) f.parent_id = parent;
    ops.push({ op: 'update', id, fields: f });
  }
  return ops;
}

export class SelectTool implements Tool {
  readonly name = 'select';
  readonly cursor = 'default';
  constructor(private b: Board) {}

  down(p: PointerInfo): Gesture | null {
    const b = this.b;
    const hit = hitTest(b, p.x, p.y);
    if (hit) {
      const wasSelected = b.selection.has(hit.id);
      if (p.shift) { b.selection.toggle(hit.id); return null; }
      if (p.touch && !wasSelected) return touchOn(b, p, hit);       // a finger: a tap selects, a drag pans, a long press opens its panel
      if (!wasSelected) b.selection.set([hit.id]);
      const movable = b.editable ? b.selection.list().filter((id) => { const i = b.store.get(id); return i && !i.locked; }) : [];
      if (movable.length === 0) return longPressOnly(b, p, hit);
      return this.drag(p, movable, hit);
    }
    // empty canvas: a finger pans (and a long press does nothing); a mouse draws a marquee
    if (p.touch) {
      if (!p.shift) b.selection.clear();
      return panGesture(b, p);
    }
    return this.marquee(p);
  }

  dbl(p: PointerInfo): void {
    const hit = hitTest(this.b, p.x, p.y);
    if (hit && this.b.editable && !hit.locked && ['sticky_note', 'shape', 'text'].includes(hit.type)) {
      this.b.selection.set([hit.id]);
      this.b.editText(hit.id);
    }
  }

  private drag(p: PointerInfo, ids: string[], hit: Item): Gesture {
    const b = this.b;
    let moved = false;
    let dx = 0, dy = 0;
    const press = window.setTimeout(() => { if (!moved && p.touch) { b.selection.set([hit.id]); b.fire('boards:open-item', { id: hit.id }); } }, 500);
    return {
      move: (q) => {
        dx = q.x - p.x; dy = q.y - p.y;
        if (!moved && Math.hypot(q.sx - p.sx, q.sy - p.sy) < DRAG_THRESHOLD) return;
        moved = true;
        window.clearTimeout(press);
        for (const id of ids) b.renderer.dragOffsets.set(id, { dx, dy });
        b.renderer.markDirty(ids.flatMap((id) => [id, ...b.store.children(id).map((c) => c.id)]));
        b.live?.drag(ids.map((id) => b.store.get(id)).filter((i): i is Item => !!i).map((i) => {
          const c = b.store.absCentre(i);
          return { id: i.id, x: c.x + dx, y: c.y + dy, width: i.width, height: i.height, rotation: i.type === 'frame' ? 0 : i.rotation };
        }));
      },
      up: () => {
        window.clearTimeout(press);
        if (moved) b.live?.drag([]);
        const touched = ids.flatMap((id) => [id, ...b.store.children(id).map((c) => c.id)]);
        for (const id of ids) b.renderer.dragOffsets.delete(id);
        if (moved && (dx !== 0 || dy !== 0)) {
          const ops = dropOps(b, ids, dx, dy);
          if (ops.length) b.commit(ops);
        }
        b.renderer.markDirty(touched);
      },
      cancel: () => {
        window.clearTimeout(press);
        if (moved) b.live?.drag([]);
        for (const id of ids) b.renderer.dragOffsets.delete(id);
        b.renderer.markDirty(ids.flatMap((id) => [id, ...b.store.children(id).map((c) => c.id)]));
      },
    };
  }

  private marquee(p: PointerInfo): Gesture {
    const b = this.b;
    const z = b.view.camera.zoom;
    const rect = new Konva.Rect({ x: p.x, y: p.y, width: 0, height: 0, fill: 'rgba(52,84,209,0.08)', stroke: '#3454d1', strokeWidth: 1 / z, listening: false });
    b.renderer.selection.add(rect);
    const before = p.shift ? b.selection.list() : [];
    if (!p.shift) b.selection.clear();
    let box = boxFromPoints(p.x, p.y, p.x, p.y);
    return {
      move: (q) => {
        box = boxFromPoints(p.x, p.y, q.x, q.y);
        rect.setAttrs({ x: box.x1, y: box.y1, width: box.x2 - box.x1, height: box.y2 - box.y1 });
        b.renderer.request();
      },
      up: () => {
        rect.destroy();
        if (box.x2 - box.x1 > 2 / z || box.y2 - box.y1 > 2 / z) {
          const inside = b.store.all().filter((i) => { const bb = b.store.box(i.id); return bb !== null && containsBox(box, bb); }).map((i) => i.id);
          b.selection.set([...new Set([...before, ...inside])]);
        }
        b.renderer.request();
      },
      cancel: () => { rect.destroy(); b.renderer.request(); },
    };
  }
}

/** A finger or the hand: the board follows the pointer. */
export function panGesture(b: Board, p: PointerInfo): Gesture {
  let lx = p.sx, ly = p.sy;
  return {
    move: (q) => { b.view.panBy(q.sx - lx, q.sy - ly); lx = q.sx; ly = q.sy; },
    up: () => undefined,
    cancel: () => undefined,
  };
}

/** A finger on an item that is not selected: a tap selects it, a drag pans the board, a long press (500 ms) selects and opens its panel. */
function touchOn(b: Board, p: PointerInfo, hit: Item): Gesture {
  let moved = false;
  const pan = panGesture(b, p);
  const t = window.setTimeout(() => { if (!moved) { b.selection.set([hit.id]); b.fire('boards:open-item', { id: hit.id }); } }, 500);
  return {
    move: (q) => { if (!moved && Math.hypot(q.sx - p.sx, q.sy - p.sy) > 8) { moved = true; window.clearTimeout(t); } if (moved) pan.move(q); },
    up: () => { window.clearTimeout(t); if (!moved) b.selection.set([hit.id]); },
    cancel: () => window.clearTimeout(t),
  };
}

/** On something that cannot move here (locked, or a read-only level): a long press still opens its panel. */
function longPressOnly(b: Board, p: PointerInfo, hit: Item): Gesture {
  let moved = false;
  const t = window.setTimeout(() => { if (!moved && p.touch) b.fire('boards:open-item', { id: hit.id }); }, 500);
  const pan = p.touch ? panGesture(b, p) : null;
  return {
    move: (q) => { if (Math.hypot(q.sx - p.sx, q.sy - p.sy) > 8) { moved = true; window.clearTimeout(t); } pan?.move(q); },
    up: () => window.clearTimeout(t),
    cancel: () => window.clearTimeout(t),
  };
}
