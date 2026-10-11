// The live socket (live-relay.md "The client side"; live.md — slice 3, the second exemplar). It connects with the mount's single-use ticket
// and the tab's client id; on `welcome` the board is live (and the island asks ops?after= once — a batch committed between the page's render
// and the socket's open). It sends cursor (≤ 20 Hz), select, view (≤ 2 Hz), drag (≤ 20 Hz, edit and full only), editing, and a ping every 25 s.
// When it closes it takes a fresh ticket from the ticket door and reconnects after 1, 2, 5, 10, 10 … s; after the SECOND failed attempt —
// or with no ticket at all, or "Live updates are off" — the state is `polling` and the poll runs until the socket is back (catching up
// first). A 4403 for any other reason reloads the page: the wall decides what the person sees now. 4429 (the room is full) polls.
import type { RemoteBatch } from '../model/types';

export type LiveState = 'connecting' | 'live' | 'polling' | 'offline' | 'unavailable';

export interface Peer { member_id: number; name: string; kind: string; colour: string; client: string; editing: string | null }
export type PeerKind = 'cursor' | 'select' | 'view' | 'drag' | 'editing';

export interface LiveCallbacks {
  onBatch(b: RemoteBatch): void;
  /** a committed batch whose ops the relay could not carry: ask ops?after= */
  onGap(seq: number): void;
  onState(state: LiveState, peers: number): void;
  onResync(): void;
  onLevel(level: string): void;
  onWelcome?(you: Peer, peers: Peer[]): void;
  onJoin?(p: Peer): void;
  onLeave?(memberId: number, client: string): void;
  onPeer?(kind: PeerKind, memberId: number, client: string, d: Record<string, unknown>): void;
  onPresence?(p: { member_id: number; name: string; kind: string; state: string; dispatch_id: number | null }): void;
}

export interface LiveConnection {
  readonly state: LiveState;
  close(): void;
  cursor(x: number, y: number): void;
  select(ids: string[]): void;
  view(x: number, y: number, zoom: number): void;
  drag(items: { id: string; x: number; y: number; width: number; height: number; rotation: number }[]): void;
  editing(id: string | null): void;
}

export interface LiveConfig { liveUrl: string; ticket: string | null; ticketUrl: string; csrf: string; clientId: string; canEdit: boolean }

const BACKOFF = [1000, 2000, 5000, 10000];
const PING_EVERY = 25000;

class Live implements LiveConnection {
  state: LiveState = 'connecting';
  private ws: WebSocket | null = null;
  private ticket: string | null;
  private failures = 0;
  private closed = false;
  private retry: number | null = null;
  private pinger: number | null = null;
  private peers = 0;
  private lastSent: Record<string, number> = {};
  private pending: Record<string, unknown> = {};
  private timers: Record<string, number | null> = {};
  private off = false;

  constructor(private cfg: LiveConfig, private cb: LiveCallbacks) {
    this.ticket = cfg.ticket;
    if (!this.ticket) { this.off = true; this.setState('polling'); this.schedule(60000); return; }   // live updates off: ask again in a minute
    this.open();
  }

  private setState(s: LiveState): void {
    if (this.state === s) return;
    this.state = s;
    this.cb.onState(s, this.peers);
  }

  private async freshTicket(): Promise<string | null> {
    try {
      const res = await fetch(this.cfg.ticketUrl, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-CSRF-Token': this.cfg.csrf } });
      if (res.status === 401 || res.status === 404) { window.location.reload(); return null; }
      if (!res.ok) return null;
      const j = (await res.json()) as { ticket?: string | null; live?: boolean };
      this.off = j.live === false;                      // live updates are off: a setting, not a failure — poll, ask again in a minute
      return j.ticket ?? null;
    } catch {
      return null;
    }
  }

  private open(): void {
    const ticket = this.ticket;
    this.ticket = null;                                 // single use: the next attempt takes a fresh one
    if (!ticket || this.closed) return;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const url = `${proto}//${location.host}${this.cfg.liveUrl}?ticket=${encodeURIComponent(ticket)}&client=${encodeURIComponent(this.cfg.clientId)}`;
    let ws: WebSocket;
    try { ws = new WebSocket(url); } catch { this.failed(); return; }
    this.ws = ws;
    let reason = '';
    ws.onmessage = (ev) => {
      let m: Record<string, unknown>;
      try { m = JSON.parse(String(ev.data)); } catch { return; }
      if (m.t === 'closing') reason = String(m.reason ?? '');
      this.receive(m);
    };
    ws.onclose = (ev) => {
      if (this.ws === ws) this.ws = null;
      if (this.pinger !== null) { clearInterval(this.pinger); this.pinger = null; }
      if (this.closed) return;
      const why = reason || ev.reason;
      if (ev.code === 4403 && !/off/i.test(why)) { window.location.reload(); return; }
      if (ev.code === 4403 || ev.code === 4429) { this.off = ev.code === 4403; this.failures = Math.max(this.failures, 2); }
      this.failed();
    };
  }

  private receive(m: Record<string, unknown>): void {
    switch (m.t) {
      case 'welcome': {
        this.failures = 0;
        const peers = (m.peers as Peer[]) ?? [];
        this.peers = peers.length;
        this.cb.onWelcome?.(m.you as Peer, peers);
        if (this.pinger === null) this.pinger = window.setInterval(() => this.raw({ t: 'ping' }), PING_EVERY);
        if (this.state === 'live') this.cb.onState('live', this.peers); else this.setState('live');
        break;
      }
      case 'join': this.peers++; this.cb.onJoin?.(m.peer as Peer); break;
      case 'leave': this.peers = Math.max(0, this.peers - 1); this.cb.onLeave?.(Number(m.member_id), String(m.client)); break;
      case 'peer': this.cb.onPeer?.(m.k as PeerKind, Number(m.member_id), String(m.client), (m.d as Record<string, unknown>) ?? {}); break;
      case 'ops':
        if (Array.isArray(m.ops)) this.cb.onBatch(m as unknown as RemoteBatch);
        else this.cb.onGap(Number(m.seq));
        break;
      case 'presence': this.cb.onPresence?.(m as never); break;
      case 'resync': this.cb.onResync(); break;
      case 'level': this.cb.onLevel(String(m.level)); break;
    }
  }

  private failed(): void {
    this.failures++;
    if (this.failures >= 2 || this.off) this.setState('polling');
    this.schedule(this.off ? 60000 : BACKOFF[Math.min(this.failures - 1, BACKOFF.length - 1)]);
  }

  private schedule(ms: number): void {
    if (this.retry !== null || this.closed) return;
    this.retry = window.setTimeout(async () => {
      this.retry = null;
      this.ticket = await this.freshTicket();
      if (this.ticket) this.open(); else this.failed();
    }, ms);
  }

  private raw(m: Record<string, unknown>): void {
    if (this.ws && this.ws.readyState === WebSocket.OPEN) this.ws.send(JSON.stringify(m));
  }

  /** At most one message of a kind every `every` ms; the latest wins (sent when the window ends). */
  private throttled(kind: string, every: number, m: Record<string, unknown>): void {
    if (this.state !== 'live') return;
    const now = performance.now();
    const due = (this.lastSent[kind] ?? 0) + every;
    if (now >= due && this.timers[kind] == null) { this.lastSent[kind] = now; this.raw(m); return; }
    this.pending[kind] = m;
    if (this.timers[kind] == null) {
      this.timers[kind] = window.setTimeout(() => {
        this.timers[kind] = null;
        this.lastSent[kind] = performance.now();
        const p = this.pending[kind] as Record<string, unknown>;
        delete this.pending[kind];
        if (p) this.raw(p);
      }, Math.max(0, due - now));
    }
  }

  cursor(x: number, y: number): void { this.throttled('cursor', 50, { t: 'cursor', x: Math.round(x * 10) / 10, y: Math.round(y * 10) / 10 }); }
  select(ids: string[]): void { if (this.state === 'live') this.raw({ t: 'select', ids: ids.slice(0, 500) }); }
  view(x: number, y: number, zoom: number): void { this.throttled('view', 500, { t: 'view', x: Math.round(x), y: Math.round(y), zoom: Math.round(zoom * 1000) / 1000 }); }
  drag(items: { id: string; x: number; y: number; width: number; height: number; rotation: number }[]): void {
    if (!this.cfg.canEdit) return;
    const r = (n: number) => Math.round(n * 10) / 10;
    const m = { t: 'drag', items: items.slice(0, 50).map((i) => ({ id: i.id, x: r(i.x), y: r(i.y), width: r(i.width), height: r(i.height), rotation: r(i.rotation) })) };
    if (items.length === 0) {                                     // the drop: at once — and a move still held back is never sent after it
      if (this.timers.drag != null) { clearTimeout(this.timers.drag); this.timers.drag = null; }
      delete this.pending.drag;
      this.raw(m);
      return;
    }
    this.throttled('drag', 50, m);
  }
  editing(id: string | null): void { if (this.cfg.canEdit && this.state === 'live') this.raw({ t: 'editing', id }); }

  close(): void {
    this.closed = true;
    if (this.retry !== null) clearTimeout(this.retry);
    if (this.pinger !== null) clearInterval(this.pinger);
    this.ws?.close(1000, 'bye');
  }
}

export function connectLive(cfg: LiveConfig, cb: LiveCallbacks): LiveConnection {
  return new Live(cfg, cb);
}
