// A drawing (slice 5; pen-files.md "The data and the rules"): `data.points` = [x0, y0, x1, y1, …] relative to the item's top-left (rounded to
// 0.1), `data.tool` pen | highlighter, `style` = {strokeColor, strokeWidth}; width and height are the points' bounds + half the stroke on each
// side. A drawing the Transformer resized is drawn scaled: its points' own box (their extent + the stroke) maps onto the item's box. The line
// is Konva's `tension 0.4` curve, computed here so a batch, a selected node and the pen's preview draw the same stroke; the highlighter
// multiplies at 40 %. Below the low zoom the stroke is a plain polyline (no curve).
import type { Item } from '../../model/types';

export const TENSION = 0.4;
export const HIGHLIGHTER_ALPHA = 0.4;

export function pointsOf(item: Item): number[] {
  const p = item.data.points;
  return Array.isArray(p) ? (p as unknown[]).map(Number).filter(Number.isFinite) : [];
}

export function strokeWidthOf(item: Item): number {
  const w = Number(item.style.strokeWidth);
  return Number.isFinite(w) && w > 0 ? w : 4;
}

/** The scale a resized drawing is drawn at: the points' own box (their extent + the stroke) onto the item's width and height. */
export function drawingScale(item: Item, pts = pointsOf(item)): { sx: number; sy: number } {
  const sw = strokeWidthOf(item);
  let mx = 0, my = 0;
  for (let k = 0; k + 1 < pts.length; k += 2) { if (pts[k] > mx) mx = pts[k]; if (pts[k + 1] > my) my = pts[k + 1]; }
  const nw = mx + sw / 2, nh = my + sw / 2;
  return { sx: nw > 0 ? item.width / nw : 1, sy: nh > 0 ? item.height / nh : 1 };
}

/** Konva's control points for a tension curve through three points. */
function controls(x0: number, y0: number, x1: number, y1: number, x2: number, y2: number, t: number): [number, number, number, number] {
  const d01 = Math.hypot(x1 - x0, y1 - y0), d12 = Math.hypot(x2 - x1, y2 - y1);
  const s = d01 + d12 || 1;
  const fa = (t * d01) / s, fb = (t * d12) / s;
  return [x1 - fa * (x2 - x0), y1 - fa * (y2 - y0), x1 + fb * (x2 - x0), y1 + fb * (y2 - y0)];
}

/** The stroke's path on a context: a tension curve (Konva.Line's), or straight segments when `plain`. */
export function tracePath(c: CanvasRenderingContext2D, pts: number[], plain: boolean, t = TENSION): void {
  const n = pts.length / 2;
  c.beginPath();
  if (n === 0) return;
  c.moveTo(pts[0], pts[1]);
  if (n === 1) { c.lineTo(pts[0] + 0.01, pts[1]); return; }
  if (plain || n === 2 || t === 0) { for (let k = 2; k < pts.length; k += 2) c.lineTo(pts[k], pts[k + 1]); return; }
  const cp: number[] = [];
  for (let i = 1; i < n - 1; i++) cp.push(...controls(pts[2 * i - 2], pts[2 * i - 1], pts[2 * i], pts[2 * i + 1], pts[2 * i + 2], pts[2 * i + 3], t));
  c.quadraticCurveTo(cp[0], cp[1], pts[2], pts[3]);
  for (let i = 1; i < n - 2; i++) {
    c.bezierCurveTo(cp[4 * (i - 1) + 2], cp[4 * (i - 1) + 3], cp[4 * i], cp[4 * i + 1], pts[2 * i + 2], pts[2 * i + 3]);
  }
  const last = 4 * (n - 3);
  c.quadraticCurveTo(cp[last + 2], cp[last + 3], pts[pts.length - 2], pts[pts.length - 1]);
}

/** A drawing in its own box [0, w] × [0, h]. */
export function drawDrawing(c: CanvasRenderingContext2D, item: Item, zoom: number, plain = false): void {
  const pts = pointsOf(item);
  if (pts.length < 2) return;
  const { sx, sy } = drawingScale(item, pts);
  const hl = item.data.tool === 'highlighter';
  c.save();
  if (sx !== 1 || sy !== 1) c.scale(sx, sy);
  c.lineCap = 'round';
  c.lineJoin = 'round';
  c.strokeStyle = (item.style.strokeColor as string) || '#1a1a1a';
  c.lineWidth = Math.max(strokeWidthOf(item), 1 / zoom);
  if (hl) { c.globalAlpha = HIGHLIGHTER_ALPHA; c.globalCompositeOperation = 'multiply'; }
  tracePath(c, pts, plain);
  c.stroke();
  c.restore();
}

/**
 * How far a canvas point is from a drawing's stroke (its centre line), in canvas units — the eraser's and the select tool's hit test. `tl` is
 * the drawing's top-left where it is drawn (rotation ignored: a stroke is thin, its box is not).
 */
export function drawingDistance(item: Item, tl: { x: number; y: number }, x: number, y: number): number {
  const pts = pointsOf(item);
  const { sx, sy } = drawingScale(item, pts);
  let best = Infinity;
  const px = (k: number) => tl.x + pts[k] * sx, py = (k: number) => tl.y + pts[k + 1] * sy;
  if (pts.length === 2) return Math.hypot(x - px(0), y - py(0));
  for (let k = 0; k + 3 < pts.length; k += 2) {
    const ax = px(k), ay = py(k), bx = px(k + 2), by = py(k + 2);
    const dx = bx - ax, dy = by - ay;
    const len = dx * dx + dy * dy;
    const t = len > 0 ? Math.max(0, Math.min(1, ((x - ax) * dx + (y - ay) * dy) / len)) : 0;
    const d = Math.hypot(x - (ax + t * dx), y - (ay + t * dy));
    if (d < best) best = d;
  }
  return best;
}

/** drawingDistance for a drawing drawn centred at `c` and rotated by its rotation: the point is turned back into the drawing's own frame. */
export function drawingDistanceAt(item: Item, c: { x: number; y: number }, x: number, y: number): number {
  let px = x, py = y;
  if (item.rotation) {
    const r = (-item.rotation * Math.PI) / 180;
    const dx = x - c.x, dy = y - c.y;
    px = c.x + dx * Math.cos(r) - dy * Math.sin(r);
    py = c.y + dx * Math.sin(r) + dy * Math.cos(r);
  }
  return drawingDistance(item, { x: c.x - item.width / 2, y: c.y - item.height / 2 }, px, py);
}

/** The drawn half-width of a drawing's stroke in canvas units (its scale applied). */
export function drawingReach(item: Item): number {
  const { sx, sy } = drawingScale(item);
  return (strokeWidthOf(item) / 2) * Math.max(sx, sy);
}

/**
 * Ramer–Douglas–Peucker over [x0, y0, x1, y1, …] at a tolerance (canvas units), iterative; the tolerance doubles until the stroke has at
 * most `max` points (pen-files.md: 1.5 / zoom to start). The first and last points always stay.
 */
export function simplify(pts: number[], tolerance: number, max: number): number[] {
  const n = pts.length / 2;
  if (n <= 2) return pts.slice();
  let tol = Math.max(tolerance, 1e-6);
  for (let round = 0; round < 40; round++) {
    const keep = new Uint8Array(n);
    keep[0] = 1; keep[n - 1] = 1;
    const stack: [number, number][] = [[0, n - 1]];
    while (stack.length) {
      const [a, b] = stack.pop()!;
      const ax = pts[2 * a], ay = pts[2 * a + 1], bx = pts[2 * b], by = pts[2 * b + 1];
      const dx = bx - ax, dy = by - ay;
      const len = Math.hypot(dx, dy);
      let far = -1, farD = tol;
      for (let i = a + 1; i < b; i++) {
        const x = pts[2 * i], y = pts[2 * i + 1];
        const d = len > 0 ? Math.abs(dy * x - dx * y + bx * ay - by * ax) / len : Math.hypot(x - ax, y - ay);
        if (d > farD) { farD = d; far = i; }
      }
      if (far >= 0) { keep[far] = 1; stack.push([a, far], [far, b]); }
    }
    const out: number[] = [];
    for (let i = 0; i < n; i++) if (keep[i]) out.push(pts[2 * i], pts[2 * i + 1]);
    if (out.length / 2 <= max) return out;
    tol *= 2;
  }
  return [pts[0], pts[1], pts[pts.length - 2], pts[pts.length - 1]];
}
