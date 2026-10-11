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
import type { LiveConnection } from './sync/socket';
import type { Presence } from './ui/presence';

export type ToolName = 'select' | 'hand' | 'sticky' | 'shape' | 'text' | 'connector' | 'frame' | 'pen' | 'highlighter' | 'eraser' | 'comment';

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
  /** the live socket (slice 3) — sends what this tab is doing; null until it is made */
  live: LiveConnection | null;
  presence: Presence | null;
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
  /** a frame's title or a connector's caption, in place (slice 4) */
  editInline(id: string): void;
  /** the pen's colour and width, the highlighter's colour (slice 5: the pen menu) */
  pen: { color: string; width: number };
  highlighterColor: string;
  /** bd_settings.drawing_max_points — a stroke is simplified to at most this many points */
  drawingMaxPoints: number;
  /** the toolbar's Image button: the file picker (slice 5) */
  pickFile(): void;
  /** upload files and place them (a drop point, else the pointer or the view's centre) — the items placed */
  upload(files: File[], at: { x: number; y: number } | null): Promise<string[]>;
  /** the last pointer position on the canvas (paste goes there) */
  pointer: { x: number; y: number } | null;
  /** comments (slice 6): start a thread at a canvas point; open one in the rail */
  comments: { startThread(x: number, y: number): void; openThread(id: string): Promise<void> } | null;
  fire(name: string, detail: unknown): void;
  /** the frame (topmost) whose box holds the canvas point, not one of `except` */
  frameAt(x: number, y: number, except?: Set<string>): Item | null;
}
