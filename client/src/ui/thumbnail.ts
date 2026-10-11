// The cards' preview (canvas-core.md "The doors": POST /boards/{board}/thumbnail, a PNG ≤ 512 kB, at most 480 × 300, at most one a board
// every five minutes): an editor's island draws the board's extent into a 480 × 300 canvas — every item as its colour and box, the way the
// low-zoom view draws it — a few seconds after a change, never sooner than five minutes after the last (the door answers 429 anyway).
import type { Board } from '../board';
import { STICKY_COLORS } from '../model/types';
import { fillOf } from '../render/items/sticky';

const W = 480;
const H = 300;
const EVERY = 300_000;
const SETTLE = 8000;

export class Thumbnails {
  private timer: number | null = null;
  private lastPost: number;
  posted = 0;

  constructor(private b: Board, private url: string, private csrf: string, private background: { color?: string }, ageSeconds: number | null) {
    this.lastPost = ageSeconds === null ? 0 : Date.now() - ageSeconds * 1000;
  }

  /** A change of mine landed: post a preview once things settle, if five minutes have passed since the last. */
  changed(): void {
    if (!this.b.editable) return;
    if (this.timer !== null) window.clearTimeout(this.timer);
    const wait = Math.max(SETTLE, this.lastPost + EVERY - Date.now());
    this.timer = window.setTimeout(() => { this.timer = null; void this.post(); }, wait);
  }

  /** Draw the board's extent at 480 × 300. */
  render(): HTMLCanvasElement {
    const c = document.createElement('canvas');
    c.width = W; c.height = H;
    const g = c.getContext('2d')!;
    g.fillStyle = this.background.color || '#ffffff';
    g.fillRect(0, 0, W, H);
    const ext = this.b.store.extent();
    if (!ext) return c;
    const pad = 12;
    const s = Math.min((W - 2 * pad) / Math.max(1, ext.x2 - ext.x1), (H - 2 * pad) / Math.max(1, ext.y2 - ext.y1), 1);
    const ox = (W - (ext.x2 - ext.x1) * s) / 2 - ext.x1 * s;
    const oy = (H - (ext.y2 - ext.y1) * s) / 2 - ext.y1 * s;
    const items = this.b.store.all().sort((a, b) => (a.type === 'frame' ? -1 : 0) - (b.type === 'frame' ? -1 : 0) || (a.z < b.z ? -1 : a.z > b.z ? 1 : 0));
    for (const it of items) {
      const p = this.b.store.absCentre(it);
      g.save();
      g.translate(ox + p.x * s, oy + p.y * s);
      if (it.type !== 'frame' && it.rotation) g.rotate((it.rotation * Math.PI) / 180);
      const w = Math.max(1, it.width * s);
      const h = Math.max(1, it.height * s);
      if (it.type === 'sticky_note') { g.fillStyle = fillOf(it); g.fillRect(-w / 2, -h / 2, w, h); }
      else if (it.type === 'shape') { g.fillStyle = (it.style.fillColor as string) || '#ffffff'; g.strokeStyle = (it.style.borderColor as string) || '#1a1a1a'; g.lineWidth = 1; g.fillRect(-w / 2, -h / 2, w, h); g.strokeRect(-w / 2, -h / 2, w, h); }
      else if (it.type === 'text') { g.fillStyle = '#868e96'; for (let y = -h / 2 + 2; y < h / 2 - 1; y += 4) g.fillRect(-w / 2, y, w * 0.8, 1.5); }
      else if (it.type === 'frame') { g.fillStyle = (it.style.fillColor as string) || '#ffffff'; g.strokeStyle = '#adb5bd'; g.fillRect(-w / 2, -h / 2, w, h); g.strokeRect(-w / 2, -h / 2, w, h); }
      else { g.fillStyle = STICKY_COLORS.gray; g.fillRect(-w / 2, -h / 2, w, h); }
      g.restore();
    }
    return c;
  }

  async post(): Promise<number> {
    const blob = await new Promise<Blob | null>((r) => this.render().toBlob(r, 'image/png'));
    if (!blob) return 0;
    try {
      const res = await fetch(this.url, { method: 'POST', credentials: 'same-origin', body: blob,
        headers: { 'Content-Type': 'image/png', Accept: 'application/json', 'X-CSRF-Token': this.csrf } });
      if (res.status === 200) { this.lastPost = Date.now(); this.posted++; }
      else if (res.status === 429) this.lastPost = Date.now() - EVERY + Number(res.headers.get('Retry-After') ?? 300) * 1000;
      return res.status;
    } catch {
      return 0;
    }
  }
}
