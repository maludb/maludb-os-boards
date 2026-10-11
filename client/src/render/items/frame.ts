// A frame (slice 4; connectors-frames.md): its fill and border in its own box [0, w] × [0, h], below every other item, and its title above
// its top-left at a constant size on screen (Miro's look) — the title is where a frame is picked up and renamed (render/layers.ts draws frames
// first; tools/select.ts hits the title). Frames neither nest nor rotate (the database refuses both).
import type { Box, Item } from '../../model/types';
import { FONT_FAMILY } from './text';

export const TITLE_PX = 13;
export const TITLE_GAP_PX = 6;

export function frameTitle(item: Item): string {
  const t = String(item.data.title ?? '').trim();
  return t === '' ? 'Untitled frame' : t;
}

export function drawFrame(c: CanvasRenderingContext2D, item: Item, zoom: number): void {
  const fill = (item.style.fillColor as string) || '#ffffff';
  const dark = fill.toLowerCase() === '#212529';
  c.fillStyle = fill;
  c.fillRect(0, 0, item.width, item.height);
  c.strokeStyle = dark ? '#495057' : '#c9ced4';
  c.lineWidth = 1 / zoom;
  c.strokeRect(0, 0, item.width, item.height);
  c.font = `600 ${TITLE_PX / zoom}px ${FONT_FAMILY}`;
  c.fillStyle = '#495057';
  c.textAlign = 'left';
  c.textBaseline = 'bottom';
  const title = frameTitle(item);
  let shown = title;
  const max = item.width;
  if (c.measureText(shown).width > max) {
    while (shown.length > 1 && c.measureText(shown + '…').width > max) shown = shown.slice(0, -1);
    shown += '…';
  }
  c.fillText(shown, 0, -TITLE_GAP_PX / zoom);
}

/** Where a frame's title is drawn (absolute), for picking a frame up and renaming it. */
export function titleBox(item: Item, tl: { x: number; y: number }, zoom: number): Box {
  return { x1: tl.x, y1: tl.y - (TITLE_PX + TITLE_GAP_PX + 6) / zoom, x2: tl.x + Math.min(item.width, Math.max(80, frameTitle(item).length * 8) / zoom), y2: tl.y };
}
