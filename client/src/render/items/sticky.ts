// A sticky note (canvas.md: Miro's palette; the text auto-fits unless a size is chosen), drawn in its own box [0, w] × [0, h] — the same
// function paints it in a batch and in its own node when it is selected (render/layers.ts), so the two never differ.
import type { Item } from '../../model/types';
import { STICKY_COLORS, plainOf } from '../../model/types';
import { MIN_TEXT_PX } from '../lod';
import { drawLines, fitFontSize, layoutText } from './text';

export const STICKY_PAD = 14;

export function fillOf(item: Item): string {
  const c = item.style.fillColor as string | undefined;
  if (!c) return STICKY_COLORS.light_yellow;
  return STICKY_COLORS[c] ?? (/^#[0-9a-f]{6}$/i.test(c) ? c : STICKY_COLORS.light_yellow);
}

export function inkOn(fill: string): string {
  const h = fill.replace('#', '');
  const r = parseInt(h.slice(0, 2), 16), g = parseInt(h.slice(2, 4), 16), b = parseInt(h.slice(4, 6), 16);
  return (0.299 * r + 0.587 * g + 0.114 * b) < 110 ? '#ffffff' : '#1a1a1a';
}

export function stickyFontSize(item: Item): number {
  const set = Number(item.style.fontSize);
  return set > 0 ? set : fitFontSize(plainOf(item), item.width, item.height, STICKY_PAD);
}

export function drawSticky(c: CanvasRenderingContext2D, item: Item, zoom: number): void {
  const fill = fillOf(item);
  c.fillStyle = fill;
  c.fillRect(0, 0, item.width, item.height);
  if (fill === '#ffffff' || fill === STICKY_COLORS.gray) {
    c.strokeStyle = '#d0d4d9';
    c.lineWidth = 1 / zoom;
    c.strokeRect(0, 0, item.width, item.height);
  }
  const text = plainOf(item);
  if (text === '') return;
  const size = stickyFontSize(item);
  if (size * zoom < MIN_TEXT_PX) return;
  const w = Math.max(1, item.width - 2 * STICKY_PAD);
  drawLines(c, layoutText(text, size, w, 1.2), STICKY_PAD, STICKY_PAD, w, item.height - 2 * STICKY_PAD, (item.style.textAlign as string) || 'center', true, inkOn(fill));
}
