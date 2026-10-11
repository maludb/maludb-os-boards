// The text editor (canvas-core.md: "the DOM overlay editor"): a textarea laid over the item at its place, size, rotation and font, kept
// above a phone's on-screen keyboard (visualViewport). It commits ONCE when the editing ends (blur, Escape, Ctrl+Enter, a click away): one
// update of data.content with base_version — the item's version when editing began, so a text someone else wrote meanwhile comes back as
// `replaced` ("You replaced Maria's edit — Undo") — and a text item's new height. A text item left empty when it was just made goes again.
import type { Board } from '../board';
import type { Fields, Item } from '../model/types';
import { plainOf, runsOf } from '../model/types';
import { STICKY_PAD, inkOn, fillOf } from '../render/items/sticky';
import { FONT_FAMILY, TEXT_PAD, fitFontSize, textFontSize, textHeightFor } from '../render/items/text';

interface Active { id: string; ta: HTMLTextAreaElement; base: number; original: string; created: boolean }

export class TextEditor {
  private active: Active | null = null;

  constructor(private b: Board) {
    b.view.onChange(() => this.place());
    window.visualViewport?.addEventListener('resize', () => this.place());
  }

  isOpen(): boolean { return this.active !== null; }
  editing(): string | null { return this.active?.id ?? null; }

  open(id: string, created = false): void {
    if (this.active) this.close(true);
    const item = this.b.store.get(id);
    if (!item || !['sticky_note', 'shape', 'text'].includes(item.type)) return;
    const ta = document.createElement('textarea');
    ta.className = 'board-text-editor';
    ta.id = 'board-text-editor';
    ta.setAttribute('aria-label', 'Text');
    ta.value = plainOf(item);
    ta.spellcheck = true;
    this.b.mount.appendChild(ta);
    this.active = { id, ta, base: item.version, original: ta.value, created };
    this.b.renderer.hide(id, true);
    this.place();
    ta.addEventListener('input', () => this.place());
    ta.addEventListener('keydown', (e) => {
      e.stopPropagation();
      if (e.key === 'Escape' || (e.key === 'Enter' && (e.ctrlKey || e.metaKey))) { e.preventDefault(); this.close(true); }
    });
    ta.addEventListener('blur', () => { if (this.active?.ta === ta) this.close(true); });
    ta.focus({ preventScroll: true });
    ta.setSelectionRange(ta.value.length, ta.value.length);
  }

  /** Lay the textarea over the item. */
  place(): void {
    const a = this.active;
    if (!a) return;
    const item = this.b.store.get(a.id);
    if (!item) { this.close(false); return; }
    const z = this.b.view.camera.zoom;
    const c = this.b.store.absCentre(item);
    const s = this.b.view.toScreen(c.x, c.y);
    const { size, pad, align, color } = this.look(item, a.ta.value);
    let h = item.height;
    if (item.type === 'text') h = Math.max(item.height, textHeightFor(a.ta.value, item.width, size));
    const st = a.ta.style;
    st.left = `${s.x - (item.width * z) / 2}px`;
    st.top = `${s.y - (h * z) / 2}px`;
    st.width = `${item.width * z}px`;
    st.height = `${h * z}px`;
    st.padding = `${pad * z}px`;
    st.fontSize = `${size * z}px`;
    st.lineHeight = item.type === 'text' ? '1.25' : '1.2';
    st.fontFamily = FONT_FAMILY;
    st.textAlign = align;
    st.color = color;
    st.transform = item.rotation ? `rotate(${item.rotation}deg)` : '';
    // above the on-screen keyboard: keep the editor inside the visual viewport
    const vv = window.visualViewport;
    if (vv) {
      const r = a.ta.getBoundingClientRect();
      const bottom = vv.offsetTop + vv.height;
      if (r.bottom > bottom - 8) this.b.view.panBy(0, -(r.bottom - bottom + 24));
    }
  }

  private look(item: Item, text: string): { size: number; pad: number; align: string; color: string } {
    if (item.type === 'sticky_note') {
      const set = Number(item.style.fontSize);
      return { size: set > 0 ? set : fitFontSize(text, item.width, item.height, STICKY_PAD), pad: STICKY_PAD, align: (item.style.textAlign as string) || 'center', color: inkOn(fillOf(item)) };
    }
    if (item.type === 'shape') {
      const set = Number(item.style.fontSize);
      return { size: set > 0 ? set : fitFontSize(text, item.width, item.height, 10, 24), pad: 10, align: (item.style.textAlign as string) || 'center', color: (item.style.textColor as string) || '#1a1a1a' };
    }
    return { size: textFontSize(item), pad: TEXT_PAD, align: (item.style.textAlign as string) || 'left', color: (item.style.textColor as string) || '#1a1a1a' };
  }

  /** End the editing: commit the text once when it changed. */
  close(commit: boolean): void {
    const a = this.active;
    if (!a) return;
    this.active = null;
    const text = a.ta.value.replace(/\r\n/g, '\n');
    a.ta.remove();
    this.b.renderer.hide(a.id, false);
    const item = this.b.store.get(a.id);
    if (!commit || !item) return;
    if (item.type === 'text' && a.created && text.trim() === '') {
      this.b.commit([{ op: 'delete', ids: [a.id] }]);
      return;
    }
    if (text === a.original) return;
    const fields: Fields = { data: { content: runsOf(text) } };
    if (item.type === 'text') {
      const h = textHeightFor(text, item.width, textFontSize(item));
      if (h !== item.height) fields.height = h;
    }
    this.b.commit([{ op: 'update', id: a.id, fields, base_version: a.base }]);
  }
}
