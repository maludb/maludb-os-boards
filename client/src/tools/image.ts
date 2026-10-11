// Images and files onto the board (slice 5; pen-files.md "Upload", "The canvas item"): from the toolbar's Image button (a file picker), dropped
// onto the canvas, or pasted. Each file goes to POST /files/upload (multipart; the door mints the item's id and makes the thumbnails); the
// answer's id is then committed as ONE create — an `image`, or a `document` for any other file (and for an image too large to preview) —
// centred at the drop point (the pointer for a paste, else the view's centre), at its size scaled into 800 × 800 (a file card 320 × 120), in a
// frame when its centre lands in one. Several files are placed side by side. A refusal is the door's sentence in a toast; nothing is placed.
import type { Board } from '../board';
import type { Item } from '../model/types';
import { keyAfter } from '../model/fractional';
import { frameTopLeft, round2 } from '../model/geometry';
import { CARD_SIZE } from '../render/items/document';

export const ACCEPT = 'image/png,image/jpeg,image/gif,image/webp,image/svg+xml,application/pdf,.docx,.xlsx,.pptx,.odt,.ods,.odp,.txt,.csv,.md,.zip';
const FIT = 800;
const GAP = 24;

export interface Uploaded { item_id: string; attachment_id: number; kind: 'image' | 'document'; mime_type: string; filename: string; byte_size: number;
  width: number | null; height: number | null; preview: boolean }

/** The size an upload lands at: its own scaled into 800 × 800 (never enlarged); a file card 320 × 120. */
export function landingSize(u: Uploaded): [number, number] {
  const previewed = u.kind === 'image' || u.preview;
  if (!previewed || !u.width || !u.height) return CARD_SIZE;
  const k = Math.min(1, FIT / u.width, FIT / u.height);
  return [round2(Math.max(1, u.width * k)), round2(Math.max(1, u.height * k))];
}

export class Uploader {
  private input: HTMLInputElement;

  constructor(private b: Board, private url: string, private csrf: string, private maxMb: number) {
    this.input = document.createElement('input');
    this.input.type = 'file';
    this.input.multiple = true;
    this.input.accept = ACCEPT;
    this.input.id = 'board-file-input';
    this.input.className = 'd-none';
    this.input.addEventListener('change', () => {
      const files = [...(this.input.files ?? [])];
      this.input.value = '';
      if (files.length) void this.upload(files, null);
    });
    b.mount.appendChild(this.input);
    const el = b.view.container;
    el.addEventListener('dragover', (e) => {
      if (!b.editable || !e.dataTransfer || ![...e.dataTransfer.types].includes('Files')) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'copy';
      el.classList.add('board-drop-target');
    });
    el.addEventListener('dragleave', () => el.classList.remove('board-drop-target'));
    el.addEventListener('drop', (e) => {
      el.classList.remove('board-drop-target');
      const files = [...(e.dataTransfer?.files ?? [])];
      if (!files.length || !b.editable) return;
      e.preventDefault();
      const r = el.getBoundingClientRect();
      void this.upload(files, b.view.toCanvas(e.clientX - r.left, e.clientY - r.top));
    });
  }

  /** The toolbar's Image button: the system's file picker. */
  pick(): void { if (this.b.editable) this.input.click(); }

  /** Files from the clipboard: at the pointer, else the view's centre. */
  paste(files: File[]): void { if (files.length) void this.upload(files, this.b.pointer); }

  /** Upload each file in turn and place it; answers the items placed (the proofs wait on it). */
  async upload(files: File[], at: { x: number; y: number } | null): Promise<string[]> {
    const b = this.b;
    if (!b.editable) return [];
    const placed: string[] = [];
    let cursor = at ?? { x: b.view.camera.x, y: b.view.camera.y };
    let prevHalf = 0;
    for (const f of files) {
      if (f.size > this.maxMb * 1024 * 1024) { b.toasts.show(`Boards keeps files up to ${this.maxMb} MB.`, { kind: 'danger', id: 'board-toast-upload' }); continue; }
      const note = b.toasts.show(`Uploading ${f.name}…`, { id: 'board-toast-uploading', ms: 120000 });
      const form = new FormData();
      form.append('board', b.boardId);
      form.append('csrf_token', this.csrf);
      form.append('file', f, f.name);
      let res: Response | null = null;
      try { res = await fetch(this.url, { method: 'POST', body: form, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': this.csrf } }); } catch { res = null; }
      note?.remove();
      const j = res ? await res.json().catch(() => null) : null;
      if (!res || !res.ok || !j?.item_id) {
        const why = j?.errors?.[0] ?? j?.error?.message ?? (res?.status === 413 ? `Boards keeps files up to ${this.maxMb} MB.` : `${f.name} could not be uploaded.`);
        b.toasts.show(why, { kind: 'danger', id: 'board-toast-upload' });
        continue;
      }
      const u = j as Uploaded;
      const [w, h] = landingSize(u);
      if (placed.length) cursor = { x: cursor.x + prevHalf + GAP + w / 2, y: cursor.y };
      prevHalf = w / 2;
      const frame = b.frameAt(cursor.x, cursor.y);
      const tl = frame ? frameTopLeft(frame) : { x: 0, y: 0 };
      const item: Item = { id: u.item_id, type: u.kind, parent_id: frame?.id ?? null, x: round2(cursor.x - tl.x), y: round2(cursor.y - tl.y), width: w, height: h,
        rotation: 0, z: keyAfter(b.store.highestZ()), style: {}, data: { attachment_id: u.attachment_id, title: u.filename, mime_type: u.mime_type,
          byte_size: u.byte_size, preview: u.preview }, start_item_id: null, end_item_id: null, locked: false, version: 1 };
      if (b.commit([{ op: 'create', item }])) placed.push(u.item_id);
    }
    if (placed.length) b.selection.set(placed);
    return placed;
  }
}
