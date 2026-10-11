// Export (templates-export.md, canvas-core.md "Exports"): the board menu's PNG entries — the view, the selection, a frame — made HERE from the store with the canvas's own draw functions
// and downloaded through a temporary <a download>, then POST /boards/{board}/export (format=png&scope=…) so the server can log it. The SVG entries are plain links to the server's door (the database's one
// renderer); this file only points the frame one at the right frame and greys what has nothing to export. Drawn into a canvas of its own — never off the stage: the stage shows what the camera shows
// (culled, flat below 40 %), an export holds the whole box at full detail: a selection or a frame at two pixels to a canvas unit whatever the zoom, the view at twice what the screen shows.
import type { Board } from '../board';
import type { Box, Item } from '../model/types';
import { drawItem } from '../render/layers';
import { drawConnector } from '../render/items/connector';
import { sortedByZ } from '../render/cull';
import { LOD_ZOOM } from '../render/lod';
import { union, grow } from '../model/geometry';

const MAX_SIDE = 4096;
const PIXEL_RATIO = 2;
const PAD = 24;

type Scope = 'view' | 'selection' | 'frame';

export class Exports {
  private menu = document.getElementById('board-menu');

  constructor(private b: Board, private csrf: string, private background: { color?: string }) {
    if (!this.menu) return;
    this.menu.addEventListener('click', (e) => {
      const el = (e.target as HTMLElement).closest('[data-export]') as HTMLElement | null;
      if (!el) return;
      if (el.classList.contains('disabled')) { e.preventDefault(); e.stopPropagation(); return; }
      if (el.tagName === 'A') return;                       // an SVG link: the browser downloads it from the server's door
      e.preventDefault();
      const scope = el.dataset.export!.replace(/^png-/, '') as Scope;
      void this.png(scope);
    });
    const refresh = () => this.refresh();
    this.menu.parentElement?.addEventListener('show.bs.dropdown', refresh);
    document.getElementById('board-menu-btn')?.addEventListener('click', refresh);
  }

  /** The frame an export of "a frame" means: the selected frame, else the one under the view's centre. */
  targetFrame(): Item | null {
    for (const id of this.b.selection.list()) {
      const it = this.b.store.get(id);
      if (it?.type === 'frame') return it;
    }
    return this.b.frameAt(this.b.view.camera.x, this.b.view.camera.y);
  }

  /** Grey what has nothing to export and point the SVG-of-the-frame link at its frame. */
  refresh(): void {
    const set = (id: string, on: boolean) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.classList.toggle('disabled', !on);
      el.setAttribute('aria-disabled', on ? 'false' : 'true');
    };
    set('export-png-selection', this.b.selection.list().length > 0);
    const f = this.targetFrame();
    set('export-png-frame', !!f);
    set('export-svg-frame', !!f);
    const link = document.getElementById('export-svg-frame') as HTMLAnchorElement | null;
    if (link) link.href = f ? `/boards/${this.b.boardId}/export?format=svg&frame=${encodeURIComponent(f.id)}` : '#';
  }

  /** The ids drawn for a set of roots: each, a frame's children and the connectors with both ends in the set. */
  private collect(roots: string[]): Set<string> {
    const s = this.b.store;
    const ids = new Set<string>();
    for (const id of roots) {
      const it = s.get(id);
      if (!it) continue;
      ids.add(id);
      if (it.type === 'frame') for (const c of s.children(id)) ids.add(c.id);
    }
    for (const it of s.items.values()) {
      if (it.type !== 'connector' || ids.has(it.id)) continue;
      if (it.start_item_id && it.end_item_id && ids.has(it.start_item_id) && ids.has(it.end_item_id)) ids.add(it.id);
    }
    return ids;
  }

  /** The box and the ids a scope exports, or null (with the sentence) when there is nothing. */
  private plan(scope: Scope): { box: Box; ids: Set<string>; label: string; perUnit: number } | { error: string } {
    const s = this.b.store;
    if (scope === 'view') {
      const box = this.b.view.viewport();
      const ids = new Set<string>();
      for (const it of s.items.values()) {
        const bx = s.box(it.id);
        if (bx && bx.x1 <= box.x2 && bx.x2 >= box.x1 && bx.y1 <= box.y2 && bx.y2 >= box.y1) ids.add(it.id);
      }
      return { box, ids, label: 'view', perUnit: PIXEL_RATIO * this.b.view.camera.zoom };          // what the screen shows, at twice its pixels
    }
    if (scope === 'selection') {
      const roots = this.b.selection.list().filter((id) => s.has(id));
      if (!roots.length) return { error: 'Select something to export.' };
      const ids = this.collect(roots);
      let box: Box | null = null;
      for (const id of ids) { const bx = s.box(id); if (bx) box = union(box, bx); }
      return box ? { box: grow(box, PAD), ids, label: 'selection', perUnit: PIXEL_RATIO } : { error: 'Select something to export.' };
    }
    const f = this.targetFrame();
    if (!f) return { error: 'There is no frame to export: select one, or put one in the middle of the view.' };
    const box = s.box(f.id)!;
    return { box, ids: this.collect([f.id]), label: String(f.data.title || 'Untitled frame'), perUnit: PIXEL_RATIO };
  }

  /** Draw a box of the board into a canvas at $perUnit pixels to a canvas unit (smaller when the box is huge: at most 4096 px a side). */
  render(box: Box, ids: Set<string>, perUnit: number): HTMLCanvasElement {
    const s = this.b.store;
    const w = Math.max(1, box.x2 - box.x1);
    const h = Math.max(1, box.y2 - box.y1);
    const scale = Math.min(perUnit, MAX_SIDE / w, MAX_SIDE / h);
    const cv = document.createElement('canvas');
    cv.width = Math.max(1, Math.ceil(w * scale));
    cv.height = Math.max(1, Math.ceil(h * scale));
    const c = cv.getContext('2d')!;
    c.fillStyle = this.background.color || '#ffffff';
    c.fillRect(0, 0, cv.width, cv.height);
    c.save();
    c.scale(scale, scale);
    c.translate(-box.x1, -box.y1);
    const zoom = Math.max(scale, LOD_ZOOM);                  // full detail whatever the size of the box
    const { frames, items } = sortedByZ(s);
    for (const it of [...frames, ...items]) {
      if (!ids.has(it.id)) continue;
      if (it.type === 'connector') { drawConnector(c, it, zoom, (id) => s.box(id)); continue; }
      const p = s.absCentre(it);
      c.save();
      c.translate(p.x, p.y);
      if (it.type !== 'frame' && it.rotation) c.rotate((it.rotation * Math.PI) / 180);
      c.translate(-it.width / 2, -it.height / 2);
      drawItem(c, it, zoom);
      c.restore();
    }
    c.restore();
    return cv;
  }

  async png(scope: Scope): Promise<void> {
    const p = this.plan(scope);
    if ('error' in p) { this.b.toasts.show(p.error, { kind: 'warning', id: 'board-toast-export' }); return; }
    const blob = await new Promise<Blob | null>((r) => this.render(p.box, p.ids, p.perUnit).toBlob(r, 'image/png'));
    if (!blob) { this.b.toasts.show('The PNG could not be made.', { kind: 'danger', id: 'board-toast-export' }); return; }
    const title = (document.getElementById('board-title')?.textContent || 'Board').trim();
    const name = `${title} — ${p.label}.png`.replace(/[\\/:*?"<>|\u0000-\u001f]+/g, '_');
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
    const form = new URLSearchParams({ board: this.b.boardId, format: 'png', scope, items: String(p.ids.size), csrf_token: this.csrf });
    void fetch(`/boards/${this.b.boardId}/export`, { method: 'POST', credentials: 'same-origin', body: form,
      headers: { Accept: 'application/json', 'X-CSRF-Token': this.csrf } }).catch(() => undefined);
  }
}
