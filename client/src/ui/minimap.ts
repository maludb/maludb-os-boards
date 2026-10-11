// The minimap (slice 4; canvas-core client/src/ui/minimap.ts): the board's extent in miniature with the viewport outlined, 160 × 120 at the
// bottom right (hidden at 375 px — the Frames panel is the way around a phone, decision 6). The items are drawn into a cached picture when
// the board changes (at most every 250 ms); a camera move redraws only the viewport's outline. A click or a drag moves the view there.
import type { Board } from '../board';
import type { Box } from '../model/types';
import { fillOf } from '../render/items/sticky';

const W = 160, H = 120;

export class Minimap {
  private canvas = document.getElementById('board-minimap-canvas') as HTMLCanvasElement | null;
  private picture: HTMLCanvasElement | null = null;
  private world: Box = { x1: -500, y1: -400, x2: 500, y2: 400 };
  private timer: number | null = null;
  private frame: number | null = null;

  constructor(private b: Board) {
    if (!this.canvas) return;
    const pr = Math.min(window.devicePixelRatio || 1, 2);
    this.canvas.width = W * pr;
    this.canvas.height = H * pr;
    this.canvas.style.width = `${W}px`;
    this.canvas.style.height = `${H}px`;
    b.store.subscribe(() => this.soon());
    b.view.onChange(() => this.drawSoon());
    const go = (e: PointerEvent) => {
      const r = this.canvas!.getBoundingClientRect();
      const s = this.scale();
      const x = this.world.x1 + (e.clientX - r.left - s.ox) / s.k;
      const y = this.world.y1 + (e.clientY - r.top - s.oy) / s.k;
      this.b.view.setCamera({ ...this.b.view.camera, x, y });
    };
    this.canvas.addEventListener('pointerdown', (e) => { e.preventDefault(); this.canvas!.setPointerCapture(e.pointerId); go(e); });
    this.canvas.addEventListener('pointermove', (e) => { if (this.canvas!.hasPointerCapture(e.pointerId)) go(e); });
    this.soon();
  }

  /** The world → minimap scale and offset (the world fitted, centred). */
  private scale(): { k: number; ox: number; oy: number } {
    const w = Math.max(1, this.world.x2 - this.world.x1), h = Math.max(1, this.world.y2 - this.world.y1);
    const k = Math.min((W - 8) / w, (H - 8) / h);
    return { k, ox: (W - w * k) / 2, oy: (H - h * k) / 2 };
  }

  private soon(): void {
    if (this.timer !== null) return;
    this.timer = window.setTimeout(() => { this.timer = null; this.paint(); this.draw(); }, 250);
  }

  private drawSoon(): void {
    if (this.frame !== null) return;
    this.frame = requestAnimationFrame(() => { this.frame = null; this.draw(); });
  }

  /** The items, once, into a picture: frames white with a border, stickies their colour, the rest grey. */
  private paint(): void {
    const ext = this.b.store.extent();
    const pad = 200;
    this.world = ext ? { x1: ext.x1 - pad, y1: ext.y1 - pad, x2: ext.x2 + pad, y2: ext.y2 + pad } : { x1: -500, y1: -400, x2: 500, y2: 400 };
    const pr = this.canvas!.width / W;
    const pic = this.picture ?? document.createElement('canvas');
    pic.width = this.canvas!.width;
    pic.height = this.canvas!.height;
    const g = pic.getContext('2d')!;
    g.setTransform(pr, 0, 0, pr, 0, 0);
    g.clearRect(0, 0, W, H);
    const s = this.scale();
    const items = this.b.store.all().sort((a, c) => (a.type === 'frame' ? 0 : 1) - (c.type === 'frame' ? 0 : 1));
    for (const it of items) {
      if (it.type === 'connector') continue;
      const bx = this.b.store.box(it.id);
      if (!bx) continue;
      const x = s.ox + (bx.x1 - this.world.x1) * s.k, y = s.oy + (bx.y1 - this.world.y1) * s.k;
      const w = Math.max(1, (bx.x2 - bx.x1) * s.k), h = Math.max(1, (bx.y2 - bx.y1) * s.k);
      if (it.type === 'frame') { g.fillStyle = '#ffffff'; g.fillRect(x, y, w, h); g.strokeStyle = '#adb5bd'; g.lineWidth = 0.5; g.strokeRect(x, y, w, h); }
      else { g.fillStyle = it.type === 'sticky_note' ? fillOf(it) : '#adb5bd'; g.fillRect(x, y, w, h); }
    }
    this.picture = pic;
  }

  /** The picture and the viewport's outline. */
  private draw(): void {
    if (!this.canvas || !this.picture) return;
    const pr = this.canvas.width / W;
    const g = this.canvas.getContext('2d')!;
    g.setTransform(1, 0, 0, 1, 0, 0);
    g.clearRect(0, 0, this.canvas.width, this.canvas.height);
    g.drawImage(this.picture, 0, 0);
    g.setTransform(pr, 0, 0, pr, 0, 0);
    const s = this.scale();
    const v = this.b.view.viewport();
    const x = s.ox + (v.x1 - this.world.x1) * s.k, y = s.oy + (v.y1 - this.world.y1) * s.k;
    g.strokeStyle = '#3454d1';
    g.lineWidth = 1.5;
    g.strokeRect(Math.max(0.75, x), Math.max(0.75, y), Math.min(W - 1.5, (v.x2 - v.x1) * s.k), Math.min(H - 1.5, (v.y2 - v.y1) * s.k));
  }
}
