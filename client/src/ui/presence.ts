// Who is here and what they are doing (live.md; live-relay.md "The client side", "The agent's cursor"). From the relay: each peer's cursor
// with their name, their selection outlined in their colour, the ghost of what they are dragging, "Maria is editing" on the item they type
// in (the soft lock: my editor still opens — last writer wins — but warns), and the avatars in the top bar (two and "+N" at 375 px). From
// committed batches, whichever way they came: an agent's avatar flies to the batch's box over 400 ms, the items it touched flash, and it
// stays five seconds after its last batch (live.md decision 2) — or while the worker says it is working (`presence`). Everything is drawn
// on the presence layer, which draws on its own when only presence changed (render/layers.ts requestPresence).
import Konva from 'konva';
import type { Board } from '../board';
import type { Peer, PeerKind } from '../sync/socket';

interface PeerState {
  peer: Peer;
  cursor: { x: number; y: number; at: number } | null;
  selection: string[];
  drag: { id: string; x: number; y: number; width: number; height: number; rotation: number }[];
  dragAt: number;
}
interface AgentState { id: number; name: string; colour: string; x: number; y: number; fromX: number; fromY: number; moveAt: number; until: number; working: boolean; flash: string[]; flashUntil: number }

const PALETTE = ['#e03131', '#1971c2', '#2f9e44', '#f08c00', '#9c36b5', '#0c8599', '#c2255c', '#5f3dc4', '#66a80f', '#e8590c', '#1864ab', '#862e9c'];
const CURSOR_IDLE = 30000;
const AGENT_STAYS = 5000;
const AGENT_FLIGHT = 400;

export const colourOf = (memberId: number) => PALETTE[memberId % PALETTE.length];
const initials = (name: string) => name.replace(/^SMOKE\s+/, '').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join('') || '?';

export class Presence {
  private peers = new Map<string, PeerState>();
  private agents = new Map<number, AgentState>();
  private me: Peer | null = null;
  private group = new Konva.Group({ listening: false });
  private strip = document.getElementById('board-presence');
  private ticking = false;

  constructor(private b: Board) {
    b.renderer.presence.add(this.group);
    b.view.onChange(() => this.draw());
    b.store.subscribe(() => this.draw());
    window.matchMedia('(max-width: 991.98px)').addEventListener('change', () => this.renderStrip());
  }

  welcome(you: Peer, peers: Peer[]): void {
    this.me = you;
    this.peers.clear();
    for (const p of peers) this.join(p, false);
    this.changed();
  }

  join(p: Peer, redraw = true): void {
    if (this.me && p.member_id === this.me.member_id && p.client === this.me.client) return;
    this.peers.set(`${p.member_id}|${p.client}`, { peer: p, cursor: null, selection: [], drag: [], dragAt: 0 });
    if (redraw) this.changed();
  }

  leave(memberId: number, client: string): void {
    this.peers.delete(`${memberId}|${client}`);
    this.changed();
  }

  /** Everyone gone (the socket is down): only agents seen in committed batches stay. */
  clear(): void {
    this.peers.clear();
    this.changed();
  }

  peer(kind: PeerKind, memberId: number, client: string, d: Record<string, unknown>): void {
    const s = this.peers.get(`${memberId}|${client}`);
    if (!s) return;
    const now = performance.now();
    switch (kind) {
      case 'cursor': s.cursor = { x: Number(d.x), y: Number(d.y), at: now }; break;
      case 'select': s.selection = (d.ids as string[]) ?? []; break;
      case 'editing': s.peer.editing = (d.id as string | null) ?? null; break;
      case 'drag':
        if (Array.isArray(d.items)) s.drag = d.items as PeerState['drag'];
        else if (Array.isArray(d.ids)) {
          s.drag = (d.ids as string[]).map((id) => this.b.store.get(id)).filter(Boolean).map((it) => {
            const c = this.b.store.absCentre(it!);
            return { id: it!.id, x: c.x + Number(d.dx), y: c.y + Number(d.dy), width: it!.width, height: it!.height, rotation: it!.rotation };
          });
        }
        s.dragAt = now;
        break;
      case 'view': break;                         // the minimap's peer viewports are slice 4's
    }
    if (kind === 'editing') this.renderStrip();
    this.changed(false);
  }

  /** A batch committed by someone else arrived: its member's ghost is done; an agent's avatar flies to the batch's box. */
  batch(memberId: number | null, source: string, bbox: number[] | null | undefined, ids: string[]): void {
    if (memberId === null) return;
    for (const s of this.peers.values()) if (s.peer.member_id === memberId) s.drag = [];
    const person = this.b.people[String(memberId)];
    const isAgent = source === 'agent' || person?.kind === 'agent';
    if (!isAgent || !bbox) { this.changed(false); return; }
    const x = (bbox[0] + bbox[2]) / 2;
    const y = (bbox[1] + bbox[3]) / 2;
    const now = performance.now();
    const a = this.agents.get(memberId);
    this.agents.set(memberId, {
      id: memberId, name: person?.name ?? a?.name ?? 'Agent', colour: colourOf(memberId),
      fromX: a ? a.x : x, fromY: a ? a.y : y, x, y, moveAt: now, until: now + AGENT_STAYS, working: a?.working ?? false,
      flash: ids, flashUntil: now + 1200,
    });
    this.changed();
    this.tick();
  }

  /** The worker says an agent started or finished a turn on this board. */
  presence(p: { member_id: number; name: string; state: string }): void {
    const now = performance.now();
    const a = this.agents.get(p.member_id);
    if (p.state === 'working') {
      this.agents.set(p.member_id, a ? { ...a, working: true, name: p.name || a.name }
        : { id: p.member_id, name: p.name || 'Agent', colour: colourOf(p.member_id), x: NaN, y: NaN, fromX: NaN, fromY: NaN, moveAt: now, until: now, working: true, flash: [], flashUntil: 0 });
    } else if (a) {
      a.working = false;
      a.until = Math.max(a.until, now + AGENT_STAYS);
    }
    this.changed();
    this.tick();
  }

  /** Who is typing in this item, if anyone (the soft lock). */
  editingBy(itemId: string): Peer | null {
    for (const s of this.peers.values()) if (s.peer.editing === itemId) return s.peer;
    return null;
  }

  /** For the proofs (window.__boards): the peers as this canvas sees them, and the agents shown. */
  snapshot(): { peers: object[]; agents: object[] } {
    const now = performance.now();
    return {
      peers: [...this.peers.values()].map((s) => ({ member_id: s.peer.member_id, name: s.peer.name, client: s.peer.client, colour: s.peer.colour,
        cursor: s.cursor && now - s.cursor.at < CURSOR_IDLE ? { x: s.cursor.x, y: s.cursor.y } : null, selection: s.selection, dragging: s.drag.length,
        editing: s.peer.editing })),
      agents: [...this.agents.values()].filter((a) => a.working || a.until > now).map((a) => ({ member_id: a.id, name: a.name, x: a.x, y: a.y, working: a.working })),
    };
  }

  private changed(strip = true): void {
    if (strip) this.renderStrip();
    this.draw();
  }

  /** Keep animating while an agent flies, flashes or is about to fade. */
  private tick(): void {
    if (this.ticking) return;
    this.ticking = true;
    const step = () => {
      const now = performance.now();
      let busy = false;
      for (const [id, a] of this.agents) {
        if (!a.working && a.until <= now) { this.agents.delete(id); this.renderStrip(); continue; }
        if (now - a.moveAt < AGENT_FLIGHT + 50 || a.flashUntil > now) busy = true;
        if (!a.working) busy = true;                              // to fade it on time
      }
      this.draw();
      if (busy) requestAnimationFrame(step); else this.ticking = false;
    };
    requestAnimationFrame(step);
  }

  /** The presence layer's drawing, rebuilt (a handful of peers: cheaper than diffing). */
  private draw(): void {
    const z = this.b.view.camera.zoom;
    const now = performance.now();
    this.group.destroyChildren();
    for (const s of this.peers.values()) {
      const colour = s.peer.colour;
      for (const id of s.selection) {
        const box = this.b.store.box(id);
        if (box) this.group.add(new Konva.Rect({ x: box.x1 - 4 / z, y: box.y1 - 4 / z, width: box.x2 - box.x1 + 8 / z, height: box.y2 - box.y1 + 8 / z,
          stroke: colour, strokeWidth: 2 / z, listening: false }));
      }
      if (s.drag.length && now - s.dragAt < 3000) {
        for (const g of s.drag) this.group.add(new Konva.Rect({ x: g.x, y: g.y, width: g.width, height: g.height, offsetX: g.width / 2, offsetY: g.height / 2,
          rotation: g.rotation, fill: colour, opacity: 0.18, stroke: colour, strokeWidth: 1.5 / z, dash: [6 / z, 4 / z], listening: false, name: 'ghost' }));
      }
      if (s.peer.editing) {
        const box = this.b.store.box(s.peer.editing);
        if (box) this.group.add(this.label(`${s.peer.name.replace(/^SMOKE\s+/, '')} is editing`, colour, box.x1, box.y1 - 26 / z, z));
      }
      if (s.cursor && now - s.cursor.at < CURSOR_IDLE) {
        const g = new Konva.Group({ x: s.cursor.x, y: s.cursor.y, scaleX: 1 / z, scaleY: 1 / z, listening: false, name: 'cursor' });
        g.add(new Konva.Line({ points: [0, 0, 0, 17, 4.5, 13, 8, 20, 11, 19, 7.5, 12, 13, 12], closed: true, fill: colour, stroke: '#ffffff', strokeWidth: 1.2 }));
        g.add(this.label(s.peer.name.replace(/^SMOKE\s+/, ''), colour, 12, 18, 1));
        this.group.add(g);
      }
    }
    for (const a of this.agents.values()) {
      if (!Number.isFinite(a.x)) continue;
      const t = Math.min(1, (now - a.moveAt) / AGENT_FLIGHT);
      const e = 1 - Math.pow(1 - t, 3);
      const x = a.fromX + (a.x - a.fromX) * e;
      const y = a.fromY + (a.y - a.fromY) * e;
      const fade = a.working ? 1 : Math.max(0, Math.min(1, (a.until - now) / 800));
      if (a.flashUntil > now) {
        for (const id of a.flash) {
          const box = this.b.store.box(id);
          if (box) this.group.add(new Konva.Rect({ x: box.x1 - 3 / z, y: box.y1 - 3 / z, width: box.x2 - box.x1 + 6 / z, height: box.y2 - box.y1 + 6 / z,
            stroke: a.colour, strokeWidth: 3 / z, opacity: (a.flashUntil - now) / 1200, listening: false }));
        }
      }
      const g = new Konva.Group({ x, y, scaleX: 1 / z, scaleY: 1 / z, opacity: fade, listening: false, name: 'agent' });
      g.add(new Konva.Circle({ radius: 16, fill: a.colour, stroke: '#ffffff', strokeWidth: 2 }));
      g.add(new Konva.Text({ text: initials(a.name), fontSize: 12, fontStyle: 'bold', fill: '#ffffff', width: 32, x: -16, y: -6, align: 'center' }));
      g.add(this.label(`${a.name.replace(/^SMOKE\s+/, '')} · AI${a.working ? ' — working' : ''}`, a.colour, 20, -10, 1));
      this.group.add(g);
    }
    this.b.renderer.requestPresence();
  }

  private label(text: string, colour: string, x: number, y: number, z: number): Konva.Label {
    const l = new Konva.Label({ x, y, scaleX: 1 / z, scaleY: 1 / z, listening: false });
    l.add(new Konva.Tag({ fill: colour, cornerRadius: 4 }));
    l.add(new Konva.Text({ text, fontSize: 12, fontFamily: 'Inter, "Segoe UI", system-ui, sans-serif', fill: '#ffffff', padding: 4 }));
    return l;
  }

  /** The top bar's avatars: everyone else here (one per person, however many tabs), then the agents at work; two and "+N" at 375 px. */
  private renderStrip(): void {
    if (!this.strip) return;
    const seen = new Map<number, { name: string; colour: string; agent: boolean; editing: boolean }>();
    for (const s of this.peers.values()) {
      const prev = seen.get(s.peer.member_id);
      seen.set(s.peer.member_id, { name: s.peer.name, colour: s.peer.colour, agent: s.peer.kind === 'agent', editing: !!prev?.editing || !!s.peer.editing });
    }
    const now = performance.now();
    for (const a of this.agents.values()) if (a.working || a.until > now) seen.set(a.id, { name: a.name + (a.working ? ' — working' : ''), colour: a.colour, agent: true, editing: false });
    const all = [...seen.entries()];
    const room = window.matchMedia('(max-width: 991.98px)').matches ? 2 : 5;
    const shown = all.length > room + 1 ? all.slice(0, room) : all;
    this.strip.replaceChildren(...shown.map(([id, p]) => {
      const el = document.createElement('span');
      el.className = 'board-avatar' + (p.agent ? ' agent' : '') + (p.editing ? ' editing' : '');
      el.style.background = p.colour;
      el.title = p.name.replace(/^SMOKE\s+/, '') + (p.agent ? ' (AI agent)' : '');
      el.textContent = initials(p.name);
      el.dataset.member = String(id);
      return el;
    }));
    if (shown.length < all.length) {
      const more = document.createElement('span');
      more.className = 'board-avatar more';
      more.id = 'board-presence-more';
      more.textContent = `+${all.length - shown.length}`;
      more.title = all.slice(shown.length).map(([, p]) => p.name.replace(/^SMOKE\s+/, '')).join(', ');
      this.strip.appendChild(more);
    }
  }
}
