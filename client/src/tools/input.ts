// Input (canvas-core.md "375 px and touch"): one router for the mouse, the pen and fingers — pointer events on the stage's container.
// One pointer → the active tool's gesture (a press, its moves, its release, or a cancel). Two fingers → pinch-zoom about their midpoint and
// pan, cancelling whatever one finger began. The mouse wheel pans (Ctrl or a trackpad pinch zooms at the pointer); the middle button or
// Space + drag pans with any tool. A press on the Transformer's handles is Konva's, never a tool's. A double-click (or a double tap) asks the
// tool to edit. The keyboard goes to main.ts's shortcuts unless a field has the focus.
import type { Board, ToolName } from '../board';

export interface PointerInfo { x: number; y: number; sx: number; sy: number; shift: boolean; touch: boolean; button: number; id: number; time: number }
export interface Gesture { move(p: PointerInfo): void; up(p: PointerInfo): void; cancel(): void }
export interface Tool { readonly name: string; readonly cursor: string; down(p: PointerInfo): Gesture | null; dbl?(p: PointerInfo): void }

export function isTyping(target: EventTarget | null): boolean {
  const el = target as HTMLElement | null;
  if (!el || !el.tagName) return false;
  return el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || el.isContentEditable;
}

export class Input {
  private pointers = new Map<number, { sx: number; sy: number }>();
  private gesture: Gesture | null = null;
  private gesturePointer: number | null = null;
  private pinch: { d: number; mx: number; my: number } | null = null;
  private konva = new Set<number>();
  private space = false;
  private lastTap = { t: 0, sx: 0, sy: 0 };
  private downAt = { sx: 0, sy: 0, t: 0 };
  private off: (() => void)[] = [];

  constructor(private b: Board, private tools: Record<ToolName, Tool>, private onKey: (e: KeyboardEvent) => boolean) {}

  attach(): void {
    const el = this.b.view.container;
    el.style.touchAction = 'none';
    const on = <K extends keyof HTMLElementEventMap>(t: EventTarget, type: K | string, fn: (e: never) => void, opts?: AddEventListenerOptions) => {
      t.addEventListener(type, fn as EventListener, opts);
      this.off.push(() => t.removeEventListener(type, fn as EventListener, opts));
    };
    on(el, 'pointerdown', (e: PointerEvent) => this.down(e));
    on(el, 'pointermove', (e: PointerEvent) => this.move(e));
    on(el, 'pointerup', (e: PointerEvent) => this.up(e, false));
    on(el, 'pointercancel', (e: PointerEvent) => this.up(e, true));
    on(el, 'dblclick', (e: MouseEvent) => { const t = this.tools[this.b.tool()]; t.dbl?.(this.info(e as PointerEvent)); });
    on(el, 'wheel', (e: WheelEvent) => this.wheel(e), { passive: false });
    on(el, 'contextmenu', (e: Event) => e.preventDefault());
    on(window, 'keydown', (e: KeyboardEvent) => {
      if (isTyping(e.target)) return;
      if (e.key === ' ' && !this.space) { this.space = true; el.style.cursor = 'grab'; e.preventDefault(); return; }
      if (this.onKey(e)) e.preventDefault();
    });
    on(window, 'keyup', (e: KeyboardEvent) => { if (e.key === ' ') { this.space = false; el.style.cursor = this.tools[this.b.tool()].cursor; } });
    on(window, 'blur', () => { this.space = false; });
  }

  detach(): void {
    for (const f of this.off) f();
    this.off = [];
    this.gesture?.cancel();
    this.gesture = null;
  }

  /** The current gesture is dropped (a tool changed, the island is going). */
  cancel(): void {
    this.gesture?.cancel();
    this.gesture = null;
    this.gesturePointer = null;
  }

  private info(e: PointerEvent | MouseEvent): PointerInfo {
    const r = this.b.view.container.getBoundingClientRect();
    const sx = e.clientX - r.left;
    const sy = e.clientY - r.top;
    const c = this.b.view.toCanvas(sx, sy);
    const pe = e as PointerEvent;
    return { x: c.x, y: c.y, sx, sy, shift: e.shiftKey || e.metaKey || e.ctrlKey, touch: pe.pointerType === 'touch' || pe.pointerType === 'pen',
      button: e.button, id: pe.pointerId ?? 1, time: performance.now() };
  }

  private onTransformer(e: PointerEvent): boolean {
    const stage = this.b.view.stage;
    stage.setPointersPositions(e);
    const pos = stage.getPointerPosition();
    if (!pos) return false;
    const shape = this.b.renderer.selection.getIntersection(pos);
    return !!shape && (shape.hasName('_anchor') || shape.getParent() === this.b.selection.tr);
  }

  private down(e: PointerEvent): void {
    const p = this.info(e);
    this.b.view.container.focus({ preventScroll: true });
    if (this.onTransformer(e)) { this.konva.add(e.pointerId); return; }
    try { this.b.view.container.setPointerCapture(e.pointerId); } catch { /* not every pointer can be captured */ }
    this.pointers.set(e.pointerId, { sx: p.sx, sy: p.sy });
    if (this.pointers.size === 2) {
      // two fingers: whatever the first began is dropped; pinch and pan
      this.cancel();
      const [a, b] = [...this.pointers.values()];
      this.pinch = { d: Math.hypot(a.sx - b.sx, a.sy - b.sy) || 1, mx: (a.sx + b.sx) / 2, my: (a.sy + b.sy) / 2 };
      return;
    }
    if (this.pointers.size > 2) return;
    this.downAt = { sx: p.sx, sy: p.sy, t: p.time };
    const tool = e.button === 1 || this.space ? this.tools.hand : this.tools[this.b.tool()];
    if (e.button === 2) return;
    this.gesture = tool.down(p);
    this.gesturePointer = this.gesture ? e.pointerId : null;
  }

  private move(e: PointerEvent): void {
    const p = this.info(e);
    this.b.pointer = { x: p.x, y: p.y };
    this.b.live?.cursor(p.x, p.y);
    if (this.konva.has(e.pointerId)) return;
    const prev = this.pointers.get(e.pointerId);
    if (!prev) return;
    prev.sx = p.sx; prev.sy = p.sy;
    if (this.pinch && this.pointers.size >= 2) {
      const [a, b] = [...this.pointers.values()];
      const d = Math.hypot(a.sx - b.sx, a.sy - b.sy) || 1;
      const mx = (a.sx + b.sx) / 2;
      const my = (a.sy + b.sy) / 2;
      this.b.view.zoomAt(mx, my, d / this.pinch.d);
      this.b.view.panBy(mx - this.pinch.mx, my - this.pinch.my);
      this.pinch = { d, mx, my };
      return;
    }
    if (this.gesture && this.gesturePointer === e.pointerId) this.gesture.move(p);
  }

  private up(e: PointerEvent, cancelled: boolean): void {
    if (this.konva.delete(e.pointerId)) return;
    const p = this.info(e);
    this.pointers.delete(e.pointerId);
    try { this.b.view.container.releasePointerCapture(e.pointerId); } catch { /* released already */ }
    if (this.pinch) {
      if (this.pointers.size < 2) this.pinch = null;
      return;
    }
    if (this.gesture && this.gesturePointer === e.pointerId) {
      const g = this.gesture;
      this.gesture = null;
      this.gesturePointer = null;
      if (cancelled) g.cancel(); else g.up(p);
    }
    // a double tap (a finger): the tool's edit
    if (!cancelled && p.touch && Math.hypot(p.sx - this.downAt.sx, p.sy - this.downAt.sy) < 10 && p.time - this.downAt.t < 300) {
      if (p.time - this.lastTap.t < 350 && Math.hypot(p.sx - this.lastTap.sx, p.sy - this.lastTap.sy) < 24) {
        this.tools[this.b.tool()].dbl?.(p);
        this.lastTap = { t: 0, sx: 0, sy: 0 };
      } else {
        this.lastTap = { t: p.time, sx: p.sx, sy: p.sy };
      }
    }
  }

  private wheel(e: WheelEvent): void {
    e.preventDefault();
    const r = this.b.view.container.getBoundingClientRect();
    const scale = e.deltaMode === 1 ? 16 : e.deltaMode === 2 ? this.b.view.height : 1;
    if (e.ctrlKey || e.metaKey) {
      this.b.view.zoomAt(e.clientX - r.left, e.clientY - r.top, Math.exp(-e.deltaY * scale * 0.0025));
    } else {
      const dx = e.shiftKey && e.deltaX === 0 ? e.deltaY : e.deltaX;
      const dy = e.shiftKey && e.deltaX === 0 ? 0 : e.deltaY;
      this.b.view.panBy(-dx * scale, -dy * scale);
    }
  }
}
