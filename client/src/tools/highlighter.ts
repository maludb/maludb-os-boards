// Highlighter (slice 5; pen-files.md "Drawing"): the pen with a 24-wide stroke at 40 % that multiplies with what is under it (so text stays
// readable), its own colour; the stroke is a `drawing` with `data.tool = highlighter`.
import { PenTool, type StrokeStyle } from './pen';

export const HIGHLIGHTER_WIDTH = 24;

export class HighlighterTool extends PenTool {
  readonly name: string = 'highlighter';
  protected style(): StrokeStyle { return { tool: 'highlighter', color: this.b.highlighterColor, width: HIGHLIGHTER_WIDTH }; }
}
