// The commit queue (canvas-core.md "The op protocol — On the client"): a gesture is ONE batch, applied optimistically with its local
// inverse kept, then POSTed; one batch in flight per tab, the rest queued in order. 200 → the store takes the answer's versions and the
// seq joins the applied set; a refusal (4xx) rolls the gesture back and shows the database's sentence; unreachable (network, 5xx) retries
// the SAME client_batch_id after 1, 3 and 10 s (the server answers a retry's first seq), then rolls back "Not saved — check your connection".
// The per-property last-writer rule lives here too: a remote change to a field this tab has a pending write on — or acknowledged at a
// later seq — is not applied over it.
import type { CommitAnswer, Op } from '../model/types';
import { uuid } from '../model/types';
import type { Store } from '../model/store';

export type BatchKind = 'gesture' | 'undo' | 'redo' | 'command';

export interface Pending {
  batchId: string;
  ops: Op[];
  localInverse: Op[];
  keys: string[];
  kind: BatchKind;
  tries: number;
  answer: Promise<CommitAnswer | null>;
  resolve: (a: CommitAnswer | null) => void;
}

export interface CommitHooks {
  onAnswer(p: Pending, a: CommitAnswer): void;
  onRefused(p: Pending, status: number, message: string): void;
  onLost(p: Pending): void;
  onIdle?(): void;
}

export interface CommitConfig { opsUrl: string; csrf: string; clientId: string; batchMax: number }

const RETRY_AFTER = [1000, 3000, 10000];

/** The field keys a batch writes: `x`, `style.fillColor`, `data.content` … (creates, deletes and restores write whole items). */
export function keysOf(ops: Op[]): string[] {
  const out: string[] = [];
  for (const op of ops) {
    if (op.op !== 'update') continue;
    for (const [k, v] of Object.entries(op.fields)) {
      if ((k === 'style' || k === 'data') && v && typeof v === 'object') for (const sk of Object.keys(v)) out.push(`${op.id}|${k}.${sk}`);
      else out.push(`${op.id}|${k}`);
    }
  }
  return out;
}

export class Committer {
  private queue: Pending[] = [];
  private inFlight: Pending | null = null;
  /** `${id}|${key}` → batches of mine not yet answered that write it */
  readonly pendingKeys = new Map<string, number>();
  /** `${id}|${key}` → the seq of my last acknowledged write of it */
  readonly ackedKeys = new Map<string, number>();
  stopped = false;

  constructor(private cfg: CommitConfig, private store: Store, private hooks: CommitHooks) {}

  /** Apply now, commit soon. Answers the pending batch (its `answer` resolves with the server's answer, or null when it did not land). */
  submit(ops: Op[], kind: BatchKind = 'gesture'): Pending | null {
    if (ops.length === 0) return null;
    const localInverse = this.store.applyWithInverse(ops);
    let resolve!: (a: CommitAnswer | null) => void;
    const answer = new Promise<CommitAnswer | null>((r) => { resolve = r; });
    const p: Pending = { batchId: uuid(), ops, localInverse, keys: keysOf(ops), kind, tries: 0, answer, resolve };
    for (const k of p.keys) this.pendingKeys.set(k, (this.pendingKeys.get(k) ?? 0) + 1);
    this.queue.push(p);
    this.pump();
    return p;
  }

  /** Whether a remote op's field should give way to mine. */
  yields(id: string, key: string, remoteSeq: number): boolean {
    const k = `${id}|${key}`;
    return (this.pendingKeys.get(k) ?? 0) > 0 || (this.ackedKeys.get(k) ?? 0) > remoteSeq;
  }

  busy(): boolean { return this.inFlight !== null || this.queue.length > 0; }

  private release(p: Pending, seq: number | null): void {
    for (const k of p.keys) {
      const n = (this.pendingKeys.get(k) ?? 1) - 1;
      if (n <= 0) this.pendingKeys.delete(k); else this.pendingKeys.set(k, n);
      if (seq !== null) this.ackedKeys.set(k, Math.max(seq, this.ackedKeys.get(k) ?? 0));
    }
  }

  private pump(): void {
    if (this.inFlight || this.stopped) return;
    const p = this.queue.shift();
    if (!p) { this.hooks.onIdle?.(); return; }
    this.inFlight = p;
    void this.send(p);
  }

  private async send(p: Pending): Promise<void> {
    let status = 0;
    let body: unknown = null;
    try {
      const res = await fetch(this.cfg.opsUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': this.cfg.csrf },
        body: JSON.stringify({ client_batch_id: p.batchId, client_id: this.cfg.clientId, ops: p.ops }),
      });
      status = res.status;
      body = await res.json().catch(() => null);
    } catch {
      status = 0;
    }
    if (status === 200 && body && typeof body === 'object') {
      const a = body as CommitAnswer;
      this.release(p, a.seq ?? null);
      this.store.setVersions(a.versions);
      this.inFlight = null;
      this.hooks.onAnswer(p, a);
      p.resolve(a);
      this.pump();
      return;
    }
    if (status >= 400 && status < 500) {
      const message = (body as { error?: { message?: string } } | null)?.error?.message
        ?? (status === 401 ? 'You are signed out: reload the page.' : 'That could not be saved.');
      this.release(p, null);
      this.store.apply(p.localInverse);
      this.inFlight = null;
      this.hooks.onRefused(p, status, message);
      p.resolve(null);
      this.pump();
      return;
    }
    // unreachable or a server error: the same batch id again, after 1, 3 and 10 s
    if (p.tries < RETRY_AFTER.length && !this.stopped) {
      const wait = RETRY_AFTER[p.tries++];
      setTimeout(() => { void this.send(p); }, wait);
      return;
    }
    this.release(p, null);
    this.store.apply(p.localInverse);
    this.inFlight = null;
    this.hooks.onLost(p);
    p.resolve(null);
    this.pump();
  }
}
