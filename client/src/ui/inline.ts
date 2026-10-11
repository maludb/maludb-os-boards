// The one-line editors (slice 4): a frame's title, renamed in place above its top-left, and a connector's caption, typed at the middle of its
// route. An <input> laid over the canvas; Enter or a click away commits ONE update (`data.title`, or `data.captions` — empty clears it), Escape
// leaves it as it was.
import type { Board } from '../board';
import { TITLE_PX, TITLE_GAP_PX } from '../render/items/frame';
import { captionOf, pointAlong, routeOf } from '../render/items/connector';
import { FONT_FAMILY } from '../render/items/text';

export class InlineEditor {
  private input: HTMLInputElement | null = null;
  private id: string | null = null;

  constructor(private b: Board) {
    b.view.onChange(() => this.place());
  }

  isOpen(): boolean { return this.input !== null; }

  open(id: string): void {
    this.close(true);
    const it = this.b.store.get(id);
    if (!it || (it.type !== 'frame' && it.type !== 'connector')) return;
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'board-inline-editor';
    input.id = it.type === 'frame' ? 'board-frame-title-editor' : 'board-caption-editor';
    input.maxLength = it.type === 'frame' ? 120 : 200;
    input.value = it.type === 'frame' ? String(it.data.title ?? '') : captionOf(it);
    input.placeholder = it.type === 'frame' ? 'Untitled frame' : 'Caption';
    input.setAttribute('aria-label', it.type === 'frame' ? "The frame's title" : "The connector's caption");
    this.b.mount.appendChild(input);
    this.input = input;
    this.id = id;
    this.place();
    input.addEventListener('keydown', (e) => {
      e.stopPropagation();
      if (e.key === 'Enter') { e.preventDefault(); this.close(true); }
      if (e.key === 'Escape') { e.preventDefault(); this.close(false); }
    });
    input.addEventListener('blur', () => { if (this.input === input) this.close(true); });
    input.focus({ preventScroll: true });
    input.select();
  }

  private place(): void {
    if (!this.input || !this.id) return;
    const it = this.b.store.get(this.id);
    if (!it) { this.close(false); return; }
    const z = this.b.view.camera.zoom;
    const st = this.input.style;
    if (it.type === 'frame') {
      const tl = this.b.view.toScreen(it.x - it.width / 2, it.y - it.height / 2);
      st.left = `${tl.x}px`;
      st.top = `${tl.y - TITLE_PX - TITLE_GAP_PX - 10}px`;
      st.width = `${Math.max(160, Math.min(it.width * z, 420))}px`;
      st.fontSize = `${TITLE_PX}px`;
      st.fontWeight = '600';
    } else {
      const r = routeOf(it, this.b.renderer.boxOf);
      if (!r) return;
      const at = pointAlong(r, 0.5);
      const s = this.b.view.toScreen(at.x, at.y);
      st.left = `${s.x - 90}px`;
      st.top = `${s.y - 16}px`;
      st.width = '180px';
      st.fontSize = `${Math.max(12, 14 * z)}px`;
      st.fontWeight = '400';
      st.textAlign = 'center';
    }
    st.fontFamily = FONT_FAMILY;
  }

  close(commit: boolean): void {
    const input = this.input;
    const id = this.id;
    if (!input || !id) return;
    this.input = null;
    this.id = null;
    const value = input.value.trim();
    input.remove();
    const it = this.b.store.get(id);
    if (!commit || !it) return;
    if (it.type === 'frame') {
      if (value !== String(it.data.title ?? '')) this.b.commit([{ op: 'update', id, fields: { data: { title: value } } }]);
    } else if (value !== captionOf(it)) {
      this.b.commit([{ op: 'update', id, fields: { data: { captions: value === '' ? [] : [{ content: value, position: 0.5 }] } } }]);
    }
  }
}
