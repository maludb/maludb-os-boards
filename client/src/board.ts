// The island's parts as one context the tools and the UI share (main.ts builds it). Nothing here decides a rule: `editable` only hides
// what the database would refuse anyway (a viewer's forged POST is 42501 → 403).
import type { Item, Op, Person, Level } from './model/types';
import type { Store } from './model/store';
import type { View } from './render/stage';
import type { Renderer } from './render/layers';
import type { Committer, Pending, BatchKind } from './sync/commit';
import type { UndoStack } from './sync/undo';
import type { Selection } from './tools/select';
import type { Toasts } from './ui/toast';

export type ToolName = 'select' | 'hand' | 'sticky' | 'shape' | 'text';

export interface Board {
  boardId: string;
  mount: HTMLElement;
  store: Store;
  view: View;
  renderer: Renderer;
  committer: Committer;
  undo: UndoStack;
  selection: Selection;
  toasts: Toasts;
  level: Level;
  /** edit, and full on a locked board */
  editable: boolean;
  memberId: number;
  people: Record<string, Person>;
  batchMax: number;
  /** the shape the shape tool draws */
  shapeKind: string;
  /** commit a gesture of mine: applied now, undoable, the poll kicked after */
  commit(ops: Op[], kind?: BatchKind): Pending | null;
  setTool(t: ToolName): void;
  tool(): ToolName;
  editText(id: string, created?: boolean): void;
  /** the last pointer position on the canvas (paste goes there) */
  pointer: { x: number; y: number } | null;
  fire(name: string, detail: unknown): void;
  /** the frame (topmost) whose box holds the canvas point, not one of `except` */
  frameAt(x: number, y: number, except?: Set<string>): Item | null;
}
