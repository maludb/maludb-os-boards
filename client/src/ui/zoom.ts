// The bottom bar (canvas.md): zoom −/+ about the viewport's centre, the percentage (back to 100 %), Fit (the board's extent), Undo/Redo of
// my changes.
import type { Board } from '../board';

const STEPS = [0.05, 0.1, 0.15, 0.25, 0.33, 0.5, 0.67, 0.8, 1, 1.25, 1.5, 2, 3, 4, 6, 8];

export class ZoomBar {
  private label = document.getElementById('board-zoom-label');
  private undoBtn = document.getElementById('board-undo') as HTMLButtonElement | null;
  private redoBtn = document.getElementById('board-redo') as HTMLButtonElement | null;

  constructor(private b: Board, fit: () => void) {
    const v = b.view;
    const step = (dir: 1 | -1) => {
      const z = v.camera.zoom;
      const next = dir > 0 ? STEPS.find((s) => s > z * 1.01) ?? 8 : [...STEPS].reverse().find((s) => s < z * 0.99) ?? 0.05;
      v.flyTo({ ...v.camera, zoom: next }, 160);
    };
    document.getElementById('board-zoom-in')?.addEventListener('click', () => step(1));
    document.getElementById('board-zoom-out')?.addEventListener('click', () => step(-1));
    this.label?.addEventListener('click', () => v.flyTo({ ...v.camera, zoom: 1 }, 160));
    document.getElementById('board-zoom-fit')?.addEventListener('click', fit);
    this.undoBtn?.addEventListener('click', () => { void b.undo.undo(); });
    this.redoBtn?.addEventListener('click', () => { void b.undo.redo(); });
    v.onChange(() => this.show());
    this.show();
  }

  show(): void {
    if (this.label) this.label.textContent = `${Math.round(this.b.view.camera.zoom * 100)}%`;
  }

  undoState(): void {
    if (this.undoBtn) this.undoBtn.disabled = !this.b.undo.canUndo();
    if (this.redoBtn) this.redoBtn.disabled = !this.b.undo.canRedo();
  }
}
