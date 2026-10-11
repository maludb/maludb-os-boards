// The toolbar (canvas.md; canvas-core.md "375 px and touch"): the server renders the buttons (app/views/boards/partials/toolbar.php) —
// Select, Hand, and for an editor Sticky, Shape (with Miro's ten in its menu) and Text; later tools disabled with their slice; at 375 px a
// bottom bar whose "More" shows the rest. This binds them to the island's tool and keeps one pressed.
import type { Board, ToolName } from '../board';

export class Toolbar {
  constructor(private b: Board, private root: HTMLElement | null) {
    if (!root) return;
    root.addEventListener('click', (e) => {
      const btn = (e.target as HTMLElement).closest('button') as HTMLButtonElement | null;
      if (!btn || btn.disabled) return;
      if (btn.id === 'board-tool-more') {
        const open = root.classList.toggle('show-later');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        return;
      }
      const shape = btn.dataset.shape;
      if (shape) {
        b.shapeKind = shape;
        this.menu(false);
        b.setTool('shape');
        this.markShape();
        return;
      }
      const tool = btn.dataset.tool as ToolName | undefined;
      if (!tool) return;
      if (tool === 'shape') { this.menu(!this.menuOpen()); b.setTool('shape'); return; }
      this.menu(false);
      b.setTool(tool);
    });
    document.addEventListener('pointerdown', (e) => { if (!(e.target as HTMLElement).closest('#board-shape-menu, #board-tool-shape')) this.menu(false); });
    this.markShape();
  }

  /** Show which tool is on. */
  show(tool: ToolName): void {
    this.root?.querySelectorAll<HTMLButtonElement>('[data-tool]').forEach((btn) => {
      const on = btn.dataset.tool === tool;
      btn.classList.toggle('active', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
  }

  private menuOpen(): boolean { return !document.getElementById('board-shape-menu')?.classList.contains('d-none'); }
  private menu(open: boolean): void { document.getElementById('board-shape-menu')?.classList.toggle('d-none', !open); }
  private markShape(): void {
    this.root?.querySelectorAll<HTMLButtonElement>('[data-shape]').forEach((btn) => btn.classList.toggle('active', btn.dataset.shape === this.b.shapeKind));
  }
}
