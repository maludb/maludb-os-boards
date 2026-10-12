<?php
declare(strict_types=1);

/** The JSON shape of the home page (slice 10) — whitelists built from the board and folder presenters, never a raw row. */

function present_home(array $s, array $templates, array $may): array
{
    return ['recent' => array_map('present_board_row', $s['recent']), 'favourites' => array_map('present_board_row', $s['favourites']),
            'shared' => array_map('present_board_row', $s['shared']), 'team_folders' => array_map('present_folder', $s['team_folders']),
            'mentions_waiting' => (int) $s['mentions_waiting'], 'first_run' => (bool) $s['first_run'],
            'templates' => $s['first_run'] ? array_map('present_template_row', $templates) : [], 'may' => $may];
}
