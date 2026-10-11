// Level of detail (canvas-core.md "Rendering and performance"): below zoom 0.4 stickies and shapes are flat rectangles without text, a
// layer's run of them painted in one pass (drawFlats); text is drawn only when its on-screen size is at least 6 px. Slice 5's drawings are a
// plain polyline there, and its images and files their 256 thumbnail (render/items/image.ts chooses the size by the item's width on screen).
import type { Item } from '../model/types';
import { fillOf } from './items/sticky';

export const LOD_ZOOM = 0.4;
export const MIN_TEXT_PX = 6;

export function lodActive(zoom: number): boolean {
  return zoom < LOD_ZOOM;
}

/** Whether text of this size is drawn at this zoom. */
export function textShown(fontSize: number, zoom: number): boolean {
  return fontSize * zoom >= MIN_TEXT_PX;
}

/** One item flat in its own box [0, w] × [0, h] (a selected item's node at low zoom). */
export function drawFlatLocal(c: CanvasRenderingContext2D, item: Item, zoom: number): void {
  const st = flatStyle(item);
  if (st.fill) { c.fillStyle = st.fill; c.fillRect(0, 0, item.width, item.height); }
  if (st.stroke) { c.strokeStyle = st.stroke; c.lineWidth = 1 / zoom; c.strokeRect(0, 0, item.width, item.height); }
}

/**
 * The low zoom's batch: every flat item of a layer painted by ONE Konva.Shape straight onto the canvas (no node a item — at 5,000 items the
 * per-node cost is the frame). The colour is set only when it changes.
 */
export function drawFlats(c: CanvasRenderingContext2D, items: Item[], centre: (i: Item) => { x: number; y: number }, zoom: number, hidden: Set<string>): void {
  let fill = '';
  let stroke = '';
  c.lineWidth = 1 / zoom;
  for (const it of items) {
    if (hidden.has(it.id)) continue;
    const p = centre(it);
    const st = flatStyle(it);
    const w = it.width, h = it.height;
    const rot = it.type === 'frame' ? 0 : it.rotation;
    let x = p.x - w / 2, y = p.y - h / 2;
    if (rot) { c.save(); c.translate(p.x, p.y); c.rotate((rot * Math.PI) / 180); x = -w / 2; y = -h / 2; }
    if (st.fill) { if (st.fill !== fill) { c.fillStyle = st.fill; fill = st.fill; } c.fillRect(x, y, w, h); }
    if (st.stroke) { if (st.stroke !== stroke) { c.strokeStyle = st.stroke; stroke = st.stroke; } c.strokeRect(x, y, w, h); }
    if (rot) { c.restore(); fill = ''; stroke = ''; }
  }
}

function flatStyle(item: Item): { fill?: string; stroke?: string } {
  switch (item.type) {
    case 'sticky_note': return { fill: fillOf(item) };
    case 'shape': return { fill: (item.style.fillColor as string) || '#ffffff', stroke: (item.style.borderColor as string) || '#1a1a1a' };
    case 'text': return { fill: '#c7cbd1' };
    case 'frame': return { fill: '#ffffff', stroke: '#b0b4ba' };
    default: return { fill: '#e9ecef', stroke: '#adb5bd' };
  }
}
