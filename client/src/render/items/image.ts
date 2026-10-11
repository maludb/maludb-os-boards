// Images (slice 5; pen-files.md "Level of detail"): an `image` item's bitmap comes through the one file door, `/files/{attachment}?size=`,
// the size chosen by the item's size on screen — 256 when it is at most 256 px, 1024 at most 1024, else 2048 — and never the original of a
// raster on the canvas. An SVG is drawn from its original by the browser (an <img> runs no script; the door serves it sandboxed, at its own
// `?inline=1` URL — the plain one is always an attachment). While the
// size it wants loads, the best size already here is drawn; with none, a grey card. A PDF's first page (a `document` with a preview) is drawn
// the same way by document.ts.
import type { Item } from '../../model/types';
import { FONT_FAMILY } from './text';
import { MIN_TEXT_PX } from '../lod';

export const SIZES = [256, 1024, 2048] as const;
type Size = (typeof SIZES)[number] | 0;                // 0 = the original (an SVG)

interface Entry { img: HTMLImageElement; ok: boolean; failed: boolean }
const cache = new Map<string, Entry>();
let fileUrl = '/files/';
let loaded: () => void = () => undefined;
/** item id → the size its last draw asked for (the proofs' window.__boards.imageSizes()) */
export const asked = new Map<string, number>();

/** The door's base and what to call when a bitmap arrives (the renderer's request()). */
export function configureImages(base: string, onLoaded: () => void): void {
  fileUrl = base.endsWith('/') ? base : base + '/';
  loaded = onLoaded;
}

export function sizeFor(screenPx: number): 256 | 1024 | 2048 {
  return screenPx <= 256 ? 256 : screenPx <= 1024 ? 1024 : 2048;
}

function entry(attachment: string, size: Size): Entry {
  const key = `${attachment}:${size}`;
  let e = cache.get(key);
  if (!e) {
    const img = new Image();
    img.decoding = 'async';
    e = { img, ok: false, failed: false };
    const self = e;
    img.onload = () => { self.ok = true; loaded(); };
    img.onerror = () => { self.failed = true; loaded(); };
    img.src = `${fileUrl}${encodeURIComponent(attachment)}${size ? `?size=${size}` : '?inline=1'}`;      // an SVG's original: the door's inline URL
    cache.set(key, e);
  }
  return e;
}

function peek(attachment: string, size: Size): HTMLImageElement | null {
  const e = cache.get(`${attachment}:${size}`);
  return e && e.ok ? e.img : null;
}

/**
 * The bitmap to draw for an item now: the size its place on screen deserves (asked for if not here), else the nearest size already loaded,
 * else null. `failed` when the door refused every size it was asked.
 */
export function bitmapFor(item: Item, zoom: number): { img: HTMLImageElement | null; failed: boolean } {
  const att = String(item.data.attachment_id ?? '');
  if (!att) return { img: null, failed: true };
  if (item.data.mime_type === 'image/svg+xml') {
    asked.set(item.id, 0);
    const e = entry(att, 0);
    return { img: e.ok ? e.img : null, failed: e.failed };
  }
  const want = sizeFor(Math.max(item.width, item.height) * zoom);
  asked.set(item.id, want);
  const e = entry(att, want);
  if (e.ok) return { img: e.img, failed: false };
  const order = want === 2048 ? [1024, 256] : want === 1024 ? [2048, 256] : [1024, 2048];
  for (const s of order) { const im = peek(att, s as Size); if (im) return { img: im, failed: false }; }
  return { img: null, failed: e.failed };
}

/** An image in its own box [0, w] × [0, h]. */
export function drawImageItem(c: CanvasRenderingContext2D, item: Item, zoom: number): void {
  const w = Math.max(1, item.width), h = Math.max(1, item.height);
  const { img, failed } = bitmapFor(item, zoom);
  if (img) { c.drawImage(img, 0, 0, w, h); return; }
  pendingCard(c, w, h, zoom, failed ? 'The image could not be loaded' : '');
}

/** A grey card where a bitmap is not here yet (or never will be: the reason in words). */
export function pendingCard(c: CanvasRenderingContext2D, w: number, h: number, zoom: number, words: string): void {
  c.fillStyle = '#f1f3f5';
  c.fillRect(0, 0, w, h);
  c.strokeStyle = '#dee2e6';
  c.lineWidth = 1 / zoom;
  c.strokeRect(0, 0, w, h);
  if (!words || 13 * zoom < MIN_TEXT_PX) return;
  c.fillStyle = '#868e96';
  c.font = `13px ${FONT_FAMILY}`;
  c.textAlign = 'center';
  c.textBaseline = 'middle';
  c.fillText(words, w / 2, h / 2, w - 16);
}
