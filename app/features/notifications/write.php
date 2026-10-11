<?php
declare(strict_types=1);

/**
 * Notifications — the writes (slice 6). A person writes only their OWN rows: reading a notice and Mark all read are statements under `member_id = me`
 * (notification_mark_read() in queries.php), and preferences are one upserted row (prefs_save()). What is here is the request's side of prefs_save: which
 * fields changed, validated in words.
 */

/**
 * The preference fields a request changes against the current ones: email_enabled, text_enabled, digest (yes or no), kinds, text_kinds (a list — `name[]` or a comma
 * string). A field left out stays as it was. The settings form marks the fields it carries with `<name>__sent` so that an unchecked box (which posts nothing) reads as
 * no and an emptied list as none. Adds to $errors; answers only what differs from $cur.
 */
function prefs_changes_from_request(array $cur, array &$errors): array
{
    $f = [];
    foreach (['email_enabled', 'text_enabled', 'digest'] as $n) {
        if (req_has($n) || req_has($n . '__sent')) {
            $v = req_has($n) ? bd_yes($n) : false;
            if ($v !== $cur[$n]) {
                $f[$n] = $v;
            }
        }
    }
    $order = array_keys(NOTICE_KINDS);
    foreach (['kinds', 'text_kinds'] as $n) {
        if (!req_has($n) && !req_has($n . '__sent')) {
            continue;
        }
        $list = request_list($n) ?? [];
        if (array_diff($list, $order) !== []) {
            $errors[$n] = 'The kinds are ' . implode(', ', $order) . '.';
            continue;
        }
        $want = array_values(array_intersect($order, $list));
        if ($want !== array_values(array_intersect($order, $cur[$n]))) {
            $f[$n] = $want;
        }
    }
    return $f;
}
