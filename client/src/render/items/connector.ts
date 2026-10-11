// A connector (slice 4; connectors-frames.md "The data", decision 2): joined to its items' sides — `auto` re-chooses the side facing the other
// end on every render — or to a free point, routed straight, elbowed (Manhattan, leaving each side perpendicularly by 20, the fewest bends then
// the shortest) or curved (a cubic Bézier whose controls leave each side by a third of the distance), with arrowheads and one caption. The
// route is computed here, at draw time, from where the ends are drawn — never stored — so a connector follows a drag on every canvas.
// Unlike every other type it is drawn in ABSOLUTE coordinates (its own x, y, width, height are 0; its box is db/007's, from its ends).
import type { Box, Item } from '../../model/types';
import { FONT_FAMILY } from './text';

export type Side = 'top' | 'right' | 'bottom' | 'left';
export interface End { x: number; y: number; dx: number; dy: number }
export interface Route { shape: string; points: number[]; start: End; end: End }
export type BoxOf = (id: string) => Box | null;

const STUB = 20;
const centre = (b: Box) => ({ x: (b.x1 + b.x2) / 2, y: (b.y1 + b.y2) / 2 });

/** The side of a box facing a point (its aspect weighed: a wide box turns to its sides less readily). */
export function sideFacing(b: Box, toward: { x: number; y: number }): Side {
  const c = centre(b);
  const dx = toward.x - c.x;
  const dy = toward.y - c.y;
  const w = Math.max(1, b.x2 - b.x1);
  const h = Math.max(1, b.y2 - b.y1);
  if (Math.abs(dx) * h >= Math.abs(dy) * w) return dx >= 0 ? 'right' : 'left';
  return dy >= 0 ? 'bottom' : 'top';
}

export function snapOn(b: Box, side: Side): End {
  const c = centre(b);
  switch (side) {
    case 'top': return { x: c.x, y: b.y1, dx: 0, dy: -1 };
    case 'right': return { x: b.x2, y: c.y, dx: 1, dy: 0 };
    case 'bottom': return { x: c.x, y: b.y2, dx: 0, dy: 1 };
    default: return { x: b.x1, y: c.y, dx: -1, dy: 0 };
  }
}

/** The four side handles of a box (where a connector starts from). */
export function sideHandles(b: Box): { side: Side; x: number; y: number }[] {
  return (['top', 'right', 'bottom', 'left'] as Side[]).map((side) => { const e = snapOn(b, side); return { side, x: e.x, y: e.y }; });
}

/** Where a connector's ends are, with the direction each leaves in. Null when an end has neither an item nor a point. */
export function routeOf(item: Item, boxOf: BoxOf): Route | null {
  const d = item.data as { shape?: string; startSnap?: string; endSnap?: string; startPoint?: { x: number; y: number }; endPoint?: { x: number; y: number } };
  const sb = item.start_item_id ? boxOf(item.start_item_id) : null;
  const eb = item.end_item_id ? boxOf(item.end_item_id) : null;
  const sp = !sb && d.startPoint ? d.startPoint : null;
  const ep = !eb && d.endPoint ? d.endPoint : null;
  if ((!sb && !sp) || (!eb && !ep)) return null;
  const sc = sb ? centre(sb) : sp!;
  const ec = eb ? centre(eb) : ep!;
  const end = (b: Box | null, p: { x: number; y: number } | null, snap: string | undefined, other: { x: number; y: number }): End => {
    if (b) return snapOn(b, snap && snap !== 'auto' ? (snap as Side) : sideFacing(b, other));
    // a free point leaves toward the other end along the dominant axis
    const dx = other.x - p!.x, dy = other.y - p!.y;
    return Math.abs(dx) >= Math.abs(dy) ? { x: p!.x, y: p!.y, dx: Math.sign(dx) || 1, dy: 0 } : { x: p!.x, y: p!.y, dx: 0, dy: Math.sign(dy) || 1 };
  };
  const s = end(sb, sp, d.startSnap, ec);
  const e = end(eb, ep, d.endSnap, sc);
  const shape = d.shape === 'straight' || d.shape === 'curved' ? d.shape : 'elbowed';
  if (shape === 'straight') return { shape, points: [s.x, s.y, e.x, e.y], start: s, end: e };
  if (shape === 'curved') {
    const dist = Math.hypot(e.x - s.x, e.y - s.y) / 3;
    return { shape, points: [s.x, s.y, s.x + s.dx * dist, s.y + s.dy * dist, e.x + e.dx * dist, e.y + e.dy * dist, e.x, e.y], start: s, end: e };
  }
  return { shape, points: elbow(s, e), start: s, end: e };
}

/** Manhattan routing: the candidate with the fewest bends, then the shortest; a 180° turn counts twice. */
function elbow(s: End, e: End): number[] {
  const a = { x: s.x + s.dx * STUB, y: s.y + s.dy * STUB };
  const b = { x: e.x + e.dx * STUB, y: e.y + e.dy * STUB };
  const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
  const candidates = [
    [s, a, { x: b.x, y: a.y }, b, e],
    [s, a, { x: a.x, y: b.y }, b, e],
    [s, a, { x: mx, y: a.y }, { x: mx, y: b.y }, b, e],
    [s, a, { x: a.x, y: my }, { x: b.x, y: my }, b, e],
  ].map(clean);
  let best: { x: number; y: number }[] = candidates[0];
  let bestScore = Infinity;
  for (const c of candidates) {
    let bends = 0;
    let len = 0;
    for (let i = 1; i < c.length; i++) {
      len += Math.abs(c[i].x - c[i - 1].x) + Math.abs(c[i].y - c[i - 1].y);
      if (i >= 2) {
        const d1 = { x: Math.sign(c[i - 1].x - c[i - 2].x), y: Math.sign(c[i - 1].y - c[i - 2].y) };
        const d2 = { x: Math.sign(c[i].x - c[i - 1].x), y: Math.sign(c[i].y - c[i - 1].y) };
        if (d1.x !== d2.x || d1.y !== d2.y) bends += d1.x === -d2.x && d1.y === -d2.y ? 2 : 1;
      }
    }
    const score = bends * 1e6 + len;
    if (score < bestScore) { bestScore = score; best = c; }
  }
  return best.flatMap((p) => [p.x, p.y]);
}

/** Drop repeated points and the middle of three in a line. */
function clean(ps: { x: number; y: number }[]): { x: number; y: number }[] {
  const out: { x: number; y: number }[] = [];
  for (const p of ps) {
    const q = out[out.length - 1];
    if (q && Math.abs(q.x - p.x) < 1e-6 && Math.abs(q.y - p.y) < 1e-6) continue;
    out.push(p);
    if (out.length >= 3) {
      const [u, v, w] = out.slice(-3);
      if ((Math.abs(u.x - v.x) < 1e-6 && Math.abs(v.x - w.x) < 1e-6) || (Math.abs(u.y - v.y) < 1e-6 && Math.abs(v.y - w.y) < 1e-6)) out.splice(out.length - 2, 1);
    }
  }
  return out;
}

/** The route as a polyline (a curve sampled), for hit tests, the caption and the proofs. */
export function polyline(r: Route): { x: number; y: number }[] {
  const p = r.points;
  if (r.shape !== 'curved') { const out = []; for (let i = 0; i < p.length; i += 2) out.push({ x: p[i], y: p[i + 1] }); return out; }
  const out = [];
  for (let k = 0; k <= 24; k++) {
    const t = k / 24, u = 1 - t;
    out.push({ x: u * u * u * p[0] + 3 * u * u * t * p[2] + 3 * u * t * t * p[4] + t * t * t * p[6], y: u * u * u * p[1] + 3 * u * u * t * p[3] + 3 * u * t * t * p[5] + t * t * t * p[7] });
  }
  return out;
}

export function distanceTo(r: Route, x: number, y: number): number {
  const pl = polyline(r);
  let best = Infinity;
  for (let i = 1; i < pl.length; i++) {
    const a = pl[i - 1], b = pl[i];
    const vx = b.x - a.x, vy = b.y - a.y;
    const len2 = vx * vx + vy * vy || 1;
    const t = Math.max(0, Math.min(1, ((x - a.x) * vx + (y - a.y) * vy) / len2));
    best = Math.min(best, Math.hypot(a.x + vx * t - x, a.y + vy * t - y));
  }
  return best;
}

/** The point at a fraction of the route's length (the caption sits at 0.5). */
export function pointAlong(r: Route, f: number): { x: number; y: number } {
  const pl = polyline(r);
  let total = 0;
  for (let i = 1; i < pl.length; i++) total += Math.hypot(pl[i].x - pl[i - 1].x, pl[i].y - pl[i - 1].y);
  let want = total * f;
  for (let i = 1; i < pl.length; i++) {
    const seg = Math.hypot(pl[i].x - pl[i - 1].x, pl[i].y - pl[i - 1].y);
    if (want <= seg || i === pl.length - 1) { const t = seg ? Math.min(1, want / seg) : 0; return { x: pl[i - 1].x + (pl[i].x - pl[i - 1].x) * t, y: pl[i - 1].y + (pl[i].y - pl[i - 1].y) * t }; }
    want -= seg;
  }
  return pl[0];
}

export function captionOf(item: Item): string {
  const caps = item.data.captions as { content?: string }[] | undefined;
  return (Array.isArray(caps) && caps[0]?.content ? String(caps[0].content) : '') || String(item.data.caption ?? '');
}

/** The connector on the canvas, in absolute coordinates. */
export function drawConnector(c: CanvasRenderingContext2D, item: Item, zoom: number, boxOf: BoxOf, flat = false): void {
  const r = routeOf(item, boxOf);
  if (!r) return;
  const st = item.style as { strokeColor?: string; strokeWidth?: number; strokeStyle?: string; startStrokeCap?: string; endStrokeCap?: string };
  const colour = st.strokeColor || '#1a1a1a';
  const w = Math.max(1, Math.min(8, Number(st.strokeWidth) || 2));
  const p = r.points;
  c.save();
  c.strokeStyle = colour;
  c.fillStyle = colour;
  c.lineWidth = flat ? Math.max(w, 1 / zoom) : w;
  c.lineJoin = 'round';
  c.lineCap = st.strokeStyle === 'dotted' ? 'round' : 'butt';
  if (!flat && st.strokeStyle === 'dashed') c.setLineDash([w * 4, w * 3]);
  else if (!flat && st.strokeStyle === 'dotted') c.setLineDash([0.1, w * 2.5]);
  c.beginPath();
  c.moveTo(p[0], p[1]);
  if (r.shape === 'curved' && !flat) c.bezierCurveTo(p[2], p[3], p[4], p[5], p[6], p[7]);
  else if (flat) c.lineTo(p[p.length - 2], p[p.length - 1]);
  else for (let i = 2; i < p.length; i += 2) c.lineTo(p[i], p[i + 1]);
  c.stroke();
  c.setLineDash([]);
  if (!flat) {
    const n = p.length;
    head(c, st.endStrokeCap ?? 'arrow', p[n - 2], p[n - 1], p[n - 4], p[n - 3], w);
    head(c, st.startStrokeCap ?? 'none', p[0], p[1], p[2], p[3], w);
    const text = captionOf(item);
    if (text && 14 * zoom >= 6) {
      const at = pointAlong(r, 0.5);
      c.font = `14px ${FONT_FAMILY}`;
      const tw = c.measureText(text).width;
      c.fillStyle = '#ffffff';
      c.fillRect(at.x - tw / 2 - 6, at.y - 11, tw + 12, 22);
      c.fillStyle = '#1a1a1a';
      c.textAlign = 'center';
      c.textBaseline = 'middle';
      c.fillText(text, at.x, at.y);
    }
  }
  c.restore();
}

/** An arrowhead at (x, y) pointing away from (fx, fy). */
function head(c: CanvasRenderingContext2D, cap: string, x: number, y: number, fx: number, fy: number, w: number): void {
  if (cap !== 'arrow' && cap !== 'filled_triangle') return;
  const a = Math.atan2(y - fy, x - fx);
  const len = 10 + w * 2;
  const spread = cap === 'arrow' ? 0.5 : 0.42;
  const l = { x: x - len * Math.cos(a - spread), y: y - len * Math.sin(a - spread) };
  const r = { x: x - len * Math.cos(a + spread), y: y - len * Math.sin(a + spread) };
  c.beginPath();
  if (cap === 'arrow') { c.moveTo(l.x, l.y); c.lineTo(x, y); c.lineTo(r.x, r.y); c.stroke(); }
  else { c.moveTo(x, y); c.lineTo(l.x, l.y); c.lineTo(r.x, r.y); c.closePath(); c.fill(); }
}
