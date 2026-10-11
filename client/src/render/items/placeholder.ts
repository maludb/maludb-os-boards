// What slice 2 does not draw yet (canvas.md divergence 2): a frame, connector, drawing, image or document that exists — an agent's, a later
// slice's fixture — is a grey box labelled with its type, selectable, movable and deletable. Slices 4 and 5 replace these renderers with
// their own draw functions of the same shape. A frame is drawn below every other item, with its title in its band.
import type { Item } from '../../model/types';
import { MIN_TEXT_PX } from '../lod';
import { FONT_FAMILY } from './text';

export function drawPlaceholder(c: CanvasRenderingContext2D, item: Item, zoom: number): void {
  const frame = item.type === 'frame';
  const w = Math.max(1, item.width), h = Math.max(1, item.height);
  c.fillStyle = frame ? ((item.style.fillColor as string) || '#ffffff') : '#f1f3f5';
  c.fillRect(0, 0, w, h);
  c.strokeStyle = frame ? '#b0b4ba' : '#adb5bd';
  c.lineWidth = 1 / zoom;
  if (!frame) c.setLineDash([6 / zoom, 4 / zoom]);
  c.strokeRect(0, 0, w, h);
  if (!frame) c.setLineDash([]);
  const size = frame ? 18 : 14;
  if (size * zoom < MIN_TEXT_PX) return;
  c.font = `${frame ? 'bold ' : ''}${size}px ${FONT_FAMILY}`;
  c.fillStyle = '#6c757d';
  c.textBaseline = 'middle';
  c.textAlign = frame ? 'left' : 'center';
  c.fillText(frame ? (String(item.data.title ?? '') || 'Frame') : item.type, frame ? 12 : w / 2, frame ? 16 + size * 0.65 : h / 2, Math.max(1, w - 24));
}
