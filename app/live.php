<?php
declare(strict_types=1);

/**
 * The live relay's ticket (docs/build-specs/live-relay.md "The wire"; live.md "The ticket"): `{member}.board:{uuid}.{level}.{exp}.{nonce}.{hmac}`
 * — HMAC-SHA256 over the first five parts under BOARDS_LIVE_KEY (the hex string itself, as bytes), valid 60 seconds and accepted ONCE by the
 * relay (live/relay.py verify_ticket). The nonce (slice 3, decision 1): without it two tickets minted for one person in the same second were
 * the same string — a second tab, or a reconnect in the page's first second, refused as a reuse. Minted for the signed-in member at their level now, by board-view into the page and by the ticket
 * door on every reconnect. Never logged; never in a JSON answer but the ticket door's own.
 */

function bd_live_ticket(int $memberId, string $boardId, string $level, int $ttl = 60): string
{
    $key = (string) env('BOARDS_LIVE_KEY', '');
    if (strlen($key) < 32) {
        throw new RuntimeException('BOARDS_LIVE_KEY is not configured.');
    }
    $body = $memberId . '.board:' . strtolower($boardId) . '.' . $level . '.' . (time() + $ttl) . '.' . bin2hex(random_bytes(8));
    return $body . '.' . hash_hmac('sha256', $body, $key);
}

/** Live updates on (bd_settings.live_enabled) and a key to sign with: false → no ticket, the island polls from the start. */
function live_enabled(PDO $pdo): bool
{
    return strlen((string) env('BOARDS_LIVE_KEY', '')) >= 32 && db_bool($pdo, "SELECT bd_setting_bool('live_enabled')");
}
