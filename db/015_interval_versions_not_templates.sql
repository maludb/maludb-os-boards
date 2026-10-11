-- Slice 7 (history-versions.md): the worker saves no interval version of a shipped template — a shipped template never changes, and the
-- history-versions spec says so. db/011's bd_interval_versions() without that one line; everything else is as it was (CREATE OR REPLACE
-- keeps the function's grants). Additive: nothing is dropped.
CREATE OR REPLACE FUNCTION bd_interval_versions() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b uuid; n integer := 0;
BEGIN
    PERFORM bd_require_worker();
    FOR b IN SELECT bo.id FROM boards bo
              WHERE bo.deleted_at IS NULL AND bo.last_seq > 0
                AND NOT (bo.is_template AND bo.template_scope = 'shipped')
                AND bo.last_seq > COALESCE((SELECT max(v.content_rev) FROM board_versions v WHERE v.board_id = bo.id), 0)
                AND COALESCE((SELECT max(v.created_at) FROM board_versions v WHERE v.board_id = bo.id), '-infinity') < now() - interval '1 hour' LOOP
        PERFORM bd_save_version(b, NULL, 'interval', NULL);
        n := n + 1;
    END LOOP;
    RETURN n;
END$$;
