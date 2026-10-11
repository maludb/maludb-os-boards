// The per-person undo/redo (canvas-core.md "Undo/redo"; D8): a per-tab stack of the answers' {seq, inverse}, 100 deep. Undo posts the
// inverse as a NEW batch and pushes that batch's own inverse onto redo; any new gesture clears redo. Only batches this tab committed are on
// the stack — another person's change is never undone from here. (bd_undo_ops() is the agents' ops_undo.)
import type { CommitAnswer, Op } from '../model/types';
import type { Committer, Pending } from './commit';

interface Entry { answer: Promise<CommitAnswer | null> }

const DEPTH = 100;

export class UndoStack {
  private undos: Entry[] = [];
  private redos: Entry[] = [];

  constructor(private committer: Committer, private onChange: () => void) {}

  /** A gesture of mine was submitted: it can be undone once answered; redo is gone. */
  pushGesture(p: Pending): void {
    this.undos.push({ answer: p.answer });
    if (this.undos.length > DEPTH) this.undos.shift();
    this.redos = [];
    this.onChange();
  }

  canUndo(): boolean { return this.undos.length > 0; }
  canRedo(): boolean { return this.redos.length > 0; }

  async undo(): Promise<boolean> { return this.step(this.undos, this.redos, 'undo'); }
  async redo(): Promise<boolean> { return this.step(this.redos, this.undos, 'redo'); }

  /** Apply an answer's inverse now as its own undoable batch (the "You replaced …'s edit — Undo" toast). */
  async undoAnswer(a: CommitAnswer): Promise<void> {
    const p = this.committer.submit(this.cleanInverse(a.inverse ?? []), 'undo');
    if (p) { this.redos.push({ answer: p.answer }); this.onChange(); }
  }

  private async step(from: Entry[], to: Entry[], kind: 'undo' | 'redo'): Promise<boolean> {
    const e = from.pop();
    this.onChange();
    if (!e) return false;
    const a = await e.answer;
    if (!a || !a.inverse || a.inverse.length === 0) return false;
    const p = this.committer.submit(this.cleanInverse(a.inverse), kind);
    if (!p) return false;
    to.push({ answer: p.answer });
    if (to.length > DEPTH) to.shift();
    this.onChange();
    return true;
  }

  /** The server's inverse as ops to post again (a restore carries no item bodies; the database has them). */
  private cleanInverse(ops: Op[]): Op[] {
    return ops.map((o) => (o.op === 'restore' ? { op: 'restore', ids: o.ids } : o));
  }
}
