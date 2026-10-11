// Copy, paste and duplicate (canvas-core.md "Gestures commit once": paste and duplicate are one batch of creates with new ids offset by 24;
// canvas.md divergence 3: pasting outside text makes one sticky per non-empty line, at most 50, in a grid at the pointer). Copy keeps the
// items here and puts their words on the system clipboard; a paste whose text is those words pastes the items, any other text makes
// stickies. A frame copied takes its children; a connector comes only with both its ends.
import type { Board } from '../board';
import type { Item, Op } from '../model/types';
import { DUPLICATE_OFFSET, ITEM_SIZES, LAYOUT_GAP, plainOf, runsOf, uuid } from '../model/types';
import { keyAfter } from '../model/fractional';
import { frameTopLeft, round2 } from '../model/geometry';

const MAX_LINES = 50;

export class Clipboard {
  private items: Item[] = [];
  private text = '';
  private pastes = 0;
  private pasteTimer: number | null = null;

  constructor(private b: Board) {
    document.addEventListener('copy', (e) => this.onCopy(e));
    document.addEventListener('cut', (e) => { if (this.onCopy(e)) this.deleteSelection(); });
    document.addEventListener('paste', (e) => this.onPaste(e));
  }

  /** The selection with a selected frame's children, in z order. */
  private gather(): Item[] {
    const ids = new Set(this.b.selection.list());
    for (const id of [...ids]) for (const c of this.b.store.children(id)) ids.add(c.id);
    const items = [...ids].map((id) => this.b.store.get(id)).filter((i): i is Item => !!i)
      .filter((i) => i.type !== 'connector' || (!!i.start_item_id && ids.has(i.start_item_id) && !!i.end_item_id && ids.has(i.end_item_id)));
    return items.sort((a, c) => (a.z < c.z ? -1 : a.z > c.z ? 1 : 0)).map((i) => JSON.parse(JSON.stringify(i)));
  }

  /** Keep the selection (Ctrl+C at the keyboard, or the copy event). Answers whether there was anything. */
  copy(): boolean {
    const items = this.gather();
    if (items.length === 0) return false;
    this.items = items;
    this.text = items.map(plainOf).filter((t) => t !== '').join('\n') || '​';
    this.pastes = 0;
    try { void navigator.clipboard?.writeText(this.text).catch(() => undefined); } catch { /* no clipboard API: the copy event carries it */ }
    return true;
  }

  private onCopy(e: ClipboardEvent): boolean {
    if (isField(e.target) || this.b.selection.list().length === 0) return false;
    if (!this.copy()) return false;
    e.clipboardData?.setData('text/plain', this.text);
    e.preventDefault();
    return true;
  }

  /** Ctrl+V pressed: the paste event follows when the browser has the clipboard; if it never comes, paste what was copied here. */
  keyPaste(): void {
    if (this.pasteTimer !== null) window.clearTimeout(this.pasteTimer);
    this.pasteTimer = window.setTimeout(() => { this.pasteTimer = null; if (this.items.length) this.pasteItems(); }, 120);
  }

  private onPaste(e: ClipboardEvent): void {
    if (isField(e.target) || !this.b.editable) return;
    if (this.pasteTimer !== null) { window.clearTimeout(this.pasteTimer); this.pasteTimer = null; }
    const text = e.clipboardData?.getData('text/plain') ?? '';
    e.preventDefault();
    if (this.items.length && (text === this.text || text === '')) this.pasteItems();
    else if (text.trim() !== '') this.pasteLines(text);
  }

  /** The kept items again, 24 further on each time (in the same frames, or with their copied frame). */
  pasteItems(): void {
    if (!this.items.length) return;
    this.pastes++;
    this.place(this.items, DUPLICATE_OFFSET * this.pastes);
  }

  /** Ctrl+D: the selection again, offset by 24 — the clipboard untouched. */
  duplicate(): void {
    const items = this.gather();
    if (items.length) this.place(items, DUPLICATE_OFFSET);
  }

  private place(items: Item[], offset: number): void {
    if (items.length > this.b.batchMax) { this.b.toasts.show(`Select at most ${this.b.batchMax} items to copy at once.`, { kind: 'warning' }); return; }
    const map = new Map<string, string>();
    for (const i of items) map.set(i.id, uuid());
    let z = this.b.store.highestZ();
    const ops: Op[] = [];
    for (const i of items) {
      z = keyAfter(z);
      const copied = { ...i, id: map.get(i.id)!, z, version: 1, created_by: null, updated_by: null };
      const inCopiedFrame = !!i.parent_id && map.has(i.parent_id);
      if (inCopiedFrame) copied.parent_id = map.get(i.parent_id!)!;                    // relative to its copied frame: no offset
      else if (i.parent_id && !this.b.store.has(i.parent_id)) {                         // its frame has gone: absolute again
        copied.parent_id = null;
        copied.x = i.x + offset; copied.y = i.y + offset;
      } else { copied.x = round2(i.x + offset); copied.y = round2(i.y + offset); }
      if (i.type === 'connector') { copied.start_item_id = map.get(i.start_item_id!) ?? null; copied.end_item_id = map.get(i.end_item_id!) ?? null; }
      ops.push({ op: 'create', item: copied });
    }
    this.b.commit(ops);
    // the copies at the top level are selected (a copied frame's children come with it)
    this.b.selection.set(items.filter((i) => !(i.parent_id && map.has(i.parent_id))).map((i) => map.get(i.id)!));
  }

  /** Outside text: a sticky per non-empty line (≤ 50), in a grid centred at the pointer (or the view's centre). */
  pasteLines(text: string): void {
    const lines = text.split(/\r?\n/).map((l) => l.trim()).filter((l) => l !== '').slice(0, MAX_LINES);
    if (!lines.length) return;
    const [w, h] = ITEM_SIZES.sticky_note;
    const cols = Math.ceil(Math.sqrt(lines.length));
    const rows = Math.ceil(lines.length / cols);
    const at = this.b.pointer ?? { x: this.b.view.camera.x, y: this.b.view.camera.y };
    const x0 = at.x - (cols * w + (cols - 1) * LAYOUT_GAP) / 2;
    const y0 = at.y - (rows * h + (rows - 1) * LAYOUT_GAP) / 2;
    let z = this.b.store.highestZ();
    const ops: Op[] = lines.map((line, k) => {
      z = keyAfter(z);
      const cx = x0 + (k % cols) * (w + LAYOUT_GAP) + w / 2;
      const cy = y0 + Math.floor(k / cols) * (h + LAYOUT_GAP) + h / 2;
      const frame = this.b.frameAt(cx, cy);
      const tl = frame ? frameTopLeft(frame) : { x: 0, y: 0 };
      return { op: 'create', item: { id: uuid(), type: 'sticky_note', parent_id: frame?.id ?? null, x: round2(cx - tl.x), y: round2(cy - tl.y), width: w, height: h,
        rotation: 0, z, style: { fillColor: 'light_yellow' }, data: { content: runsOf(line) }, start_item_id: null, end_item_id: null, locked: false, version: 1 } };
    });
    this.b.commit(ops);
    this.b.selection.set(ops.map((o) => (o as { item: Item }).item.id));
  }

  deleteSelection(): void {
    const ids = this.b.selection.list().filter((id) => !this.b.store.get(id)?.locked);
    if (ids.length) this.b.commit([{ op: 'delete', ids }]);
  }
}

function isField(t: EventTarget | null): boolean {
  const el = t as HTMLElement | null;
  return !!el && !!el.tagName && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable);
}
