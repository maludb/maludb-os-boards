// A file on the board (slice 5; pen-files.md "The canvas item"): a PDF with a preview shows its first page (the image's level of detail, a
// strip with its name below); any other file is a card — a coloured badge with its kind, its name, its size and type. Opened or downloaded
// through the one door from the Properties panel (or a double-click), behind the board's wall.
import type { Item } from '../../model/types';
import { FONT_FAMILY } from './text';
import { MIN_TEXT_PX } from '../lod';
import { bitmapFor, pendingCard } from './image';

export const CARD_SIZE: [number, number] = [320, 120];

const KINDS: Record<string, [string, string]> = {          // extension → [badge colour, words]
  pdf: ['#e03131', 'PDF document'], docx: ['#1971c2', 'Word document'], odt: ['#1971c2', 'Text document'],
  xlsx: ['#2f9e44', 'Excel workbook'], ods: ['#2f9e44', 'Spreadsheet'], csv: ['#2f9e44', 'CSV file'],
  pptx: ['#e8590c', 'PowerPoint deck'], odp: ['#e8590c', 'Presentation'],
  txt: ['#495057', 'Text file'], md: ['#495057', 'Markdown file'], zip: ['#7048e8', 'Zip archive'],
  png: ['#0c8599', 'Image'], jpg: ['#0c8599', 'Image'], gif: ['#0c8599', 'Image'], webp: ['#0c8599', 'Image'],
};

/** The stored type → its extension (a renamed title keeps its badge); the title's extension only when the type is unknown. */
const MIME_EXT: Record<string, string> = {
  'application/pdf': 'pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'pptx',
  'application/vnd.oasis.opendocument.text': 'odt', 'application/vnd.oasis.opendocument.spreadsheet': 'ods', 'application/vnd.oasis.opendocument.presentation': 'odp',
  'text/plain': 'txt', 'text/csv': 'csv', 'text/markdown': 'md', 'application/zip': 'zip',
  'image/png': 'png', 'image/jpeg': 'jpg', 'image/gif': 'gif', 'image/webp': 'webp',
};
export function extOf(item: Item): string {
  const byType = MIME_EXT[String(item.data.mime_type ?? '')];
  if (byType) return byType;
  const m = /\.([A-Za-z0-9]{1,8})$/.exec(String(item.data.title ?? ''));
  return m ? m[1].toLowerCase() : '';
}

export function bytesWords(n: number): string {
  if (!Number.isFinite(n) || n < 0) return '';
  if (n < 1024) return `${n} B`;
  if (n < 1024 * 1024) return `${Math.round(n / 1024)} kB`;
  return `${(n / 1024 / 1024).toFixed(n < 10 * 1024 * 1024 ? 1 : 0)} MB`;
}

function ellipsis(c: CanvasRenderingContext2D, s: string, max: number): string {
  if (c.measureText(s).width <= max) return s;
  let lo = 0, hi = s.length;
  while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (c.measureText(s.slice(0, mid) + '…').width <= max) lo = mid; else hi = mid - 1; }
  return s.slice(0, lo) + '…';
}

/** A document in its own box [0, w] × [0, h]. */
export function drawDocument(c: CanvasRenderingContext2D, item: Item, zoom: number): void {
  const w = Math.max(1, item.width), h = Math.max(1, item.height);
  if (item.data.preview === true) {
    const { img } = bitmapFor(item, zoom);
    if (img) c.drawImage(img, 0, 0, w, h); else pendingCard(c, w, h, zoom, '');
    c.strokeStyle = '#ced4da';
    c.lineWidth = 1 / zoom;
    c.strokeRect(0, 0, w, h);
    const band = 28;
    if (h > band * 2 && 12 * zoom >= MIN_TEXT_PX) {
      c.fillStyle = 'rgba(33,37,41,0.78)';
      c.fillRect(0, h - band, w, band);
      c.fillStyle = '#ffffff';
      c.font = `600 12px ${FONT_FAMILY}`;
      c.textAlign = 'left';
      c.textBaseline = 'middle';
      c.fillText(ellipsis(c, String(item.data.title ?? 'Document'), w - 20), 10, h - band / 2);
    }
    return;
  }
  const ext = extOf(item);
  const [colour, words] = KINDS[ext] ?? ['#868e96', 'File'];
  const r = Math.min(10, w / 8, h / 8);
  c.beginPath();
  c.roundRect(0, 0, w, h, r);
  c.fillStyle = '#ffffff';
  c.fill();
  c.strokeStyle = '#ced4da';
  c.lineWidth = 1 / zoom;
  c.stroke();
  const pad = Math.min(16, h / 6);
  const bw = Math.min(56, h - 2 * pad), bh = Math.min(68, h - 2 * pad);
  c.fillStyle = colour;
  c.beginPath();
  c.roundRect(pad, (h - bh) / 2, bw, bh, 6);
  c.fill();
  if (14 * zoom < MIN_TEXT_PX) return;
  c.fillStyle = '#ffffff';
  c.font = `700 ${Math.max(10, Math.min(14, bw / 3.6))}px ${FONT_FAMILY}`;
  c.textAlign = 'center';
  c.textBaseline = 'middle';
  c.fillText((ext || 'file').toUpperCase().slice(0, 4), pad + bw / 2, h / 2, bw - 6);
  const tx = pad + bw + 14, tw = w - tx - pad;
  if (tw < 20) return;
  c.textAlign = 'left';
  c.fillStyle = '#212529';
  c.font = `600 15px ${FONT_FAMILY}`;
  c.fillText(ellipsis(c, String(item.data.title ?? 'File'), tw), tx, h / 2 - 10);
  c.fillStyle = '#6c757d';
  c.font = `13px ${FONT_FAMILY}`;
  const size = bytesWords(Number(item.data.byte_size));
  c.fillText(ellipsis(c, [words, size].filter(Boolean).join(' · '), tw), tx, h / 2 + 12);
}
