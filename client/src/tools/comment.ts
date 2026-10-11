// Comment (Shift+C): a click (a tap) on an item or on the empty canvas starts a thread there — the rail opens at the new-thread form with the
// anchor (the item and the offset from its top-right corner, or the point) and the current selection as `context[]`; the pin appears once the
// thread is posted. A commenter has this tool (the database refuses a comment below `comment`); it writes no item, so it makes no batch.
// CommentsController is the island's side of the comment rail: opening a thread from a pin, a link or `?comment=`.
import type { Board } from '../board';
import { plainOf } from '../model/types';
import { round2 } from '../model/geometry';
import type { Gesture, PointerInfo, Tool } from './input';
import { tapToPlace } from './sticky';
import { hitTest } from './select';
import type { PinLayer } from '../render/pins';
import type { PinSync } from '../sync/pins';
import type { RailBridge } from '../ui/rail';

export class CommentTool implements Tool {
  readonly name = 'comment';
  readonly cursor = 'crosshair';
  constructor(private b: Board) {}
  down(p: PointerInfo): Gesture {
    return tapToPlace(this.b, p, (x, y) => { this.b.comments?.startThread(x, y); this.b.setTool('select'); });
  }
}

const sleep = (ms: number) => new Promise<void>((r) => window.setTimeout(r, ms));

export class CommentsController {
  private off: (() => void)[] = [];

  constructor(private b: Board, private layer: PinLayer, private sync: PinSync, private rail: RailBridge) {
    const body = document.body;
    const onChanged = (e: Event) => {
      // the new-thread form's own post: reset and close it, drop the marker
      if ((e.target as HTMLElement | null)?.closest?.('#comment-new-form')) this.closeNew(true);
    };
    const onClick = (e: Event) => {
      if ((e.target as HTMLElement).closest('#comment-new-cancel')) { e.preventDefault(); this.closeNew(true); }
    };
    body.addEventListener('commentChanged', onChanged);
    body.addEventListener('click', onClick);
    this.off.push(() => body.removeEventListener('commentChanged', onChanged), () => body.removeEventListener('click', onClick));
  }

  destroy(): void { for (const f of this.off) f(); this.off = []; }

  /** Show the rail's Comments panel (the phone's sheet, the desktop's rail un-hidden) and wait for a marker element of it. */
  private async panel(marker: string): Promise<HTMLElement | null> {
    document.getElementById('board-page')?.classList.remove('rail-hidden');
    window.dispatchEvent(new Event('resize'));
    if (!document.querySelector(marker)) this.rail.sheet(true, 'comments'); else this.rail.sheet(true);
    // a phone's sheet is 40 % of the screen: a composer wants the tall one (the handle takes it back)
    if (window.matchMedia('(max-width: 991.98px)').matches) document.getElementById('board-rail')?.classList.add('tall');
    for (let i = 0; i < 80; i++) {
      const el = document.querySelector<HTMLElement>(marker);
      if (el) return el;
      await sleep(50);
    }
    return null;
  }

  /** A new thread at a canvas point: on the item under it (the offset from its top-right corner kept) or at the point. */
  startThread(x: number, y: number): void {
    const b = this.b;
    const hit = hitTest(b, x, y);
    const box = hit ? b.store.box(hit.id) : null;
    const anchor = hit && box ? { item: hit.id, x: round2(x - box.x2), y: round2(y - box.y1) } : { item: null as string | null, x: round2(x), y: round2(y) };
    this.layer.setPending({ x, y, item: hit?.id ?? null });
    const where = hit ? (plainOf(hit).trim() ? `on '${plainOf(hit).trim().replace(/\s+/g, ' ').slice(0, 60)}'` : `on ${hit.type.replace('_', ' ')}`) : 'at a point';
    void this.panel('#comment-new').then((form) => {
      if (!form) return;
      const f = document.getElementById('comment-new-form') as HTMLFormElement | null;
      if (!f) return;
      (f.elements.namedItem('item') as HTMLInputElement).value = anchor.item ?? '';
      (f.elements.namedItem('x') as HTMLInputElement).value = String(anchor.x);
      (f.elements.namedItem('y') as HTMLInputElement).value = String(anchor.y);
      f.querySelectorAll('input[name="context[]"]').forEach((i) => i.remove());
      const ctx = new Set(b.selection.list());
      if (hit) ctx.add(hit.id);
      for (const id of ctx) {
        const h = document.createElement('input');
        h.type = 'hidden'; h.name = 'context[]'; h.value = id;
        f.appendChild(h);
      }
      const w = document.getElementById('comment-new-where');
      if (w) w.textContent = `New comment ${where}`;
      form.classList.remove('d-none');
      (document.getElementById('comment-form-field-text') as HTMLTextAreaElement | null)?.focus();
    });
  }

  private closeNew(reset: boolean): void {
    this.layer.setPending(null);
    const form = document.getElementById('comment-new');
    form?.classList.add('d-none');
    if (reset) (document.getElementById('comment-new-form') as HTMLFormElement | null)?.reset();
  }

  /** A thread in the rail (from a pin, a link, `?comment=`); the pin is marked. */
  async openThread(id: string): Promise<void> {
    this.layer.focus(id);
    const pane = document.getElementById('board-rail-panel');
    if (pane) pane.dataset.openComment = id;
    if (document.getElementById('comment-rail')) {
      document.getElementById('board-page')?.classList.remove('rail-hidden');
      window.dispatchEvent(new Event('resize'));
      this.rail.sheet(true);
      document.body.dispatchEvent(new CustomEvent('boards:open-comment', { detail: { id } }));
    } else {
      await this.panel('#comment-rail');
    }
  }

  /** `?comment=` (the thread's root): fly to its pin and open it. A resolved thread's pin is read for the purpose. */
  async focus(id: string): Promise<void> {
    let pin = this.layer.find(id);
    if (!pin) {
      await this.sync.refresh(true);
      pin = this.layer.find(id);
    }
    if (pin) {
      const c = this.layer.canvasPos(pin);
      this.b.view.flyTo({ x: c.x, y: c.y, zoom: Math.max(this.b.view.camera.zoom, 0.8) }, 250);
    }
    await this.openThread(id);
  }
}
