// Comment pins (comments-notifications.md "The canvas"): one round avatar per thread, a DOM overlay over the stage — not a Konva node, so a
// pin is a real button (focusable, labelled, tapped at 375 px) and the island's gestures never see it. A thread on an item sits at the item's
// top-right corner plus the stored offset and FOLLOWS the item as it moves (the position is derived from the store on every change); a thread
// at a point sits at the point. Resolved threads are hidden unless the rail's "Show resolved" is on. Pins that share a spot fan out by 12 px.
import type { Board } from '../board';

export interface Pin {
  id: string; item_id: string | null; x: number; y: number;
  author_id: number | null; author_name: string; author_kind: string; replies: number; resolved: boolean;
}

export class PinLayer {
  readonly layer: HTMLElement;
  private pins: Pin[] = [];
  private els = new Map<string, HTMLButtonElement>();
  private offsets = new Map<string, { dx: number; dy: number }>();
  private pendingEl: HTMLElement | null = null;
  private pending: { x: number; y: number; item: string | null } | null = null;
  private focused: string | null = null;
  private raf: number | null = null;

  constructor(private b: Board, private onOpen: (id: string) => void) {
    this.layer = document.createElement('div');
    this.layer.className = 'board-pins';
    this.layer.id = 'board-pins';
    b.mount.appendChild(this.layer);
    b.view.onChange(() => this.invalidate());
    b.store.subscribe(() => this.invalidate());
    this.layer.addEventListener('click', (e) => {
      const btn = (e.target as HTMLElement).closest('button.board-pin[data-pin]') as HTMLElement | null;
      if (btn) { e.preventDefault(); this.onOpen(btn.dataset.pin!); }
    });
  }

  /** The pins as the server answered them (one per thread). */
  set(pins: Pin[]): void {
    this.pins = pins;
    this.offsets.clear();
    for (const p of pins) {
      const box = p.item_id ? this.b.store.box(p.item_id) : null;
      if (box) this.offsets.set(p.id, { dx: p.x - box.x2, dy: p.y - box.y1 });
    }
    const keep = new Set(pins.map((p) => p.id));
    for (const [id, el] of this.els) if (!keep.has(id)) { el.remove(); this.els.delete(id); }
    for (const p of pins) {
      let el = this.els.get(p.id);
      if (!el) {
        el = document.createElement('button');
        el.type = 'button';
        el.dataset.pin = p.id;
        el.id = `pin-${p.id}`;
        this.layer.appendChild(el);
        this.els.set(p.id, el);
      }
      el.className = `board-pin${p.author_kind === 'agent' ? ' board-pin-agent' : ''}${p.resolved ? ' board-pin-resolved' : ''}${this.focused === p.id ? ' board-pin-focused' : ''}`;
      el.setAttribute('aria-label', `${p.resolved ? 'Resolved comment' : 'Comment'} by ${p.author_name}${p.replies ? `, ${p.replies} ${p.replies === 1 ? 'reply' : 'replies'}` : ''}`);
      el.title = `${p.author_name}${p.replies ? ` · ${p.replies} ${p.replies === 1 ? 'reply' : 'replies'}` : ''}`;
      el.innerHTML = '';
      const face = document.createElement('span');
      face.className = 'board-pin-face';
      face.textContent = (p.author_name.trim()[0] ?? '?').toUpperCase();
      el.appendChild(face);
      if (p.replies > 0) {
        const n = document.createElement('span');
        n.className = 'board-pin-count';
        n.textContent = String(p.replies);
        el.appendChild(n);
      }
    }
    this.invalidate();
  }

  ids(): string[] { return this.pins.map((p) => p.id); }
  find(id: string): Pin | undefined { return this.pins.find((p) => p.id === id); }

  /** Where a pin sits on the canvas now (an item's pin follows the item). */
  canvasPos(p: Pin): { x: number; y: number } {
    const box = p.item_id ? this.b.store.box(p.item_id) : null;
    const off = this.offsets.get(p.id);
    return box && off ? { x: box.x2 + off.dx, y: box.y1 + off.dy } : { x: p.x, y: p.y };
  }

  focus(id: string | null): void {
    this.focused = id;
    for (const [pid, el] of this.els) el.classList.toggle('board-pin-focused', pid === id);
  }

  /** A marker where a new thread is being written (until it is posted or cancelled). */
  setPending(at: { x: number; y: number; item: string | null } | null): void {
    this.pending = at;
    if (!at) { this.pendingEl?.remove(); this.pendingEl = null; return; }
    if (!this.pendingEl) {
      this.pendingEl = document.createElement('div');
      this.pendingEl.className = 'board-pin board-pin-new';
      this.pendingEl.id = 'board-pin-new';
      this.pendingEl.innerHTML = '<span class="board-pin-face">+</span>';
      this.layer.appendChild(this.pendingEl);
    }
    this.invalidate();
  }

  invalidate(): void {
    if (this.raf !== null) return;
    this.raf = requestAnimationFrame(() => { this.raf = null; this.place(); });
  }

  /** Put every pin where it belongs (and hide the ones off the screen). */
  private place(): void {
    const v = this.b.view;
    const seen = new Map<string, number>();
    for (const p of this.pins) {
      const el = this.els.get(p.id);
      if (!el) continue;
      const c = this.canvasPos(p);
      const s = v.toScreen(c.x, c.y);
      const key = `${Math.round(s.x / 6)},${Math.round(s.y / 6)}`;
      const k = seen.get(key) ?? 0;
      seen.set(key, k + 1);
      const x = s.x + k * 12;
      const on = x > -20 && x < v.width + 20 && s.y > -20 && s.y < v.height + 20;
      el.style.display = on ? '' : 'none';
      el.style.transform = `translate(${Math.round(x)}px, ${Math.round(s.y)}px) translate(-50%, -100%)`;
    }
    if (this.pending && this.pendingEl) {
      const s = v.toScreen(this.pending.x, this.pending.y);
      this.pendingEl.style.transform = `translate(${Math.round(s.x)}px, ${Math.round(s.y)}px) translate(-50%, -100%)`;
    }
  }

  destroy(): void {
    if (this.raf !== null) cancelAnimationFrame(this.raf);
    this.layer.remove();
  }
}
