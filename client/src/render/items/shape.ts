// A shape with text (canvas.md: Miro's ten basic shapes, by its names): the outline traced for its kind, filled and stroked, the text inside
// — drawn in its own box [0, w] × [0, h].
import type { Item } from '../../model/types';
import { plainOf } from '../../model/types';
import { MIN_TEXT_PX } from '../lod';
import { drawLines, fitFontSize, layoutText } from './text';

export const SHAPE_KINDS = ['rectangle', 'round_rectangle', 'ellipse', 'triangle', 'diamond', 'parallelogram', 'hexagon', 'star', 'right_arrow',
  'wedge_round_rectangle_callout'] as const;
const PAD = 10;

/** Trace a kind's outline in a w × h box whose top-left is (x, y). */
export function tracePath(ctx: CanvasRenderingContext2D, kind: string, x: number, y: number, w: number, h: number): void {
  const poly = (pts: number[][]) => { ctx.moveTo(x + pts[0][0] * w, y + pts[0][1] * h); for (const p of pts.slice(1)) ctx.lineTo(x + p[0] * w, y + p[1] * h); ctx.closePath(); };
  ctx.beginPath();
  switch (kind) {
    case 'ellipse': ctx.ellipse(x + w / 2, y + h / 2, w / 2, h / 2, 0, 0, Math.PI * 2); break;
    case 'triangle': poly([[0.5, 0], [1, 1], [0, 1]]); break;
    case 'diamond': poly([[0.5, 0], [1, 0.5], [0.5, 1], [0, 0.5]]); break;
    case 'parallelogram': poly([[0.2, 0], [1, 0], [0.8, 1], [0, 1]]); break;
    case 'hexagon': poly([[0.25, 0], [0.75, 0], [1, 0.5], [0.75, 1], [0.25, 1], [0, 0.5]]); break;
    case 'right_arrow': poly([[0, 0.28], [0.6, 0.28], [0.6, 0], [1, 0.5], [0.6, 1], [0.6, 0.72], [0, 0.72]]); break;
    case 'star': {
      const pts: number[][] = [];
      for (let i = 0; i < 10; i++) {
        const r = i % 2 === 0 ? 0.5 : 0.2;
        const a = -Math.PI / 2 + (i * Math.PI) / 5;
        pts.push([0.5 + r * Math.cos(a), 0.52 + r * Math.sin(a) * 1.05]);
      }
      poly(pts);
      break;
    }
    case 'round_rectangle':
    case 'wedge_round_rectangle_callout': {
      const bodyH = kind === 'round_rectangle' ? h : h * 0.8;
      const r = Math.min(w, bodyH) * 0.15;
      ctx.moveTo(x + r, y);
      ctx.lineTo(x + w - r, y); ctx.quadraticCurveTo(x + w, y, x + w, y + r);
      ctx.lineTo(x + w, y + bodyH - r); ctx.quadraticCurveTo(x + w, y + bodyH, x + w - r, y + bodyH);
      if (kind === 'wedge_round_rectangle_callout') { ctx.lineTo(x + w * 0.35, y + bodyH); ctx.lineTo(x + w * 0.2, y + h); ctx.lineTo(x + w * 0.22, y + bodyH); }
      ctx.lineTo(x + r, y + bodyH); ctx.quadraticCurveTo(x, y + bodyH, x, y + bodyH - r);
      ctx.lineTo(x, y + r); ctx.quadraticCurveTo(x, y, x + r, y);
      ctx.closePath();
      break;
    }
    default: poly([[0, 0], [1, 0], [1, 1], [0, 1]]);
  }
}

export function shapeKind(item: Item): string {
  return SHAPE_KINDS.includes(item.data.shape as typeof SHAPE_KINDS[number]) ? String(item.data.shape) : 'rectangle';
}

export function shapeFontSize(item: Item): number {
  const set = Number(item.style.fontSize);
  const textH = shapeKind(item) === 'wedge_round_rectangle_callout' ? item.height * 0.8 : item.height;
  return set > 0 ? set : fitFontSize(plainOf(item), item.width, textH, PAD, 24);
}

export function drawShape(c: CanvasRenderingContext2D, item: Item, zoom: number): void {
  const kind = shapeKind(item);
  tracePath(c, kind, 0, 0, item.width, item.height);
  c.fillStyle = (item.style.fillColor as string) || '#ffffff';
  c.fill();
  c.strokeStyle = (item.style.borderColor as string) || '#1a1a1a';
  c.lineWidth = Number(item.style.borderWidth) || 2;
  c.stroke();
  const text = plainOf(item);
  if (text === '') return;
  const size = shapeFontSize(item);
  if (size * zoom < MIN_TEXT_PX) return;
  const textH = kind === 'wedge_round_rectangle_callout' ? item.height * 0.8 : item.height;
  const w = Math.max(1, item.width - 2 * PAD);
  drawLines(c, layoutText(text, size, w, 1.2), PAD, PAD, w, textH - 2 * PAD, (item.style.textAlign as string) || 'center', true, (item.style.textColor as string) || '#1a1a1a');
}
