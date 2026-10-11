// The z order's keys (canvas-core.md "Coordinates, frames and z"): fractional-index keys over [0-9A-Za-z] in BYTE order — board_items.z
// is COLLATE "C", and JavaScript compares these ASCII strings by code unit, which is the same order. rocicorp's fractional-indexing (MIT):
// a variable-length integer part ('a0', 'a1' … 'az', 'b00' …) then a fraction, so placing after the highest grows a key only every 62^k
// placements. The PHP twin is app/features/items/fractional.php — the same keys for the same inputs.

const DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
const SMALLEST_INTEGER = 'A' + '0'.repeat(26);

function midpoint(a: string, b: string | null): string {
  if (b !== null && a >= b) throw new Error(`${a} >= ${b}`);
  if (a.slice(-1) === '0' || (b !== null && b.slice(-1) === '0')) throw new Error('trailing zero');
  if (b !== null) {
    let n = 0;
    while (n < b.length && (a[n] ?? '0') === b[n]) n++;
    if (n > 0) return b.slice(0, n) + midpoint(a.slice(n), b.slice(n));
  }
  const da = a ? DIGITS.indexOf(a[0]) : 0;
  const db = b !== null ? DIGITS.indexOf(b[0]) : DIGITS.length;
  if (db - da > 1) return DIGITS[Math.round(0.5 * (da + db))];
  if (b !== null && b.length > 1) return b.slice(0, 1);
  return DIGITS[da] + midpoint(a.slice(1), null);
}

function integerLength(head: string): number {
  if (head >= 'a' && head <= 'z') return head.charCodeAt(0) - 97 + 2;
  if (head >= 'A' && head <= 'Z') return 90 - head.charCodeAt(0) + 2;
  throw new Error('invalid order key head: ' + head);
}

function integerPart(key: string): string {
  const len = integerLength(key[0] ?? '');
  if (len > key.length) throw new Error('invalid order key: ' + key);
  return key.slice(0, len);
}

function validate(key: string): void {
  if (!/^[0-9A-Za-z]{1,64}$/.test(key) || key === SMALLEST_INTEGER) throw new Error('invalid order key: ' + key);
  const i = integerPart(key);
  if (key.slice(i.length).slice(-1) === '0') throw new Error('invalid order key: ' + key);
}

function increment(x: string): string | null {
  const [head, ...digs] = x.split('');
  let carry = true;
  for (let i = digs.length - 1; carry && i >= 0; i--) {
    const d = DIGITS.indexOf(digs[i]) + 1;
    if (d === DIGITS.length) digs[i] = '0';
    else { digs[i] = DIGITS[d]; carry = false; }
  }
  if (!carry) return head + digs.join('');
  if (head === 'Z') return 'a0';
  if (head === 'z') return null;
  const h = String.fromCharCode(head.charCodeAt(0) + 1);
  if (h > 'a') digs.push('0'); else digs.pop();
  return h + digs.join('');
}

function decrement(x: string): string | null {
  const [head, ...digs] = x.split('');
  let borrow = true;
  for (let i = digs.length - 1; borrow && i >= 0; i--) {
    const d = DIGITS.indexOf(digs[i]) - 1;
    if (d === -1) digs[i] = 'z';
    else { digs[i] = DIGITS[d]; borrow = false; }
  }
  if (!borrow) return head + digs.join('');
  if (head === 'a') return 'Zz';
  if (head === 'A') return null;
  const h = String.fromCharCode(head.charCodeAt(0) - 1);
  if (h < 'Z') digs.push('z'); else digs.pop();
  return h + digs.join('');
}

export function isValidKey(key: string): boolean {
  try { validate(key); return true; } catch { return false; }
}

/** A key strictly between a and b (null = open-ended). */
export function keyBetween(a: string | null, b: string | null): string {
  if (a !== null) validate(a);
  if (b !== null) validate(b);
  if (a !== null && b !== null && a >= b) throw new Error(`${a} >= ${b}`);
  if (a === null) {
    if (b === null) return 'a0';
    const ib = integerPart(b);
    const fb = b.slice(ib.length);
    if (ib === SMALLEST_INTEGER) return ib + midpoint('', fb);
    if (ib < b) return ib;
    const res = decrement(ib);
    if (res === null) throw new Error('cannot decrement any more');
    return res;
  }
  if (b === null) {
    const ia = integerPart(a);
    const i = increment(ia);
    return i === null ? ia + midpoint(a.slice(ia.length), null) : i;
  }
  const ia = integerPart(a);
  const ib = integerPart(b);
  if (ia === ib) return ia + midpoint(a.slice(ia.length), b.slice(ib.length));
  const i = increment(ia);
  if (i === null) throw new Error('cannot increment any more');
  return i < b ? i : ia + midpoint(a.slice(ia.length), null);
}

/** A key after `a` that survives a key that is not ours (the column admits any [0-9A-Za-z]{1,64}): the PHP twin's z_after rule. */
export function keyAfter(a: string | null): string {
  if (a === null) return 'a0';
  return isValidKey(a) ? keyBetween(a, null) : a.slice(0, 60) + 'zzzV';
}

/** A key before `b`, or null when b is not an order key of ours (the caller keeps the item's place). */
export function keyBefore(b: string | null): string | null {
  if (b === null) return 'a0';
  return isValidKey(b) ? keyBetween(null, b) : null;
}
