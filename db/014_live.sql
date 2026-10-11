-- 014: LIVE (slice 3 — the second exemplar; docs/build-specs/live.md, live-relay.md): the relay's one more read. A socket's display name
-- and kind for presence come from the mirror, never from the client: bd_live_member() is SECURITY DEFINER and granted to the records role
-- alone — the role the relay connects as, which holds no write grant anywhere (with bd_live_check() and bd_live_batch(), db/006–007, it is
-- everything the relay may ask). An unknown or inactive member answers no row. Additive.
BEGIN;

CREATE OR REPLACE FUNCTION bd_live_member(p_member_id bigint) RETURNS TABLE (name text, kind text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT m.display_name, m.member_kind FROM members m WHERE m.id = p_member_id AND m.status = 'active';
$$;

REVOKE ALL ON FUNCTION bd_live_member(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION bd_live_member(bigint) TO boards_records_ro;

COMMIT;
