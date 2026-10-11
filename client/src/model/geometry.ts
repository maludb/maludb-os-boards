// Coordinates (canvas-core.md "Coordinates, frames and z"): x, y are the centre; under a frame they are relative to the frame's top-left.
// The server keeps the absolute, rotation-inclusive bbox (db/007's trigger); the client computes the SAME box, for culling and hit tests.
import type { Box, Item } from './types';

/** The rotated bounding box of a w × h rectangle centred at (cx, cy) — the server's formula. */
export function rotatedBox(cx: number, cy: number, w: number, h: number, rotation: number): Box {
  const r = (rotation * Math.PI) / 180;
  const c = Math.abs(Math.cos(r));
  const s = Math.abs(Math.sin(r));
  const hx = (w * c + h * s) / 2;
  const hy = (w * s + h * c) / 2;
  return { x1: cx - hx, y1: cy - hy, x2: cx + hx, y2: cy + hy };
}

export function intersects(a: Box, b: Box): boolean {
  return a.x1 <= b.x2 && a.x2 >= b.x1 && a.y1 <= b.y2 && a.y2 >= b.y1;
}

export function containsBox(outer: Box, inner: Box): boolean {
  return inner.x1 >= outer.x1 && inner.x2 <= outer.x2 && inner.y1 >= outer.y1 && inner.y2 <= outer.y2;
}

export function union(a: Box | null, b: Box): Box {
  if (a === null) return { ...b };
  return { x1: Math.min(a.x1, b.x1), y1: Math.min(a.y1, b.y1), x2: Math.max(a.x2, b.x2), y2: Math.max(a.y2, b.y2) };
}

export function grow(b: Box, by: number): Box {
  return { x1: b.x1 - by, y1: b.y1 - by, x2: b.x2 + by, y2: b.y2 + by };
}

export function boxFromPoints(ax: number, ay: number, bx: number, by: number): Box {
  return { x1: Math.min(ax, bx), y1: Math.min(ay, by), x2: Math.max(ax, bx), y2: Math.max(ay, by) };
}

/** A frame's top-left (frames are top-level and unrotated). */
export function frameTopLeft(frame: Item): { x: number; y: number } {
  return { x: frame.x - frame.width / 2, y: frame.y - frame.height / 2 };
}

/** Is the canvas point inside the item's (rotated) rectangle centred at (cx, cy)? */
export function hitsRotated(px: number, py: number, cx: number, cy: number, w: number, h: number, rotation: number, slop = 0): boolean {
  const r = (-rotation * Math.PI) / 180;
  const dx = px - cx;
  const dy = py - cy;
  const lx = dx * Math.cos(r) - dy * Math.sin(r);
  const ly = dx * Math.sin(r) + dy * Math.cos(r);
  return Math.abs(lx) <= w / 2 + slop && Math.abs(ly) <= h / 2 + slop;
}

/** A rotation in (-360, 360) — the column's check — kept in (-180, 180]. */
export function normaliseRotation(deg: number): number {
  let r = ((deg % 360) + 360) % 360;
  if (r > 180) r -= 360;
  return Math.round(r * 100) / 100;
}

export function round2(n: number): number {
  return Math.round(n * 100) / 100;
}
