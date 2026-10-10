<?php
declare(strict_types=1);

/**
 * What the shell reads on every render (sso-shell.md "Query functions"): the bell's count (mcp_notifications) and the business name. Each is
 * cached for the request — one query per render. Every read goes through a view that applies the wall; nothing here re-implements a rule.
 */

/** The bell: how many of my notifications are unread (mcp_notifications answers only the caller's own). */
function bell_count(PDO $pdo, int $memberId): int
{
    static $cache = null;
    return $cache ??= (int) one_value($pdo, 'SELECT count(*) FROM mcp_notifications WHERE read_at IS NULL');
}

/** The business's name on the header (bd_settings.business_name via mcp_settings), else the application's. */
function business_name(PDO $pdo): string
{
    static $cache = null;
    return $cache ??= (string) (one_value($pdo, 'SELECT business_name FROM mcp_settings') ?: app_name());
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}

/** A level as its badge (the locked vocabulary, walls-and-sharing.md): [class, words]. The owner's `full` reads "Owner". */
function level_badge(string $level, bool $isOwner = false): ?array
{
    return match ($level) {
        'full'    => ['text-bg-dark', $isOwner ? 'Owner' : 'Full'],
        'edit'    => ['text-bg-success', 'Can edit'],
        'comment' => ['text-bg-info', 'Can comment'],
        'view'    => ['text-bg-secondary', 'Can view'],
        default   => null,
    };
}
