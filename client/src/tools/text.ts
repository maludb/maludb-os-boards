// Text (T): a click places a text item 200 wide and opens the editor; left empty, it goes again when the editor closes.
import type { Board } from '../board';
import type { Gesture, PointerInfo, Tool } from './input';
import { placeItem, tapToPlace } from './sticky';

export class TextTool implements Tool {
  readonly name = 'text';
  readonly cursor = 'text';
  constructor(private b: Board) {}
  down(p: PointerInfo): Gesture {
    return tapToPlace(this.b, p, (x, y) => placeItem(this.b, 'text', x, y, { style: { textColor: '#1a1a1a' } }));
  }
}
