// The navigator (slice 4; connectors-frames.md): the board's frames in reading order — top to bottom, then left to right, the order of
// bd_board_overview() and the rail's Frames panel — and Prev / Next in the bottom bar, each flying to a frame and fitting it.
import type { Board } from '../board';
import type { Item } from '../model/types';

export class Navigator {
  private at = -1;

  constructor(private b: Board) {
    document.getElementById('board-frame-prev')?.addEventListener('click', () => this.step(-1));
    document.getElementById('board-frame-next')?.addEventListener('click', () => this.step(1));
  }

  /** The frames in reading order (by the top of their box, then its left). */
  frames(): Item[] {
    return this.b.store.all().filter((i) => i.type === 'frame').sort((a, c) => {
      const ab = this.b.store.box(a.id)!, cb = this.b.store.box(c.id)!;
      return ab.y1 - cb.y1 || ab.x1 - cb.x1 || (a.id < c.id ? -1 : 1);
    });
  }

  /** Fly to a frame and fit it, with a margin. */
  fit(id: string): void {
    const box = this.b.store.box(id);
    if (!box) return;
    this.b.view.flyTo(this.b.view.cameraFor({ x1: box.x1, y1: box.y1 - 30, x2: box.x2, y2: box.y2 }, 40, 4));
    const k = this.frames().findIndex((f) => f.id === id);
    if (k >= 0) this.at = k;
  }

  step(dir: 1 | -1): void {
    const frames = this.frames();
    if (!frames.length) { this.b.toasts.show('There are no frames on this board yet.', { id: 'board-toast-no-frames' }); return; }
    this.at = this.at < 0 ? (dir > 0 ? 0 : frames.length - 1) : (this.at + dir + frames.length) % frames.length;
    this.fit(frames[this.at].id);
  }
}
