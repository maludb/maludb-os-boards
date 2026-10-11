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
      if (btn.id === 'board-tool-image') { this.penMenu(false); this.menu(false); b.pickFile(); return; }
      if (btn.dataset.penColor) {
        if (b.tool() === 'highlighter') b.highlighterColor = btn.dataset.penColor; else { b.pen.color = btn.dataset.penColor; if (b.tool() !== 'pen') b.setTool('pen'); }
        this.markPen();
        return;
      }
      if (btn.dataset.penWidth) { b.pen.width = Number(btn.dataset.penWidth); if (b.tool() !== 'pen') b.setTool('pen'); this.markPen(); return; }
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
      if (tool === 'shape') { this.penMenu(false); this.menu(!this.menuOpen()); b.setTool('shape'); return; }
      this.menu(false);
      if (tool === 'pen' || tool === 'highlighter') {
        const again = b.tool() === tool;
        b.setTool(tool);
        this.penMenu(again ? !this.penMenuOpen() : true);
        this.markPen();
        return;
      }
      this.penMenu(false);
      b.setTool(tool);
    });
    document.addEventListener('pointerdown', (e) => {
      const t = e.target as HTMLElement;
      if (!t.closest('#board-shape-menu, #board-tool-shape')) this.menu(false);
      if (!t.closest('#board-pen-menu, #board-tool-pen, #board-tool-highlighter')) this.penMenu(false);
    });
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

  private penMenuOpen(): boolean { return !document.getElementById('board-pen-menu')?.classList.contains('d-none'); }
  private penMenu(open: boolean): void { document.getElementById('board-pen-menu')?.classList.toggle('d-none', !open); }
  /** The pen menu shows the active tool's colour (and the pen's width; the highlighter has one). */
  private markPen(): void {
    const hl = this.b.tool() === 'highlighter';
    const colour = hl ? this.b.highlighterColor : this.b.pen.color;
    document.querySelectorAll<HTMLButtonElement>('#board-pen-menu [data-pen-color]').forEach((x) => x.classList.toggle('active', x.dataset.penColor === colour));
    document.querySelectorAll<HTMLButtonElement>('#board-pen-menu [data-pen-width]').forEach((x) => { x.classList.toggle('active', Number(x.dataset.penWidth) === this.b.pen.width); x.hidden = hl; });
  }

  private menuOpen(): boolean { return !document.getElementById('board-shape-menu')?.classList.contains('d-none'); }
  private menu(open: boolean): void { document.getElementById('board-shape-menu')?.classList.toggle('d-none', !open); }
  private markShape(): void {
    this.root?.querySelectorAll<HTMLButtonElement>('[data-shape]').forEach((btn) => btn.classList.toggle('active', btn.dataset.shape === this.b.shapeKind));
  }
}
