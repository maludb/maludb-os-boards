// The catch-up (canvas-core.md "Catch-up"): GET ops?after=<last seq> → the batches in seq order (≤ 500 a page, `more` asks again). Because
// bd_commit_ops() takes the board's row lock first, seq order IS commit order within a board, so after=<last seq> is exact. Remote batches
// are applied in arrival order, skipped when they are my own echo (my client_id — the POST's answer applied them) or already applied (the
// set of the last 2,000 seqs). `resync` (the seq is older than the retained ops) reloads the state.
import type { BoardState, RemoteBatch } from '../model/types';
import type { Store } from '../model/store';
import type { Committer } from './commit';

export interface CatchupHooks {
  onApplied(b: RemoteBatch): void;
  onResync(state: BoardState): void;
}

const APPLIED_KEEP = 2000;

export class Catchup {
  lastSeq: number;
  private appliedSet = new Set<number>();
  private appliedOrder: number[] = [];
  private pulling: Promise<void> | null = null;
  private waiters: { seq: number; resolve: () => void }[] = [];

  constructor(private cfg: { opsUrl: string; stateUrl: string; clientId: string }, private store: Store, private committer: Committer,
              private hooks: CatchupHooks, lastSeq: number) {
    this.lastSeq = lastSeq;
  }

  applied(): number[] { return [...this.appliedOrder]; }
  hasApplied(seq: number): boolean { return this.appliedSet.has(seq); }

  markApplied(seq: number): void {
    if (!seq || this.appliedSet.has(seq)) return;
    this.appliedSet.add(seq);
    this.appliedOrder.push(seq);
    if (this.appliedOrder.length > APPLIED_KEEP) this.appliedSet.delete(this.appliedOrder.shift()!);
    this.wake();
  }

  /** A promise that resolves when this seq has been applied here (mine or another's). */
  waitFor(seq: number): Promise<void> {
    if (this.appliedSet.has(seq) || seq <= this.lastSeq) return Promise.resolve();
    return new Promise((resolve) => this.waiters.push({ seq, resolve }));
  }

  private wake(): void {
    this.waiters = this.waiters.filter((w) => {
      if (this.appliedSet.has(w.seq) || w.seq <= this.lastSeq) { w.resolve(); return false; }
      return true;
    });
  }

  /** One remote batch, in arrival order. */
  applyRemote(b: RemoteBatch): void {
    if (b.client_id === this.cfg.clientId || this.appliedSet.has(b.seq)) {
      this.markApplied(b.seq);
      if (b.seq > this.lastSeq) this.lastSeq = b.seq;
      this.wake();
      return;
    }
    this.store.apply(b.ops, (id, key) => this.committer.yields(id, key, b.seq));
    this.markApplied(b.seq);
    if (b.seq > this.lastSeq) this.lastSeq = b.seq;
    this.hooks.onApplied(b);
    this.wake();
  }

  /** Ask for everything after the last seq (and again while there is more). One pull at a time. */
  pull(): Promise<void> {
    if (this.pulling) return this.pulling;
    this.pulling = this.doPull().finally(() => { this.pulling = null; });
    return this.pulling;
  }

  private async doPull(): Promise<void> {
    for (let pages = 0; pages < 20; pages++) {
      const res = await fetch(`${this.cfg.opsUrl}?after=${this.lastSeq}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error(`ops ${res.status}`);
      const page = await res.json() as { batches: RemoteBatch[]; last_seq: number; resync: boolean; more: boolean };
      if (page.resync) { await this.reload(); return; }
      for (const b of page.batches) this.applyRemote(b);
      if (page.batches.length === 0 && page.last_seq > this.lastSeq) this.lastSeq = page.last_seq;
      this.wake();
      if (!page.more) return;
    }
  }

  /** The whole state again (a resync, a version restored — boards:reload). */
  async reload(): Promise<BoardState> {
    const res = await fetch(this.cfg.stateUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (!res.ok) throw new Error(`state ${res.status}`);
    const state = await res.json() as BoardState;
    this.store.load(state.items);
    this.lastSeq = state.last_seq;
    this.hooks.onResync(state);
    this.wake();
    return state;
  }
}
