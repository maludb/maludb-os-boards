// Text, laid out once and drawn many times (canvas-core.md "Rendering and performance"): words wrapped to a width by measureText and kept per
// (text, size, width) — the canvas draws a laid-out text with a fillText a line; a 5,000-item board never re-measures while it pans. The
// sticky's auto-fit, the shape's, the text item's height and the editor's overlay all use these same lines, so what is edited is what is
// drawn. And the text item itself: words on the canvas, no box (Miro's text) — its height follows its words.
import type { Item } from '../../model/types';
import { plainOf } from '../../model/types';
import { MIN_TEXT_PX } from '../lod';

export const FONT_FAMILY = 'Inter, "Segoe UI", system-ui, -apple-system, sans-serif';
export const TEXT_DEFAULT_SIZE = 18;
export const TEXT_PAD = 4;
export const LINE_HEIGHT = 1.25;

export interface Layout { lines: string[]; size: number; lineHeight: number; height: number; widest: number }

let measurer: CanvasRenderingContext2D | null = null;
const measureCache = new Map<string, number>();
const layoutCache = new Map<string, Layout>();

function measure(word: string, size: number): number {
  const key = `${size}|${word}`;
  const hit = measureCache.get(key);
  if (hit !== undefined) return hit;
  measurer ??= document.createElement('canvas').getContext('2d');
  measurer!.font = `${size}px ${FONT_FAMILY}`;
  const w = measurer!.measureText(word).width;
  if (measureCache.size > 50000) measureCache.clear();
  measureCache.set(key, w);
  return w;
}

/** Words wrapped to a width at a size (a word longer than the width breaks by characters); newlines kept. */
export function layoutText(text: string, size: number, width: number, lineHeight = LINE_HEIGHT): Layout {
  const key = `${size}|${width}|${lineHeight}|${text}`;
  const hit = layoutCache.get(key);
  if (hit) return hit;
  const lines: string[] = [];
  const space = measure(' ', size);
  let widest = 0;
  for (const para of text.split('\n')) {
    let line = '';
    let lw = 0;
    for (const word of para.split(/ +/)) {
      let ww = measure(word, size);
      if (ww > width) {
        // a word wider than the box: break it by characters
        if (line !== '') { lines.push(line); widest = Math.max(widest, lw); line = ''; lw = 0; }
        let part = '';
        for (const ch of word) {
          if (measure(part + ch, size) > width && part !== '') { lines.push(part); widest = Math.max(widest, measure(part, size)); part = ''; }
          part += ch;
        }
        line = part; lw = measure(part, size);
        continue;
      }
      if (line === '') { line = word; lw = ww; }
      else if (lw + space + ww <= width) { line += ' ' + word; lw += space + ww; }
      else { lines.push(line); widest = Math.max(widest, lw); line = word; lw = ww; }
      ww = 0;
    }
    lines.push(line);
    widest = Math.max(widest, lw);
  }
  const out = { lines, size, lineHeight: size * lineHeight, height: lines.length * size * lineHeight, widest };
  if (layoutCache.size > 20000) layoutCache.clear();
  layoutCache.set(key, out);
  return out;
}

const LADDER = [64, 48, 40, 36, 32, 28, 24, 20, 18, 16, 14, 12, 10, 8];

/** The largest size of the ladder whose lines fit the box (searched by halves); every word on its line. */
export function fitFontSize(text: string, width: number, height: number, pad: number, max = 64): number {
  const words = text.trim() === '' ? 'M' : text;
  const ladder = LADDER.filter((s) => s <= max);
  const w = Math.max(1, width - 2 * pad);
  const h = height - 2 * pad;
  const fits = (size: number) => {
    const l = layoutText(words, size, w, 1.2);
    return l.height <= h && words.split(/\s+/).every((x) => measure(x, size) <= w);
  };
  let lo = 0, hi = ladder.length - 1, chosen = ladder[ladder.length - 1];
  while (lo <= hi) {
    const mid = (lo + hi) >> 1;
    if (fits(ladder[mid])) { chosen = ladder[mid]; hi = mid - 1; } else lo = mid + 1;
  }
  return chosen;
}

/** Draw laid-out lines in a box (x, y, w, h), aligned and — when asked — centred vertically. */
export function drawLines(c: CanvasRenderingContext2D, l: Layout, x: number, y: number, w: number, h: number, align: string, middle: boolean, color: string): void {
  c.font = `${l.size}px ${FONT_FAMILY}`;
  c.fillStyle = color;
  c.textBaseline = 'middle';
  c.textAlign = align === 'right' ? 'right' : align === 'left' ? 'left' : 'center';
  const tx = align === 'right' ? x + w : align === 'left' ? x : x + w / 2;
  let ty = (middle ? y + (h - l.height) / 2 : y) + l.lineHeight / 2;
  for (const line of l.lines) {
    if (ty - l.lineHeight / 2 > y + h + 1) break;            // clipped by the box
    c.fillText(line, tx, ty);
    ty += l.lineHeight;
  }
}

// ---- the text item ----

export function textFontSize(item: Item): number {
  const s = Number(item.style.fontSize);
  return s > 0 ? s : TEXT_DEFAULT_SIZE;
}

/** The height a text item's words need at its width. */
export function textHeightFor(text: string, width: number, fontSize: number): number {
  return Math.ceil(layoutText(text === '' ? 'M' : text, fontSize, Math.max(1, width - 2 * TEXT_PAD)).height + 2 * TEXT_PAD);
}

/** A text item in its box [0, w] × [0, h]. */
export function drawText(c: CanvasRenderingContext2D, item: Item, zoom: number): void {
  const size = textFontSize(item);
  if (size * zoom < MIN_TEXT_PX) {
    c.fillStyle = '#c7cbd1';
    c.fillRect(0, item.height * 0.3, item.width * 0.8, Math.max(1, item.height * 0.15));
    return;
  }
  const text = plainOf(item);
  if (text === '') return;
  const l = layoutText(text, size, Math.max(1, item.width - 2 * TEXT_PAD));
  drawLines(c, l, TEXT_PAD, TEXT_PAD, item.width - 2 * TEXT_PAD, Math.max(item.height, l.height) - 2 * TEXT_PAD + 1, (item.style.textAlign as string) || 'left', false,
    (item.style.textColor as string) || '#1a1a1a');
}
