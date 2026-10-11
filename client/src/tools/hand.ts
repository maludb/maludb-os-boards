// Hand (H, Space + drag, the middle button): the board follows the pointer.
import type { Board } from '../board';
import type { Gesture, PointerInfo, Tool } from './input';
import { panGesture } from './select';

export class HandTool implements Tool {
  readonly name = 'hand';
  readonly cursor = 'grab';
  constructor(private b: Board) {}
  down(p: PointerInfo): Gesture { return panGesture(this.b, p); }
}
