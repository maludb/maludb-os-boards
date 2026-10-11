// Shape (S): one of Miro's ten (the toolbar's menu sets which). A click places it at 200 × 120; a mouse drag draws its box; a finger taps
// to place (a drag pans). White with a dark outline; its text is edited like a sticky's.
import Konva from 'konva';
import type { Board } from '../board';
import { boxFromPoints } from '../model/geometry';
import type { Gesture, PointerInfo, Tool } from './input';
import { placeItem, tapToPlace } from './sticky';

export class ShapeTool implements Tool {
  readonly name = 'shape';
  readonly cursor = 'crosshair';
  constructor(private b: Board) {}

  down(p: PointerInfo): Gesture {
    const b = this.b;
    const style = { fillColor: '#ffffff', borderColor: '#1a1a1a' };
    if (p.touch) return tapToPlace(b, p, (x, y) => placeItem(b, 'shape', x, y, { style, data: { shape: b.shapeKind } }));
    const z = b.view.camera.zoom;
    const ghost = new Konva.Rect({ x: p.x, y: p.y, width: 0, height: 0, stroke: '#3454d1', strokeWidth: 1 / z, dash: [4 / z, 3 / z], listening: false });
    b.renderer.selection.add(ghost);
    let box = boxFromPoints(p.x, p.y, p.x, p.y);
    let dragged = false;
    return {
      move: (q) => {
        if (Math.hypot(q.sx - p.sx, q.sy - p.sy) > 8) dragged = true;
        box = boxFromPoints(p.x, p.y, q.x, q.y);
        ghost.setAttrs({ x: box.x1, y: box.y1, width: box.x2 - box.x1, height: box.y2 - box.y1 });
        b.renderer.request();
      },
      up: () => {
        ghost.destroy();
        const w = box.x2 - box.x1;
        const h = box.y2 - box.y1;
        if (dragged && w >= 20 && h >= 20) placeItem(b, 'shape', (box.x1 + box.x2) / 2, (box.y1 + box.y2) / 2, { width: Math.round(w), height: Math.round(h), style, data: { shape: b.shapeKind } });
        else placeItem(b, 'shape', p.x, p.y, { style, data: { shape: b.shapeKind } });
      },
      cancel: () => { ghost.destroy(); b.renderer.request(); },
    };
  }
}
