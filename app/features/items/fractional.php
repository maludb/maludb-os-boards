<?php
declare(strict_types=1);

/**
 * The z order's keys (canvas-core.md "Coordinates, frames and z"): fractional-index keys over [0-9A-Za-z] in BYTE order — the column is
 * COLLATE "C" (db/007), and 0-9 < A-Z < a-z in bytes. The algorithm is rocicorp's fractional-indexing (MIT): a variable-length integer
 * part ('a0', 'a1' … 'az', 'b00' …) then a fraction, so a key placed after the highest grows by a character only every 62^k placements —
 * a plain midpoint would grow one character every few stickies and pass the column's 64 characters within a few hundred. The client's
 * twin is client/src/model/fractional.ts: the SAME keys for the same inputs (tests/phase3/slice2/ops.php compares them).
 */

const Z_DIGITS = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

/** A key strictly between $a and $b (either null = open-ended). Throws InvalidArgumentException on keys that are not order keys or a >= b. */
function z_between(?string $a, ?string $b): string
{
    if ($a !== null) { z_validate($a); }
    if ($b !== null) { z_validate($b); }
    if ($a !== null && $b !== null && strcmp($a, $b) >= 0) {
        throw new InvalidArgumentException("$a >= $b");
    }
    if ($a === null) {
        if ($b === null) {
            return 'a0';
        }
        $ib = z_integer_part($b);
        $fb = substr($b, strlen($ib));
        if ($ib === 'A' . str_repeat('0', 26)) {
            return $ib . z_midpoint('', $fb);
        }
        if (strcmp($ib, $b) < 0) {
            return $ib;
        }
        $res = z_decrement($ib);
        if ($res === null) {
            throw new InvalidArgumentException('cannot decrement any more');
        }
        return $res;
    }
    if ($b === null) {
        $ia = z_integer_part($a);
        $fa = substr($a, strlen($ia));
        $i = z_increment($ia);
        return $i === null ? $ia . z_midpoint($fa, null) : $i;
    }
    $ia = z_integer_part($a);
    $fa = substr($a, strlen($ia));
    $ib = z_integer_part($b);
    $fb = substr($b, strlen($ib));
    if ($ia === $ib) {
        return $ia . z_midpoint($fa, $fb);
    }
    $i = z_increment($ia);
    if ($i === null) {
        throw new InvalidArgumentException('cannot increment any more');
    }
    return strcmp($i, $b) < 0 ? $i : $ia . z_midpoint($fa, null);
}

/** The key after $a (a batch's next new item), surviving a key that is not ours: the column admits any [0-9A-Za-z]{1,64}. */
function z_next(?string $a): string
{
    if ($a === null) {
        return 'a0';
    }
    return z_is_valid($a) ? z_between($a, null) : substr($a, 0, 60) . 'zzzV';
}

/** True when $key is an order key this algorithm reads (the column admits any [0-9A-Za-z]{1,64}; only ours are written). */
function z_is_valid(string $key): bool
{
    try {
        z_validate($key);
        return true;
    } catch (InvalidArgumentException) {
        return false;
    }
}

/** A key after the board's highest live item (byte order). An unreadable highest key (not ours) gets a key that still sorts after it. */
function z_after(PDO $pdo, string $boardId): string
{
    $max = one_value($pdo, 'SELECT max(z) FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL', ['b' => $boardId]);
    return z_next($max === null || $max === false ? null : (string) $max);
}

/** A key before the board's lowest live item. */
function z_before(PDO $pdo, string $boardId): string
{
    $min = one_value($pdo, 'SELECT min(z) FROM board_items WHERE board_id = CAST(:b AS uuid) AND deleted_at IS NULL', ['b' => $boardId]);
    if ($min === null || $min === false) {
        return 'a0';
    }
    if (!z_is_valid((string) $min)) {
        throw new DomainException('The board\'s lowest item has an order key this cannot go below.');
    }
    return z_between(null, (string) $min);
}

// ---- the algorithm's parts ------------------------------------------------------------------

function z_midpoint(string $a, ?string $b): string
{
    if ($b !== null && strcmp($a, $b) >= 0) {
        throw new InvalidArgumentException("$a >= $b");
    }
    if (($a !== '' && $a[-1] === '0') || ($b !== null && $b !== '' && $b[-1] === '0')) {
        throw new InvalidArgumentException('trailing zero');
    }
    if ($b !== null) {
        // the longest common prefix, padding $a with zeros as we go
        $n = 0;
        while ($n < strlen($b) && ($a[$n] ?? '0') === $b[$n]) {
            $n++;
        }
        if ($n > 0) {
            return substr($b, 0, $n) . z_midpoint(substr($a, $n), substr($b, $n));
        }
    }
    $da = $a !== '' ? strpos(Z_DIGITS, $a[0]) : 0;
    $db = $b !== null ? strpos(Z_DIGITS, $b[0]) : strlen(Z_DIGITS);
    if ($db - $da > 1) {
        return Z_DIGITS[(int) round(0.5 * ($da + $db))];
    }
    if ($b !== null && strlen($b) > 1) {
        return substr($b, 0, 1);
    }
    return Z_DIGITS[$da] . z_midpoint(substr($a, 1), null);
}

function z_integer_length(string $head): int
{
    if ($head >= 'a' && $head <= 'z') {
        return ord($head) - ord('a') + 2;
    }
    if ($head >= 'A' && $head <= 'Z') {
        return ord('Z') - ord($head) + 2;
    }
    throw new InvalidArgumentException('invalid order key head: ' . $head);
}

function z_integer_part(string $key): string
{
    $len = z_integer_length($key[0] ?? '');
    if ($len > strlen($key)) {
        throw new InvalidArgumentException('invalid order key: ' . $key);
    }
    return substr($key, 0, $len);
}

function z_validate(string $key): void
{
    if ($key === '' || !preg_match('/^[0-9A-Za-z]{1,64}$/', $key) || $key === 'A' . str_repeat('0', 26)) {
        throw new InvalidArgumentException('invalid order key: ' . $key);
    }
    $i = z_integer_part($key);
    $f = substr($key, strlen($i));
    if ($f !== '' && $f[-1] === '0') {
        throw new InvalidArgumentException('invalid order key: ' . $key);
    }
}

function z_increment(string $x): ?string
{
    if (strlen($x) !== z_integer_length($x[0])) {
        throw new InvalidArgumentException('invalid integer part: ' . $x);
    }
    $head = $x[0];
    $digs = str_split(substr($x, 1));
    $carry = true;
    for ($i = count($digs) - 1; $carry && $i >= 0; $i--) {
        $d = strpos(Z_DIGITS, $digs[$i]) + 1;
        if ($d === strlen(Z_DIGITS)) {
            $digs[$i] = '0';
        } else {
            $digs[$i] = Z_DIGITS[$d];
            $carry = false;
        }
    }
    if ($carry) {
        if ($head === 'Z') {
            return 'a0';
        }
        if ($head === 'z') {
            return null;
        }
        $h = chr(ord($head) + 1);
        if ($h > 'a') {
            $digs[] = '0';
        } else {
            array_pop($digs);
        }
        return $h . implode('', $digs);
    }
    return $head . implode('', $digs);
}

function z_decrement(string $x): ?string
{
    if (strlen($x) !== z_integer_length($x[0])) {
        throw new InvalidArgumentException('invalid integer part: ' . $x);
    }
    $head = $x[0];
    $digs = str_split(substr($x, 1));
    $borrow = true;
    for ($i = count($digs) - 1; $borrow && $i >= 0; $i--) {
        $d = strpos(Z_DIGITS, $digs[$i]) - 1;
        if ($d === -1) {
            $digs[$i] = 'z';
        } else {
            $digs[$i] = Z_DIGITS[$d];
            $borrow = false;
        }
    }
    if ($borrow) {
        if ($head === 'a') {
            return 'Zz';
        }
        if ($head === 'A') {
            return null;
        }
        $h = chr(ord($head) - 1);
        if ($h < 'Z') {
            $digs[] = 'z';
        } else {
            array_pop($digs);
        }
        return $h . implode('', $digs);
    }
    return $head . implode('', $digs);
}
