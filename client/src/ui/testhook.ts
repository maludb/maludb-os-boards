// window.__boards — the proofs' hook (canvas-core.md "Proving the canvas"): it exists ONLY when the mount carries data-test="1", which
// tests/dev_router.php's BD_TEST_HOOKS alone sets, never in production. snapshot() the store's items, lastSeq(), level(), applied() the
// applied seqs, waitFor(seq), screenOf(id) an item's centre and its Transformer's handles in page pixels, perf() the frame statistics,
// commit(ops) for setup only — the same path as a gesture. Nothing else reads it.
import type { Board } from '../board';
import type { Op } from '../model/types';
import type { Catchup } from '../sync/catchup';
import { polyline, routeOf } from '../render/items/connector';
import { asked } from '../render/items/image';

export function installTestHook(b: Board, catchup: Catchup, extra: { thumbnail(): Promise<number>; ready: Promise<void> }): void {
  const page = (sx: number, sy: number) => {
    const r = b.view.container.getBoundingClientRect();
    return { x: r.left + sx, y: r.top + sy };
  };
  (window as unknown as Record<string, unknown>).__boards = {
    ready: () => extra.ready,
    snapshot: () => b.store.snapshot(),
    lastSeq: () => catchup.lastSeq,
    level: () => b.level,
    applied: () => catchup.applied(),
    appliedSeqs: () => catchup.applied(),
    live: () => b.live?.state ?? 'connecting',
    presence: () => b.presence?.snapshot() ?? { peers: [], agents: [] },
    waitFor: (seq: number) => catchup.waitFor(seq),
    selection: () => b.selection.list(),
    camera: () => ({ ...b.view.camera }),
    setCamera: (x: number, y: number, zoom: number) => { b.view.setCamera({ x, y, zoom }); b.renderer.flush(); },
    screenOf: (id: string) => {
      const it = b.store.get(id);
      if (!it) return null;
      b.renderer.flush();
      const c = b.store.absCentre(it);
      const s = b.view.toScreen(c.x, c.y);
      const handles: Record<string, { x: number; y: number }> = {};
      if (b.selection.tr.nodes().some((n) => n.getAttr('itemId') === id)) {
        for (const name of ['top-left', 'top-right', 'bottom-left', 'bottom-right', 'middle-left', 'middle-right', 'top-center', 'bottom-center', 'rotater']) {
          const a = b.selection.tr.findOne('.' + name);
          if (a && a.visible()) { const p = a.getAbsolutePosition(); handles[name] = page(p.x, p.y); }
        }
      }
      return { ...page(s.x, s.y), width: it.width * b.view.camera.zoom, height: it.height * b.view.camera.zoom, handles };
    },
    route: (id: string) => { const it = b.store.get(id); const r = it ? routeOf(it, b.renderer.boxOf) : null; return r ? { shape: r.shape, points: r.points, polyline: polyline(r) } : null; },
    frameOrder: () => b.store.all().filter((i) => i.type === 'frame').sort((x, y) => { const xb = b.store.box(x.id)!, yb = b.store.box(y.id)!; return xb.y1 - yb.y1 || xb.x1 - yb.x1; }).map((f) => f.id),
    perf: () => ({ ...b.renderer.stats(), attached: b.renderer.attachedCount(), items: b.store.count() }),
    frames: () => b.renderer.stats(),
    resetPerf: () => b.renderer.resetStats(),
    panFrames: (dx: number, dy: number, frames: number) => b.renderer.panFrames(dx, dy, frames),
    commit: (ops: Op[]) => b.commit(ops)?.answer ?? Promise.resolve(null),
    thumbnail: () => extra.thumbnail(),
    pending: () => b.committer.busy(),
    /** item id → the size its last draw asked the file door for (0 = the original, an SVG) */
    imageSizes: () => Object.fromEntries(asked),
    hidden: (id: string) => !b.store.has(id),
    drawingMaxPoints: () => b.drawingMaxPoints,
  };
}
