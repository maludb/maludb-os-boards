// The layers and the drawing (canvas-core.md client/src/render/layers.ts). Background (dots | grid | none, from boards.background) is a CSS
// pattern on the stage's element, moved with the camera — no canvas to clear. Frames (below every other item) and items are two groups of ONE
// canvas layer; selection (the Transformer, the marquee, lock outlines) and presence (slice 3) are layers drawn only while they hold
// something. On software rendering every canvas costs a clear and a composite a frame: three canvases, not five.
//
// HOW AN ITEM IS DRAWN — the exemplar for every later type (slices 4 and 5 add theirs exactly this way): each type has a draw function over
// its own box [0, w] × [0, h] (render/items/*.ts). What is not selected is painted by BATCH shapes — one Konva.Shape walks a run of items in
// z order and calls the draw functions on the canvas directly (no node an item: at thousands of items the per-node cost IS the frame). A
// selected item gets a node of its own — a Konva.Shape at its centre, rotated, whose scene function is the SAME draw function — because the
// Transformer needs a node; the runs are split around it, so the z order holds. Below zoom 0.4 the batch draws flat rectangles (lod.ts).
// An item in a frame is drawn at the frame's top-left + its relative centre, so a frame's move is one update and its children follow.
// Konva's automatic drawing is off: ONE frame per animation frame culls (cull.ts), lays out the runs and draws — and records its time for
// the performance gate (window.__boards.perf()).
import Konva from 'konva';
import type { Item } from '../model/types';
import type { Store, Change } from '../model/store';
import type { View } from './stage';
import { cullBox, inView, sortedByZ } from './cull';
import { drawFlatLocal, drawFlats, lodActive } from './lod';
import { drawSticky } from './items/sticky';
import { drawShape } from './items/shape';
import { drawText } from './items/text';
import { drawPlaceholder } from './items/placeholder';

Konva.autoDrawEnabled = false;

export interface FrameStats { frames: number; median: number; p95: number; max: number; drawMedian: number; drawMax: number; samples: number[] }

type Native = CanvasRenderingContext2D;
const native = (ctx: Konva.Context): Native => (ctx as unknown as { _context: Native })._context;

/** An item in its own box [0, w] × [0, h], by its type (flat below the low zoom). */
export function drawItem(c: Native, item: Item, zoom: number): void {
  if (lodActive(zoom)) { drawFlatLocal(c, item, zoom); return; }
  switch (item.type) {
    case 'sticky_note': drawSticky(c, item, zoom); break;
    case 'shape': drawShape(c, item, zoom); break;
    case 'text': drawText(c, item, zoom); break;
    default: drawPlaceholder(c, item, zoom);
  }
}

export class Renderer {
  readonly content = new Konva.Layer({ listening: false });
  readonly frames = new Konva.Group({ listening: false });
  readonly items = new Konva.Group({ listening: false });
  readonly selection = new Konva.Layer();
  readonly presence = new Konva.Layer({ listening: false });
  /** the selected items' own nodes (the Transformer holds them) */
  private nodes = new Map<string, Konva.Shape>();
  private batches: Konva.Shape[] = [];
  private sortDirty = true;
  private sorted: { frames: Item[]; items: Item[] } = { frames: [], items: [] };
  private requested: number | null = null;
  private keepIds = new Set<string>();
  private hiddenIds = new Set<string>();
  private drawnCount = 0;
  private drewSelection = true;
  private drewPresence = true;
  /** a drag in progress: id → the offset (canvas units) it is drawn at until the drop commits */
  readonly dragOffsets = new Map<string, { dx: number; dy: number }>();
  private afterFrame: (() => void)[] = [];
  // the frame clock
  private intervals: number[] = [];
  private draws: number[] = [];
  private lastFrameAt = 0;
  private bgKey = '';

  constructor(private view: View, private store: Store, private background: { pattern?: string; color?: string }) {
    this.content.add(this.frames, this.items);
    view.stage.add(this.content, this.selection, this.presence);
    view.onChange(() => this.request());
    store.subscribe((c) => this.onStore(c));
  }

  onFrame(fn: () => void): void { this.afterFrame.push(fn); }

  /** Selected items get nodes of their own whatever the cull says (the Transformer holds them). */
  keep(ids: Iterable<string>): void { this.keepIds = new Set(ids); this.request(); }
  /** An item whose text is being edited is hidden under the editor. */
  hide(id: string, on: boolean): void { if (on) this.hiddenIds.add(id); else this.hiddenIds.delete(id); this.request(); }
  nodeOf(id: string): Konva.Shape | undefined { return this.nodes.get(id); }
  /** Draw again (a drag cancelled, a remote change under a drag). */
  markDirty(_ids: Iterable<string>): void { this.request(); }
  setBackground(bg: { pattern?: string; color?: string }): void { this.background = bg; this.bgKey = ''; this.request(); }

  private onStore(c: Change): void {
    if (c.all) { for (const n of this.nodes.values()) n.destroy(); this.nodes.clear(); }
    for (const id of c.ids) {
      if (!this.store.has(id)) { this.nodes.get(id)?.destroy(); this.nodes.delete(id); }
    }
    if (c.structural || c.all) this.sortDirty = true;
    this.request();
  }

  /** Ask for one frame. */
  request(): void {
    if (this.requested !== null) return;
    this.requested = requestAnimationFrame((t) => { this.requested = null; this.frame(t); });
  }

  /** Draw now (a test, a thumbnail). */
  flush(): void {
    if (this.requested !== null) { cancelAnimationFrame(this.requested); this.requested = null; }
    this.frame(performance.now());
  }

  private frame(at: number): void {
    const t0 = performance.now();
    if (this.sortDirty) { this.sorted = sortedByZ(this.store); this.sortDirty = false; }
    const area = cullBox(this.view.viewport());
    const visFrames = inView(this.sorted.frames, this.store, area, this.keepIds);
    const visItems = inView(this.sorted.items, this.store, area, this.keepIds);
    // the kept items' nodes, placed where they are drawn; nodes of items no longer kept go
    for (const it of [...visFrames, ...visItems]) if (this.keepIds.has(it.id)) this.place(this.node(it.id), it);
    for (const [id, n] of this.nodes) if (!this.keepIds.has(id)) { n.destroy(); this.nodes.delete(id); }
    let pool = 0;
    pool = this.lay(this.frames, visFrames, pool);
    this.lay(this.items, visItems, pool);
    this.drawnCount = visFrames.length + visItems.length;
    for (const fn of this.afterFrame) fn();
    this.drawBackground();
    this.content.draw();
    // the selection and presence layers: drawn while they hold something, and once more to clear
    const sel = holds(this.selection);
    if (sel || this.drewSelection) this.selection.draw();
    this.drewSelection = sel;
    const pres = holds(this.presence);
    if (pres || this.drewPresence) this.presence.draw();
    this.drewPresence = pres;
    const t1 = performance.now();
    if (this.lastFrameAt > 0 && at - this.lastFrameAt < 250) this.intervals.push(at - this.lastFrameAt);
    this.lastFrameAt = at;
    this.draws.push(t1 - t0);
    if (this.draws.length > 2000) { this.draws.splice(0, 1000); this.intervals.splice(0, Math.max(0, this.intervals.length - 1000)); }
  }

  /** A group's children: runs of items in batches with a kept item's node between them — re-attached only when they change. */
  private lay(group: Konva.Group, list: Item[], pool: number): number {
    const used: Konva.Shape[] = [];
    let run: Item[] = [];
    const flush = () => {
      if (!run.length) return;
      const b = this.batches[pool] ?? this.newBatch();
      this.batches[pool++] = b;
      b.setAttr('list', run);
      used.push(b);
      run = [];
    };
    for (const it of list) {
      if (this.keepIds.has(it.id)) { flush(); used.push(this.nodes.get(it.id)!); }
      else run.push(it);
    }
    flush();
    const now = group.getChildren();
    if (now.length !== used.length || used.some((n, k) => now[k] !== n)) {
      group.removeChildren();
      for (const n of used) group.add(n);
    }
    return pool;
  }

  private newBatch(): Konva.Shape {
    return new Konva.Shape({ listening: false, perfectDrawEnabled: false, sceneFunc: (ctx, shape) => {
      const c = native(ctx);
      const list = (shape.getAttr('list') as Item[]) ?? [];
      const zoom = this.view.camera.zoom;
      if (lodActive(zoom)) { drawFlats(c, list, (i) => this.drawnCentre(i), zoom, this.hiddenIds); return; }
      for (const it of list) {
        if (this.hiddenIds.has(it.id)) continue;
        const p = this.drawnCentre(it);
        const rot = it.type === 'frame' ? 0 : it.rotation;
        c.save();
        c.translate(p.x, p.y);
        if (rot) c.rotate((rot * Math.PI) / 180);
        c.translate(-it.width / 2, -it.height / 2);
        drawItem(c, it, zoom);
        c.restore();
      }
    } });
  }

  /** A kept item's node: a shape whose scene function is the item's own draw function. */
  private node(id: string): Konva.Shape {
    let n = this.nodes.get(id);
    if (!n) {
      n = new Konva.Shape({ listening: false, perfectDrawEnabled: false, sceneFunc: (ctx) => {
        const it = this.store.get(id);
        if (it) drawItem(native(ctx), it, this.view.camera.zoom);
      } });
      n.setAttr('itemId', id);
      this.nodes.set(id, n);
    }
    return n;
  }

  /** Put a node where its item is drawn (unless the Transformer is holding it mid-gesture: its scale is not 1). */
  private place(n: Konva.Shape, it: Item): void {
    if (n.scaleX() !== 1 || n.scaleY() !== 1) return;
    const p = this.drawnCentre(it);
    n.setAttrs({ x: p.x, y: p.y, width: it.width, height: it.height, offsetX: it.width / 2, offsetY: it.height / 2,
      rotation: it.type === 'frame' ? 0 : it.rotation, visible: !this.hiddenIds.has(it.id) });
  }

  /** Where an item is drawn: its absolute centre, plus a drag's offset (its own, or its frame's). */
  private drawnCentre(item: Item): { x: number; y: number } {
    const c = this.store.absCentre(item);
    if (this.dragOffsets.size === 0) return c;
    const off = this.dragOffsets.get(item.id) ?? (item.parent_id ? this.dragOffsets.get(item.parent_id) : undefined);
    return off ? { x: c.x + off.dx, y: c.y + off.dy } : c;
  }

  /** The frame clock: frame intervals while the view moves, and the time each frame took here. */
  stats(): FrameStats {
    const sorted = (a: number[]) => [...a].sort((x, y) => x - y);
    const med = (a: number[]) => (a.length ? sorted(a)[Math.floor(a.length / 2)] : 0);
    const p95 = (a: number[]) => (a.length ? sorted(a)[Math.min(a.length - 1, Math.floor(a.length * 0.95))] : 0);
    return { frames: this.intervals.length, median: med(this.intervals), p95: p95(this.intervals), max: Math.max(0, ...this.intervals),
      drawMedian: med(this.draws), drawMax: Math.max(0, ...this.draws), samples: [...this.intervals] };
  }
  resetStats(): void { this.intervals = []; this.draws = []; this.lastFrameAt = 0; }
  /** How many items the last frame drew (culled in). */
  attachedCount(): number { return this.drawnCount; }

  /** The performance gate's pan (canvas-core: "a 2,000 px pan over 60 frames" — 1,000 out and 1,000 back): one step a frame, drawn in that frame. */
  panFrames(dx: number, dy: number, frames: number): Promise<FrameStats> {
    this.resetStats();
    return new Promise((resolve) => {
      let k = 0;
      const step = (t: number) => {
        const sign = k < frames / 2 ? 1 : -1;               // out and back: the board stays in view
        this.view.panBy((-2 * sign * dx) / frames, (-2 * sign * dy) / frames);
        if (this.requested !== null) { cancelAnimationFrame(this.requested); this.requested = null; }
        this.frame(t);
        if (++k < frames) requestAnimationFrame(step);
        else requestAnimationFrame((t2) => { this.frame(t2); resolve(this.stats()); });
      };
      requestAnimationFrame(step);
    });
  }

  /** The board's dots or grid: a CSS pattern on the stage's element, its cell and offset following the camera. */
  private drawBackground(): void {
    const el = this.view.container;
    const pattern = this.background.pattern ?? 'dots';
    let cell = 20 * this.view.camera.zoom;
    while (cell < 10) cell *= 4;
    const dark = (this.background.color ?? '#ffffff').toLowerCase() === '#212529';
    const key = `${pattern}|${cell.toFixed(3)}|${dark}`;
    if (key !== this.bgKey) {
      this.bgKey = key;
      const ink = dark ? '#495057' : '#c4c8ce';
      const line = dark ? '#343a40' : '#e9ecef';
      el.style.backgroundImage = pattern === 'none' ? 'none'
        : pattern === 'grid' ? `linear-gradient(to right, ${line} 1px, transparent 1px), linear-gradient(to bottom, ${line} 1px, transparent 1px)`
        : `radial-gradient(circle, ${ink} 1.1px, transparent 1.4px)`;
      el.style.backgroundSize = `${cell}px ${cell}px`;
    }
    // canvas (0, 0) on the screen, modulo a cell; the dots sit at the cells' centres
    const o = this.view.toScreen(0, 0);
    const mod = (a: number, m: number) => ((a % m) + m) % m;
    const shift = pattern === 'grid' ? 0 : cell / 2;
    el.style.backgroundPosition = `${mod(o.x - shift, cell).toFixed(2)}px ${mod(o.y - shift, cell).toFixed(2)}px`;
  }
}

/** Does a layer hold anything to draw: a Transformer with nodes, a group with children, any other shape? */
function holds(layer: Konva.Layer): boolean {
  return layer.getChildren().some((n) => (n instanceof Konva.Transformer ? n.nodes().length > 0 : n instanceof Konva.Group ? n.getChildren().length > 0 : n.isVisible()));
}
