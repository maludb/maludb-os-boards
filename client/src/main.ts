// The canvas island (canvas-core.md "The island and the page"): reads the mount's data-*, builds the store, the stage, the tools and the
// sync, fires and listens the page's events. It draws, edits and syncs; it never decides a rule — every write is a batch through
// POST /boards/{board}/ops → bd_commit_ops(), the database's one write path. One island a page; an HTMX navigation away takes it down.
import Konva from 'konva';
import type { Board, ToolName } from './board';
import type { BoardState, Camera, CommitAnswer, Item, Level, Op } from './model/types';
import { LEVEL_RANK } from './model/types';
import { Store } from './model/store';
import { keyAfter, keyBefore } from './model/fractional';
import { View } from './render/stage';
import { Renderer } from './render/layers';
import { Committer } from './sync/commit';
import { Catchup } from './sync/catchup';
import { Poller } from './sync/poll';
import { connectLive, type LiveConnection } from './sync/socket';
import { UndoStack } from './sync/undo';
import { Selection, SelectTool } from './tools/select';
import { HandTool } from './tools/hand';
import { StickyTool } from './tools/sticky';
import { ShapeTool } from './tools/shape';
import { TextTool } from './tools/text';
import { Input, type Tool } from './tools/input';
import { Toasts } from './ui/toast';
import { TextEditor } from './ui/textedit';
import { Toolbar } from './ui/toolbar';
import { ZoomBar } from './ui/zoom';
import { RailBridge, type CommandDetail } from './ui/rail';
import { Clipboard } from './ui/clipboard';
import { Thumbnails } from './ui/thumbnail';
import { Exports } from './ui/export';
import { installTestHook } from './ui/testhook';
import { Presence } from './ui/presence';
import { ConnectorTool } from './tools/connector';
import { FrameTool } from './tools/frame';
import { InlineEditor } from './ui/inline';
import { Navigator } from './ui/navigator';
import { Minimap } from './ui/minimap';
import { PenTool } from './tools/pen';
import { HighlighterTool } from './tools/highlighter';
import { EraserTool } from './tools/eraser';
import { CommentTool, CommentsController } from './tools/comment';
import { PinLayer } from './render/pins';
import { PinSync } from './sync/pins';
import { Uploader } from './tools/image';
import { configureImages } from './render/items/image';
import { frameTopLeft, round2 } from './model/geometry';

declare global { interface Window { __boardsIsland?: { destroy(): void } } }

function parseView(s: string | undefined): Camera | null {
  if (!s) return null;
  const [x, y, zoom] = s.trim().split(/\s+/).map(Number);
  return [x, y, zoom].every(Number.isFinite) && zoom > 0 ? { x, y, zoom } : null;
}

function boot(mount: HTMLElement): { destroy(): void } {
  const d = mount.dataset;
  Konva.pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
  const level = (d.level ?? 'view') as Level;
  const rank = LEVEL_RANK[level] ?? 1;
  const editable = rank >= 3 && (d.locked !== '1' || rank >= 4);
  const csrf = d.csrf ?? '';
  const clientId = d.client ?? '';
  const background = (() => { try { return JSON.parse(d.background ?? '{}'); } catch { return {}; } })() as { pattern?: string; color?: string };
  mount.style.backgroundColor = background.color ?? '#ffffff';

  const container = document.createElement('div');
  container.className = 'board-stage';
  container.id = 'board-stage';
  container.tabIndex = 0;
  container.setAttribute('aria-label', 'The board');
  mount.appendChild(container);

  // the page fills the window between the header and the bars at the bottom; the canvas fills what the rail leaves
  const pageEl = document.getElementById('board-page');
  const fit = () => {
    if (!pageEl) return;
    const top = pageEl.getBoundingClientRect().top;
    let bottom = window.visualViewport?.height ?? window.innerHeight;
    for (const id of ['assistant-bar', 'tab-bar']) {
      const el = document.getElementById(id);
      if (!el) continue;
      const cs = getComputedStyle(el);                       // (a fixed element's offsetParent is always null: ask its display)
      if (cs.display !== 'none' && cs.visibility !== 'hidden' && cs.position === 'fixed' && el.getBoundingClientRect().height > 0) bottom = Math.min(bottom, el.getBoundingClientRect().top);
    }
    pageEl.style.height = `${Math.max(240, bottom - top)}px`;
  };
  fit();

  const store = new Store();
  const view = new View(container);
  const renderer = new Renderer(view, store, background);
  const toasts = new Toasts(document.getElementById('board-toasts'), document.getElementById('board-banner'));
  let tool: ToolName = 'select';
  let people: BoardState['people'] = {};

  // the shared context: built in two steps (the committer and the selection need it)
  const b = { boardId: d.board ?? '', mount, store, view, renderer, toasts, level, editable, memberId: Number(d.member ?? 0), people,
    batchMax: Number(d.batchMax ?? 500), shapeKind: 'rectangle', pointer: null, pen: { color: '#1a1a1a', width: 4 }, highlighterColor: '#ffd43b',
    drawingMaxPoints: Math.max(2, Number(d.drawingMaxPoints ?? 2000)) } as unknown as Board;
  configureImages(d.fileUrl ?? '/files/', () => renderer.request());

  let rail: RailBridge;
  let thumbs: Thumbnails;
  let zoomBar: ZoomBar;
  const selectionTouched = (ops: Op[]) => ops.some((o) => (o.op === 'update' && b.selection.has(o.id)) || ((o.op === 'delete' || o.op === 'restore') && (o.ids ?? []).some((i) => b.selection.has(i))));

  const committer = new Committer({ opsUrl: d.opsUrl ?? '', csrf, clientId, batchMax: b.batchMax }, store, {
    onAnswer: (p, a: CommitAnswer) => {
      if (!a.noop) catchup.markApplied(a.seq);
      for (const r of a.replaced ?? []) {
        const who = r.by !== null && people[String(r.by)] ? people[String(r.by)].name : 'someone';
        toasts.show(`You replaced ${who}'s edit`, { action: { label: 'Undo', run: () => { void b.undo.undoAnswer(a); } }, id: 'board-toast-replaced' });
      }
      for (const w of a.warnings ?? []) toasts.warnOnce(w);
      rail.changed({ seq: a.seq, source: 'web', member_id: b.memberId });
      if (selectionTouched(p.ops)) rail.select(b.selection.list());
      thumbs.changed();
      zoomBar.undoState();
    },
    onRefused: (_p, status, message) => {
      toasts.show(message, { kind: 'danger', id: status === 403 ? 'board-toast-refused' : undefined });
      zoomBar.undoState();
    },
    onLost: () => toasts.show('Not saved — check your connection', { kind: 'danger', id: 'board-toast-lost' }),
  });
  b.committer = committer;
  const undo = new UndoStack(committer, () => zoomBar?.undoState());
  b.undo = undo;
  const catchup = new Catchup({ opsUrl: d.opsUrl ?? '', stateUrl: d.stateUrl ?? '', clientId }, store, committer, {
    onApplied: (batch) => {
      rail.changed({ seq: batch.seq, source: batch.source, member_id: batch.member_id, counts: batch.summary?.counts });
      presence.batch(batch.member_id, batch.source, batch.summary?.bbox,
        batch.ops.flatMap((o) => (o.op === 'create' ? [o.item.id] : o.op === 'update' ? [o.id] : [])));
      if (selectionTouched(batch.ops)) rail.select(b.selection.list());
    },
    onResync: (state) => { people = state.people; b.people = people; },
  }, Number(d.lastSeq ?? 0));

  const selection = new Selection(b);
  const presence = new Presence(b);
  b.presence = presence;
  b.live = null;
  b.selection = selection;
  const editor = new TextEditor(b);
  const clipboard = new Clipboard(b);
  const tools: Record<ToolName, Tool> = { select: new SelectTool(b), hand: new HandTool(b), sticky: new StickyTool(b), shape: new ShapeTool(b), text: new TextTool(b),
    connector: new ConnectorTool(b), frame: new FrameTool(b), pen: new PenTool(b), highlighter: new HighlighterTool(b), eraser: new EraserTool(b), comment: new CommentTool(b) };
  const uploader = new Uploader(b, d.uploadUrl ?? '/files/upload.php', csrf, Math.max(1, Number(d.maxUploadMb ?? 25)));
  b.pickFile = () => uploader.pick();
  b.upload = (files: File[], at: { x: number; y: number } | null) => uploader.upload(files, at);
  const inline = new InlineEditor(b);
  const navigator = new Navigator(b);
  new Exports(b, csrf, background);
  new Minimap(b);
  const toolbar = new Toolbar(b, document.getElementById('board-toolbar'));

  b.commit = (ops: Op[], kind = 'gesture') => {
    if (!editable) { toasts.show('You can see this board but not change it.', { kind: 'warning' }); return null; }
    if (ops.length > b.batchMax) { toasts.show(`That is more than ${b.batchMax} changes at once.`, { kind: 'warning' }); return null; }
    const p = committer.submit(ops, kind);
    if (p && kind === 'gesture') undo.pushGesture(p);
    return p;
  };
  b.setTool = (t: ToolName) => {
    if (!editable && t !== 'select' && t !== 'hand' && !(t === 'comment' && rank >= 2)) return;
    input.cancel();
    tool = t;
    container.style.cursor = tools[t].cursor;
    toolbar.show(t);
  };
  b.tool = () => tool;
  b.editText = (id: string, created = false) => { if (editable) editor.open(id, created); };
  b.editInline = (id: string) => { if (editable) inline.open(id); };
  b.fire = (name: string, detail: unknown) => rail.fire(name, detail);
  b.frameAt = (x: number, y: number, except?: Set<string>) => {
    let best: Item | null = null;
    for (const it of store.items.values()) {
      if (it.type !== 'frame' || except?.has(it.id)) continue;
      if (x >= it.x - it.width / 2 && x <= it.x + it.width / 2 && y >= it.y - it.height / 2 && y <= it.y + it.height / 2 && (!best || it.z > best.z)) best = it;
    }
    return best;
  };

  const fitBoard = () => {
    const ext = store.extent();
    view.flyTo(ext ? view.cameraFor(ext) : { x: 0, y: 0, zoom: 1 }, 250);
  };
  zoomBar = new ZoomBar(b, fitBoard);

  /** Delete what is selected; a frame that holds things not selected with it asks first: its contents too, or kept where they stand (slice 4). */
  const deleteSelection = () => {
    const ids = selection.list().filter((id) => !store.get(id)?.locked);
    if (!ids.length) return;
    const chosen = new Set(ids);
    const holding = ids.some((id) => store.get(id)?.type === 'frame' && store.children(id).some((c) => !chosen.has(c.id)));
    if (!holding) { b.commit([{ op: 'delete', ids }]); return; }
    toasts.ask('Delete its contents too?', [
      { label: 'Keep them', id: 'board-ask-keep', run: () => b.commit([{ op: 'delete', ids, keep_children: true }]) },
      { label: 'Delete all', id: 'board-ask-all', run: () => b.commit([{ op: 'delete', ids }]) },
    ], 'board-ask-frame-delete');
  };
  /** Fit a frame to what it holds (40 around, 40 more on top): its box and — its top-left moving — its children's places, ONE batch. */
  const fitFrame = (id: string) => {
    const f = store.get(id);
    const kids = f ? store.children(id) : [];
    if (!f || !kids.length) { toasts.show('The frame holds nothing to fit.', { id: 'board-toast-fit' }); return; }
    let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
    for (const k of kids) { const bx = store.box(k.id)!; x1 = Math.min(x1, bx.x1); y1 = Math.min(y1, bx.y1); x2 = Math.max(x2, bx.x2); y2 = Math.max(y2, bx.y2); }
    x1 -= 40; y1 -= 80; x2 += 40; y2 += 40;
    const old = frameTopLeft(f);
    const ops: Op[] = [{ op: 'update', id, fields: { x: round2((x1 + x2) / 2), y: round2((y1 + y2) / 2), width: round2(x2 - x1), height: round2(y2 - y1) } }];
    for (const k of kids) ops.push({ op: 'update', id: k.id, fields: { x: round2(k.x + old.x - x1), y: round2(k.y + old.y - y1) } });
    b.commit(ops);
  };
  const reorder = (front: boolean) => {
    const items = selection.list().map((id) => store.get(id)).filter((i): i is Item => !!i && !i.locked).sort((x, y) => (x.z < y.z ? -1 : 1));
    if (!items.length) return;
    const ops: Op[] = [];
    if (front) {
      let z = store.highestZ();
      for (const it of items) { z = keyAfter(z); ops.push({ op: 'update', id: it.id, fields: { z } }); }
    } else {
      let z: string | null = store.lowestZ();
      for (const it of [...items].reverse()) { z = keyBefore(z); if (z === null) return; ops.push({ op: 'update', id: it.id, fields: { z } }); }
    }
    b.commit(ops);
  };

  const input = new Input(b, tools, (e) => {
    if (editor.isOpen() || inline.isOpen()) return false;
    const mod = e.ctrlKey || e.metaKey;
    const k = e.key.toLowerCase();
    if (mod && k === 'z') { void (e.shiftKey ? undo.redo() : undo.undo()); return true; }
    if (mod && k === 'y') { void undo.redo(); return true; }
    if (mod && k === 'd') { if (editable) clipboard.duplicate(); return true; }
    if (mod && k === 'c') { clipboard.copy(); return false; }
    if (mod && k === 'v') { if (editable) clipboard.keyPaste(); return false; }
    if (mod && k === 'a') { selection.set(store.all().map((i) => i.id)); return true; }
    if (k === 'delete' || k === 'backspace') { if (editable) deleteSelection(); return true; }
    if (k === 'escape') { selection.clear(); b.setTool('select'); return true; }
    if (k === 'enter') {
      const one = selection.list().length === 1 ? store.get(selection.list()[0]) : undefined;
      if (one && ['sticky_note', 'shape', 'text'].includes(one.type) && !one.locked) { b.editText(one.id); return true; }
      return false;
    }
    if (!mod && !e.altKey && e.shiftKey && k === 'c') { if (rank >= 2) b.setTool('comment'); return true; }
    if (!mod && !e.altKey) {
      const map: Record<string, ToolName> = { v: 'select', h: 'hand', n: 'sticky', s: 'shape', t: 'text', c: 'connector', f: 'frame', p: 'pen', e: 'eraser' };
      if (map[k]) { b.setTool(map[k]); return true; }
    }
    return false;
  });

  rail = new RailBridge(b, {
    command: (c: CommandDetail) => {
      const ids = (c.ids && c.ids.length ? c.ids : selection.list()).filter((id) => store.has(id));
      if (!ids.length && c.kind !== 'start-view') return;
      switch (c.kind) {
        case 'data': {
          if (!c.key) return;
          const ops: Op[] = ids.map((id) => store.get(id)!).filter((i) => !i.locked && (!c.types || c.types.includes(i.type)))
            .map((i) => ({ op: 'update', id: i.id, fields: { data: { [c.key!]: c.value ?? null } } }));
          if (ops.length) b.commit(ops);
          break;
        }
        case 'fit-frame':
          for (const id of ids) if (store.get(id)?.type === 'frame') fitFrame(id);
          break;
        case 'style': {
          if (!c.key) return;
          const value: unknown = c.key === 'fontSize' || c.key === 'strokeWidth' ? Number(c.value) : c.value;
          if (c.key === 'strokeWidth' && ids.some((id) => store.get(id)?.type === 'drawing')) {
            // a drawing's stroke width: its box grows or shrinks by the difference and its points move by half of it, so the stroke stays
            // inside its box (slice 5); anything else selected with it takes the width as it is — one batch
            const d1 = Number(c.value);
            const ops: Op[] = ids.map((id) => store.get(id)!).filter((i) => !i.locked && (!c.types || c.types.includes(i.type))).map((i): Op => {
              if (i.type !== 'drawing') return { op: 'update', id: i.id, fields: { style: { strokeWidth: d1 } } };
              const d0 = Number(i.style.strokeWidth) || 4;
              const pts = (Array.isArray(i.data.points) ? i.data.points as number[] : []).map((v) => Math.round((v + (d1 - d0) / 2) * 10) / 10);
              return { op: 'update', id: i.id, fields: { width: round2(i.width + d1 - d0), height: round2(i.height + d1 - d0), style: { strokeWidth: d1 }, data: { points: pts } } };
            });
            if (ops.length) b.commit(ops);
            break;
          }
          const ops: Op[] = ids.map((id) => store.get(id)!).filter((i) => !i.locked && (!c.types || c.types.includes(i.type)))
            .map((i) => ({ op: 'update', id: i.id, fields: { style: { [c.key!]: value } } }));
          if (ops.length) b.commit(ops);
          break;
        }
        case 'text-size':
          b.commit(ids.map((id) => ({ op: 'update', id, fields: { style: { fontSize: Number(c.value) } } })));
          break;
        case 'lock':
          b.commit(ids.map((id) => ({ op: 'update', id, fields: { locked: c.value === '1' || c.value === 'true' } })));
          break;
        case 'front': reorder(true); break;
        case 'back': reorder(false); break;
        case 'delete': deleteSelection(); break;
        case 'start-view': void setStartView(c.x ?? view.camera.x, c.y ?? view.camera.y); break;
      }
    },
    focus: (f) => {
      if (f.id && store.get(f.id)?.type === 'frame' && f.fit) { navigator.fit(f.id); selection.set([f.id]); return; }
      if (f.id && store.has(f.id)) {
        const box = store.box(f.id)!;
        view.flyTo(view.cameraFor(box, 120, Math.max(1, view.camera.zoom)));
        selection.set([f.id]);
      } else if (f.x !== undefined && f.y !== undefined) {
        view.flyTo({ x: f.x, y: f.y, zoom: f.zoom ?? view.camera.zoom });
      }
    },
    reload: () => { void catchup.reload().catch(() => toasts.show('The board could not be read again.', { kind: 'danger' })); },
  });
  mount.addEventListener('boards:open-item', () => rail.sheet(true, 'item'));

  // comments (slice 6): the pins over the stage, read every 15 s (comments are not ops), and the rail's side of a thread
  const comments: { ctl: CommentsController | null } = { ctl: null };
  const pinLayer = new PinLayer(b, (id) => { void comments.ctl?.openThread(id); });
  const pinSync = new PinSync(d.commentsUrl ?? `/boards/${b.boardId}/comments`, pinLayer);
  comments.ctl = new CommentsController(b, pinLayer, pinSync, rail);
  b.comments = { startThread: (x, y) => comments.ctl?.startThread(x, y), openThread: (id) => comments.ctl?.openThread(id) ?? Promise.resolve() };
  selection.onChange(() => rail.select(selection.list()));

  /** "Set as the start view": board_update's start_view (level edit), through the island so the page stays. */
  const setStartView = async (x: number, y: number) => {
    const form = new URLSearchParams({ board: b.boardId, start_view: `${Math.round(x)} ${Math.round(y)} ${Math.round(view.camera.zoom * 100) / 100}`, csrf_token: csrf });
    const res = await fetch('/boards/save.php', { method: 'POST', credentials: 'same-origin', body: form, headers: { Accept: 'application/json', 'X-CSRF-Token': csrf } }).catch(() => null);
    const j = res ? await res.json().catch(() => null) : null;
    if (res?.ok) toasts.show('This is where the board opens now.', { id: 'board-toast-start-view' });
    else toasts.show(j?.error?.message ?? 'The start view could not be set.', { kind: 'danger' });
  };

  thumbs = new Thumbnails(b, d.thumbnailUrl ?? '', csrf, background, d.thumbnailAge ? Number(d.thumbnailAge) : null);
  const poller = new Poller(catchup, Math.max(1, Number(d.pollSeconds ?? 3)), (ok) => rail.fire('boards:live', { state: ok ? 'polling' : 'offline', peers: 0 }));

  // the live socket (live.md): opened once the state is loaded (a batch must never land on a store the load then replaces), it carries
  // everyone's committed batches, presence and cursors; while it is down the poll does, and the banner says so
  const liveBanner = document.getElementById('board-live-banner');
  const levelChanged = (lvl: string) => {
    rail.fire('boards:level', { level: lvl });
    const r = LEVEL_RANK[lvl] ?? 0;
    if ((r >= 3 && (d.locked !== '1' || r >= 4)) !== editable) {               // the tools on this page no longer fit: the wall decides anew
      toasts.show('Your access to this board changed — reloading…', { kind: 'warning', id: 'board-toast-level' });
      window.setTimeout(() => window.location.reload(), 1500);
    }
  };
  const startLive = (): LiveConnection => connectLive(
    { liveUrl: d.liveUrl ?? '/live', ticket: d.ticket || null, ticketUrl: d.ticketUrl ?? '', csrf, clientId, canEdit: editable }, {
      onBatch: (batch) => catchup.applyRemote(batch),
      onGap: () => { void catchup.pull().catch(() => undefined); },
      onState: (state, peers) => {
        mount.dataset.live = state;
        if (state === 'live') {
          poller.stop();
          liveBanner?.classList.add('d-none');
          void catchup.pull().catch(() => undefined);          // a batch committed while the socket was not there
          b.live?.select(selection.list());
        } else if (state !== 'connecting') {
          presence.clear();
          poller.start();
          liveBanner?.classList.remove('d-none');
        }
        rail.fire('boards:live', { state: state === 'unavailable' ? 'polling' : state, peers });
      },
      // the relay lost notifications: ops?after= is exact (per board seq order is commit order); a compaction meanwhile makes it a reload
      onResync: () => { void catchup.pull().catch(() => catchup.reload()); },
      onLevel: levelChanged,
      onWelcome: (you, peers) => presence.welcome(you, peers),
      onJoin: (p) => presence.join(p),
      onLeave: (m, c) => presence.leave(m, c),
      onPeer: (k, m, c, dd) => presence.peer(k, m, c, dd),
      onPresence: (p) => presence.presence(p),
    });
  selection.onChange(() => b.live?.select(selection.list()));
  view.onChange(() => b.live?.view(view.camera.x, view.camera.y, view.camera.zoom));

  const ro = new ResizeObserver(() => view.resize(container.clientWidth, container.clientHeight));
  ro.observe(container);
  const onResize = () => { fit(); view.resize(container.clientWidth, container.clientHeight); };
  window.addEventListener('resize', onResize);
  window.visualViewport?.addEventListener('resize', onResize);
  const onVisible = () => { if (!document.hidden) poller.kick(); };
  document.addEventListener('visibilitychange', onVisible);
  const onUnload = (e: BeforeUnloadEvent) => { if (committer.busy()) { e.preventDefault(); e.returnValue = ''; } };
  window.addEventListener('beforeunload', onUnload);

  input.attach();
  b.setTool('select');
  view.resize(container.clientWidth, container.clientHeight);

  // the first load: the state, then the view — ?x&y&zoom, else ?item, else the start view, else the whole board
  const ready = (async () => {
    const res = await fetch(d.stateUrl ?? '', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!res.ok) { toasts.show(res.status === 404 ? 'There is no such board.' : 'The board could not be read.', { kind: 'danger' }); return; }
    const state = await res.json() as BoardState;
    people = state.people; b.people = people;
    store.load(state.items);
    catchup.lastSeq = state.last_seq;
    const asked = parseView(d.view);
    const focus = d.focusItem && store.has(d.focusItem) ? d.focusItem : '';
    if (asked) view.setCamera(asked);
    else if (focus) { view.setCamera(view.cameraFor(store.box(focus)!, 120, 1)); selection.set([focus]); }
    else if (state.board.start_view) view.setCamera(state.board.start_view);
    else { const ext = store.extent(); view.setCamera(ext ? view.cameraFor(ext) : { x: 0, y: 0, zoom: 1 }); }
    const soft = Number(d.softBudget ?? 4000);
    if (state.board.item_count > soft) toasts.warnOnce(`This board holds ${state.board.item_count.toLocaleString()} items; boards stay quick under ${soft.toLocaleString()}.`);
    renderer.flush();
    mount.classList.add('ready');
    if (editable && state.items.length > 0 && (d.thumbnailAge === '' || Number(d.thumbnailAge) > 300)) thumbs.changed();
    void catchup.pull().catch(() => undefined);
    b.live = startLive();
    pinSync.start();
    if (d.focusComment) void comments.ctl?.focus(d.focusComment);
  })();

  if (d.test === '1') installTestHook(b, catchup, { thumbnail: () => thumbs.post(), ready });

  return {
    destroy() {
      poller.stop();
      b.live?.close();
      pinSync.stop();
      comments.ctl?.destroy();
      pinLayer.destroy();
      input.detach();
      editor.close(true);
      ro.disconnect();
      window.removeEventListener('resize', onResize);
      window.visualViewport?.removeEventListener('resize', onResize);
      document.removeEventListener('visibilitychange', onVisible);
      window.removeEventListener('beforeunload', onUnload);
      view.stage.destroy();
      document.body.classList.remove('board-canvas-page');
      delete (window as unknown as Record<string, unknown>).__boards;
    },
  };
}

function start(): void {
  if (window.__boardsIsland) return;
  const mount = document.getElementById('board-canvas');
  if (!mount) return;
  window.__boardsIsland = boot(mount);
  // an HTMX navigation away from the canvas takes the island down (a navigation INTO a board is a full page load — view.php)
  document.body.addEventListener('htmx:beforeSwap', (e) => {
    const target = (e as CustomEvent).detail?.target as HTMLElement | undefined;
    if (window.__boardsIsland && target && (target.id === 'page-content' || target.contains(mount))) {
      window.__boardsIsland.destroy();
      window.__boardsIsland = undefined;
    }
  });
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
