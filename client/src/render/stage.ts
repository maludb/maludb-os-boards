// The stage and the camera (canvas-core.md client/src/render/stage.ts): one Konva.Stage in the mount; pan, zoom (0.05–8), screen ↔ canvas.
// The camera is the canvas point at the viewport's centre and the zoom (the board's start view means the same); the stage's transform is
// derived from it. Every camera change asks for ONE frame (requestAnimationFrame-throttled) — the renderer culls and draws there.
import Konva from 'konva';
import type { Box, Camera } from '../model/types';

export const ZOOM_MIN = 0.05;
export const ZOOM_MAX = 8;

export class View {
  readonly stage: Konva.Stage;
  camera: Camera = { x: 0, y: 0, zoom: 1 };
  width: number;
  height: number;
  private listeners: (() => void)[] = [];
  private flight: number | null = null;

  constructor(readonly container: HTMLDivElement) {
    this.width = Math.max(1, container.clientWidth);
    this.height = Math.max(1, container.clientHeight);
    this.stage = new Konva.Stage({ container, width: this.width, height: this.height });
    this.apply();
  }

  onChange(fn: () => void): void { this.listeners.push(fn); }

  resize(w: number, h: number): void {
    this.width = Math.max(1, Math.round(w));
    this.height = Math.max(1, Math.round(h));
    this.stage.size({ width: this.width, height: this.height });
    this.apply();
  }

  setCamera(c: Camera): void {
    this.camera = { x: c.x, y: c.y, zoom: clampZoom(c.zoom) };
    this.apply();
  }

  /** Move the view by a distance in screen pixels (a drag of the canvas: the board follows the finger). */
  panBy(dxScreen: number, dyScreen: number): void {
    this.camera = { ...this.camera, x: this.camera.x - dxScreen / this.camera.zoom, y: this.camera.y - dyScreen / this.camera.zoom };
    this.apply();
  }

  /** Zoom by a factor keeping the canvas point under the screen point where it is. */
  zoomAt(sx: number, sy: number, factor: number): void {
    const before = this.toCanvas(sx, sy);
    const zoom = clampZoom(this.camera.zoom * factor);
    this.camera = { x: before.x - (sx - this.width / 2) / zoom, y: before.y - (sy - this.height / 2) / zoom, zoom };
    this.apply();
  }

  /** The camera that fits a box with a margin (screen pixels), at most 100 % unless asked. */
  cameraFor(b: Box, margin = 48, maxZoom = 1): Camera {
    const w = Math.max(1, b.x2 - b.x1);
    const h = Math.max(1, b.y2 - b.y1);
    const zoom = clampZoom(Math.min((this.width - 2 * margin) / w, (this.height - 2 * margin) / h, maxZoom));
    return { x: (b.x1 + b.x2) / 2, y: (b.y1 + b.y2) / 2, zoom };
  }

  /** Fly to a camera over ~300 ms (instantly when asked). */
  flyTo(to: Camera, ms = 300): void {
    if (this.flight !== null) cancelAnimationFrame(this.flight);
    if (ms <= 0) { this.setCamera(to); return; }
    const from = { ...this.camera };
    const start = performance.now();
    const step = (now: number) => {
      const t = Math.min(1, (now - start) / ms);
      const k = t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;
      const zoom = Math.exp(Math.log(from.zoom) + (Math.log(clampZoom(to.zoom)) - Math.log(from.zoom)) * k);
      this.camera = { x: from.x + (to.x - from.x) * k, y: from.y + (to.y - from.y) * k, zoom };
      this.apply();
      this.flight = t < 1 ? requestAnimationFrame(step) : null;
    };
    this.flight = requestAnimationFrame(step);
  }

  toCanvas(sx: number, sy: number): { x: number; y: number } {
    return { x: this.camera.x + (sx - this.width / 2) / this.camera.zoom, y: this.camera.y + (sy - this.height / 2) / this.camera.zoom };
  }

  toScreen(cx: number, cy: number): { x: number; y: number } {
    return { x: (cx - this.camera.x) * this.camera.zoom + this.width / 2, y: (cy - this.camera.y) * this.camera.zoom + this.height / 2 };
  }

  /** The visible part of the canvas. */
  viewport(): Box {
    const a = this.toCanvas(0, 0);
    const b = this.toCanvas(this.width, this.height);
    return { x1: a.x, y1: a.y, x2: b.x, y2: b.y };
  }

  private apply(): void {
    const z = this.camera.zoom;
    this.stage.scale({ x: z, y: z });
    this.stage.position({ x: this.width / 2 - this.camera.x * z, y: this.height / 2 - this.camera.y * z });
    for (const l of this.listeners) l();
  }
}

export function clampZoom(z: number): number {
  return Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, Number.isFinite(z) ? z : 1));
}
