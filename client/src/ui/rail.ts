// The event bridge between the island and the HTMX page (canvas-core.md "Events the island fires / listens for"): the island fires
// boards:select, boards:changed, boards:open-item, boards:live, boards:level on the mount (they bubble; HTMX panels listen with
// `hx-trigger="… from:body"`) and stamps the selection and the view on #page-content for the command bar; it listens on document.body for
// boards:focus, boards:command (the rail's buttons — each becomes ONE batch here), boards:reload. The rail's own chrome — its tabs, the
// desktop toggle, the phone's sheet (40 % / 90 %) — is wired here too: no other script is on the page.
import type { Board } from '../board';

export interface CommandDetail { kind: string; ids?: string[]; key?: string; value?: string; types?: string[]; x?: number; y?: number }

export class RailBridge {
  private rail = document.getElementById('board-rail');
  private pageContent = document.getElementById('page-content');
  private viewTimer: number | null = null;

  constructor(private b: Board, handlers: { command(d: CommandDetail): void; focus(d: { id?: string; x?: number; y?: number; zoom?: number; fit?: boolean }): void; reload(): void }) {
    const body = document.body;
    const on = <T>(type: string, fn: (d: T) => void) => body.addEventListener(type, (e) => fn(((e as CustomEvent).detail ?? {}) as T));
    on('boards:command', handlers.command);
    on('boards:focus', handlers.focus);
    on('boards:reload', handlers.reload);
    // the rail's buttons → boards:command; an outline line → fly there
    this.rail?.addEventListener('click', (e) => {
      const t = e.target as HTMLElement;
      const cmd = t.closest('[data-board-command]') as HTMLElement | null;
      if (cmd) {
        e.preventDefault();
        const d: CommandDetail = { kind: cmd.dataset.boardCommand!, ids: b.selection.list() };
        if (cmd.dataset.key) d.key = cmd.dataset.key;
        if (cmd.dataset.value !== undefined) d.value = cmd.dataset.value;
        if (cmd.dataset.types) d.types = cmd.dataset.types.split(',');
        if (cmd.dataset.x) d.x = Number(cmd.dataset.x);
        if (cmd.dataset.y) d.y = Number(cmd.dataset.y);
        body.dispatchEvent(new CustomEvent('boards:command', { detail: d }));
        return;
      }
      const line = t.closest('a.outline-item[data-item]') as HTMLAnchorElement | null;
      if (line) {
        e.preventDefault();
        body.dispatchEvent(new CustomEvent('boards:focus', { detail: { id: line.dataset.item, fit: line.dataset.fit === '1' } }));
        if (window.matchMedia('(max-width: 991.98px)').matches) this.sheet(false);
        return;
      }
      const tab = t.closest('.board-rail-tab') as HTMLElement | null;
      if (tab) {
        this.rail?.querySelectorAll('.board-rail-tab').forEach((x) => { x.classList.toggle('active', x === tab); x.setAttribute('aria-selected', x === tab ? 'true' : 'false'); });
      }
    });
    // a field of the Properties panel (a frame's title): its change is ONE batch, like a button's
    this.rail?.addEventListener('change', (e) => {
      const input = (e.target as HTMLElement).closest('[data-board-input]') as HTMLInputElement | null;
      if (!input) return;
      body.dispatchEvent(new CustomEvent('boards:command', { detail: { kind: input.dataset.boardInput, key: input.dataset.key, value: input.value,
        types: input.dataset.types ? input.dataset.types.split(',') : undefined, ids: b.selection.list() } }));
    });
    document.getElementById('board-rail-toggle')?.addEventListener('click', (e) => {
      const hidden = document.getElementById('board-page')?.classList.toggle('rail-hidden');
      (e.currentTarget as HTMLElement).setAttribute('aria-expanded', hidden ? 'false' : 'true');
      window.dispatchEvent(new Event('resize'));
    });
    document.getElementById('board-rail-close')?.addEventListener('click', () => this.sheet(false));
    // a phone's way to the panel without selecting anything: the board's menu → Frames (slice 4)
    document.querySelectorAll<HTMLElement>('[data-open-panel]').forEach((el) => el.addEventListener('click', () => this.sheet(true, el.dataset.openPanel)));
    document.getElementById('board-rail-handle')?.addEventListener('click', () => this.rail?.classList.toggle('tall'));
    b.view.onChange(() => this.stampView());
  }

  /** Open or close the phone's sheet (on a desktop the rail is always beside the canvas unless hidden). */
  sheet(open: boolean, panel?: string): void {
    this.rail?.classList.toggle('open', open);
    if (open && panel) (document.getElementById(`board-rail-tab-${panel}`) as HTMLElement | null)?.click();
  }

  select(ids: string[]): void {
    if (this.pageContent) this.pageContent.dataset.selection = ids.join(',');
    const items = ids.map((id) => this.b.store.get(id)).filter(Boolean).map((i) => ({ id: i!.id, type: i!.type }));
    this.fire('boards:select', { ids, items });
  }

  changed(detail: { seq: number; source: string; member_id: number | null; counts?: Record<string, number> }): void {
    this.fire('boards:changed', detail);
  }

  fire(name: string, detail: unknown): void {
    this.b.mount.dispatchEvent(new CustomEvent(name, { detail, bubbles: true }));
  }

  private stampView(): void {
    if (this.viewTimer !== null) return;
    this.viewTimer = window.setTimeout(() => {
      this.viewTimer = null;
      const c = this.b.view.camera;
      if (this.pageContent) this.pageContent.dataset.view = `${Math.round(c.x)} ${Math.round(c.y)} ${Math.round(c.zoom * 1000) / 1000}`;
    }, 250);
  }
}
