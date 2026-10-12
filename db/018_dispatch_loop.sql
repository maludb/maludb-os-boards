-- 018: THE DISPATCH LOOP — what the worker's `dispatches` pass reads and writes (docs/build-specs/agents-search.md; Spaces db/016 + db/022 ported:
-- sp_ → bd_, messages → comments, channels → boards). Functions only, additive. A mention of an agent made a dispatch row in db/011's
-- bd_comment_create(); here the worker is offered what is due, what is running and what waits for a person's approval, and records the
-- outcome through ONE function — the placeholder comment, the reply as one row, the backoff, the five attempts and who is told are THE
-- DATABASE'S; PHP never decides whether an agent should answer. Every function is the worker's alone (no member set: bd_require_worker()).
BEGIN;

-- The thread so far: the root and its live replies written before p_before, the last p_n of them, oldest first, one line each.
CREATE OR REPLACE FUNCTION bd_thread_text(p_root uuid, p_before timestamptz, p_n integer) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE(string_agg(t.line, E'\n' ORDER BY t.at, t.id), '')
      FROM (SELECT c.created_at AS at, c.id,
                   COALESCE(m.display_name, 'Someone') || ': ' || left(replace(c.plain_text, E'\n', ' '), 300) AS line
              FROM comments c LEFT JOIN members m ON m.id = c.author_member_id
             WHERE (c.id = p_root OR c.parent_comment_id = p_root) AND c.deleted_at IS NULL AND c.created_at < p_before
             ORDER BY c.created_at DESC, c.id DESC LIMIT greatest(1, p_n)) t;
$$;

-- The one reader behind the three offers: p_state 'due' (a mention not yet sent: no run), 'running' (a run kept, due for a poll) or
-- 'waiting' (awaiting a person's approval, due for a poll). The board and the asking comment must be live.
CREATE OR REPLACE FUNCTION bd_dispatch_rows(p_state text, p_limit integer, p_now timestamptz)
    RETURNS TABLE (dispatch_id bigint, agent_member_id bigint, agent_name text, acting_member_id bigint, acting_name text, board_id uuid, board_title text,
                   comment_id uuid, root_id uuid, conversation_id text, utterance text, anchor_item_id uuid, anchor_excerpt text, anchor_x float8,
                   anchor_y float8, context_items jsonb, thread text, attempts integer, run_id bigint, request_id text, started_at timestamptz)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    PERFORM bd_require_worker();
    RETURN QUERY
    SELECT d.id, d.agent_member_id, ag.display_name::text, d.acting_member_id, ac.display_name::text, d.board_id, b.title::text,
           c.id, COALESCE(c.parent_comment_id, c.id), d.conversation_id, c.plain_text,
           r.item_id, CASE WHEN ri.id IS NULL THEN NULL ELSE left(COALESCE(NULLIF(ri.plain_text, ''), ri.data->>'title', ri.type), 80) END, r.anchor_x, r.anchor_y,
           COALESCE((SELECT jsonb_agg(jsonb_build_object('item_id', i.id, 'type', i.type,
                                                         'excerpt', left(COALESCE(NULLIF(i.plain_text, ''), i.data->>'title', ''), 80)) ORDER BY u.ord)
                       FROM unnest(c.context_item_ids) WITH ORDINALITY u(iid, ord)
                       JOIN board_items i ON i.id = u.iid AND i.board_id = c.board_id AND i.deleted_at IS NULL), '[]'::jsonb),
           bd_thread_text(COALESCE(c.parent_comment_id, c.id), c.created_at, 12), d.attempts, d.run_id, d.request_id,
           COALESCE(pc.created_at, d.created_at)
      FROM agent_dispatches d
      JOIN comments c ON c.id = d.record_uuid AND d.record_type = 'comment' AND c.deleted_at IS NULL
      JOIN comments r ON r.id = COALESCE(c.parent_comment_id, c.id)
      JOIN boards b ON b.id = c.board_id AND b.deleted_at IS NULL
      JOIN members ag ON ag.id = d.agent_member_id
      LEFT JOIN members ac ON ac.id = d.acting_member_id
      LEFT JOIN board_items ri ON ri.id = r.item_id AND ri.deleted_at IS NULL
      LEFT JOIN comments pc ON pc.id = d.pending_comment_id
     WHERE d.next_attempt_at <= p_now
       AND CASE p_state WHEN 'due'     THEN d.status = 'sent' AND d.run_id IS NULL
                        WHEN 'running' THEN d.status = 'sent' AND d.run_id IS NOT NULL
                        WHEN 'waiting' THEN d.status = 'awaiting_approval' AND d.run_id IS NOT NULL
                        ELSE false END
     ORDER BY CASE WHEN p_state = 'due' THEN d.created_at ELSE d.next_attempt_at END, d.id
     LIMIT greatest(1, p_limit);
END$$;

CREATE OR REPLACE FUNCTION bd_dispatches_due(p_limit integer, p_now timestamptz)
    RETURNS TABLE (dispatch_id bigint, agent_member_id bigint, agent_name text, acting_member_id bigint, acting_name text, board_id uuid, board_title text,
                   comment_id uuid, root_id uuid, conversation_id text, utterance text, anchor_item_id uuid, anchor_excerpt text, anchor_x float8,
                   anchor_y float8, context_items jsonb, thread text, attempts integer)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT x.dispatch_id, x.agent_member_id, x.agent_name, x.acting_member_id, x.acting_name, x.board_id, x.board_title, x.comment_id, x.root_id,
           x.conversation_id, x.utterance, x.anchor_item_id, x.anchor_excerpt, x.anchor_x, x.anchor_y, x.context_items, x.thread, x.attempts
      FROM bd_dispatch_rows('due', p_limit, p_now) x;
$$;

CREATE OR REPLACE FUNCTION bd_dispatches_running(p_limit integer, p_now timestamptz)
    RETURNS TABLE (dispatch_id bigint, agent_member_id bigint, agent_name text, acting_member_id bigint, acting_name text, board_id uuid, board_title text,
                   comment_id uuid, root_id uuid, conversation_id text, utterance text, anchor_item_id uuid, anchor_excerpt text, anchor_x float8,
                   anchor_y float8, context_items jsonb, thread text, attempts integer, run_id bigint, started_at timestamptz, request_id text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT x.dispatch_id, x.agent_member_id, x.agent_name, x.acting_member_id, x.acting_name, x.board_id, x.board_title, x.comment_id, x.root_id,
           x.conversation_id, x.utterance, x.anchor_item_id, x.anchor_excerpt, x.anchor_x, x.anchor_y, x.context_items, x.thread, x.attempts,
           x.run_id, x.started_at, x.request_id
      FROM bd_dispatch_rows('running', p_limit, p_now) x;
$$;

CREATE OR REPLACE FUNCTION bd_dispatches_waiting(p_limit integer, p_now timestamptz)
    RETURNS TABLE (dispatch_id bigint, agent_member_id bigint, agent_name text, acting_member_id bigint, acting_name text, board_id uuid, board_title text,
                   comment_id uuid, root_id uuid, conversation_id text, utterance text, anchor_item_id uuid, anchor_excerpt text, anchor_x float8,
                   anchor_y float8, context_items jsonb, thread text, attempts integer, run_id bigint, started_at timestamptz, request_id text)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT x.dispatch_id, x.agent_member_id, x.agent_name, x.acting_member_id, x.acting_name, x.board_id, x.board_title, x.comment_id, x.root_id,
           x.conversation_id, x.utterance, x.anchor_item_id, x.anchor_excerpt, x.anchor_x, x.anchor_y, x.context_items, x.thread, x.attempts,
           x.run_id, x.started_at, x.request_id
      FROM bd_dispatch_rows('waiting', p_limit, p_now) x;
$$;

-- The worker's record of a dispatch. Answers the placeholder's / reply's comment id (running, awaiting_approval, answered, refused) or NULL (failed).
CREATE OR REPLACE FUNCTION bd_dispatch_record(p_dispatch_id bigint, p_status text, p_run_id bigint, p_request_id text, p_reply jsonb, p_detail text,
                                              p_now timestamptz) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE d agent_dispatches%ROWTYPE; c comments%ROWTYPE; v_root uuid; v_agent text; v_board text; v_cid uuid; v_text text; v_n bigint; v_plain text;
BEGIN
    PERFORM bd_require_worker();
    IF p_status NOT IN ('running', 'awaiting_approval', 'answered', 'refused', 'failed') THEN
        RAISE EXCEPTION 'A dispatch is recorded as running, awaiting_approval, answered, refused or failed.' USING ERRCODE = 'invalid_parameter_value';
    END IF;
    SELECT * INTO d FROM agent_dispatches WHERE id = p_dispatch_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'There is no such dispatch.' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO c FROM comments WHERE id = d.record_uuid;
    IF NOT FOUND THEN RAISE EXCEPTION 'The comment that asked is gone.' USING ERRCODE = 'no_data_found'; END IF;
    v_root := COALESCE(c.parent_comment_id, c.id);
    SELECT display_name INTO v_agent FROM members WHERE id = d.agent_member_id;
    SELECT title INTO v_board FROM boards WHERE id = c.board_id;
    v_agent := COALESCE(v_agent, 'The agent');

    IF p_status IN ('running', 'awaiting_approval') THEN
        v_text := CASE p_status WHEN 'running' THEN v_agent || ' is working…' ELSE v_agent || ' is waiting for a person''s approval…' END;
        v_cid := d.pending_comment_id;
        IF v_cid IS NULL OR NOT EXISTS (SELECT 1 FROM comments WHERE id = v_cid AND deleted_at IS NULL) THEN
            INSERT INTO comments (board_id, parent_comment_id, author_member_id, body, agent_run_id)
            VALUES (c.board_id, v_root, d.agent_member_id, bd_rich_text(v_text), COALESCE(p_run_id, d.run_id)) RETURNING id INTO v_cid;
        ELSE
            UPDATE comments SET body = bd_rich_text(v_text), agent_run_id = COALESCE(p_run_id, agent_run_id) WHERE id = v_cid AND body IS DISTINCT FROM bd_rich_text(v_text);
        END IF;
        IF p_status = 'running' THEN
            UPDATE agent_dispatches SET run_id = COALESCE(p_run_id, run_id), request_id = COALESCE(p_request_id, request_id), pending_comment_id = v_cid,
                   next_attempt_at = p_now + interval '20 seconds' WHERE id = p_dispatch_id;
        ELSE
            UPDATE agent_dispatches SET status = 'awaiting_approval', run_id = COALESCE(p_run_id, run_id), request_id = COALESCE(p_request_id, request_id),
                   detail = COALESCE(p_detail, detail), pending_comment_id = v_cid, next_attempt_at = p_now + interval '1 minute' WHERE id = p_dispatch_id;
        END IF;
        RETURN v_cid;

    ELSIF p_status = 'answered' THEN
        v_plain := bd_rich_text_plain(p_reply);
        IF NOT bd_is_rich_text(p_reply) OR jsonb_array_length(p_reply) = 0 OR btrim(v_plain) = '' THEN
            RAISE EXCEPTION 'A reply has words.' USING ERRCODE = 'invalid_parameter_value';
        END IF;
        v_cid := d.pending_comment_id;
        IF v_cid IS NOT NULL AND EXISTS (SELECT 1 FROM comments WHERE id = v_cid AND deleted_at IS NULL) THEN
            UPDATE comments SET body = p_reply, agent_run_id = COALESCE(p_run_id, agent_run_id) WHERE id = v_cid;
        ELSE
            INSERT INTO comments (board_id, parent_comment_id, author_member_id, body, agent_run_id)
            VALUES (c.board_id, v_root, d.agent_member_id, p_reply, COALESCE(p_run_id, d.run_id)) RETURNING id INTO v_cid;
        END IF;
        UPDATE agent_dispatches SET status = 'answered', run_id = COALESCE(p_run_id, run_id), request_id = COALESCE(p_request_id, request_id),
               reply_comment_id = v_cid, pending_comment_id = NULL, answered_at = p_now, reply_excerpt = left(v_plain, 200), detail = NULL
         WHERE id = p_dispatch_id;
        -- the people the reply names are told when they can see the board (a mention never grants); an agent named is a mention and starts nothing
        FOR v_n IN SELECT unnest(bd_rich_text_mentions(p_reply, 'member')) LOOP
            IF EXISTS (SELECT 1 FROM members WHERE id = v_n AND member_kind = 'human') THEN
                INSERT INTO comment_mentions VALUES (v_cid, 'member', v_n) ON CONFLICT DO NOTHING;
                IF v_n IS DISTINCT FROM d.acting_member_id THEN
                    PERFORM bd_notify(v_n, 'mention', v_agent || ' mentioned you', left(v_plain, 200), c.board_id, 'comment', v_cid, d.agent_member_id);
                END IF;
            END IF;
        END LOOP;
        FOR v_n IN SELECT unnest(bd_rich_text_mentions(p_reply, 'agent')) LOOP
            IF EXISTS (SELECT 1 FROM members WHERE id = v_n AND member_kind = 'agent') THEN
                INSERT INTO comment_mentions VALUES (v_cid, 'agent', v_n) ON CONFLICT DO NOTHING;
            END IF;
        END LOOP;
        FOR v_n IN SELECT unnest(bd_rich_text_mentions(p_reply, 'department')) LOOP
            IF EXISTS (SELECT 1 FROM departments WHERE id = v_n) THEN
                INSERT INTO comment_mentions VALUES (v_cid, 'department', v_n) ON CONFLICT DO NOTHING;
            END IF;
        END LOOP;
        IF d.acting_member_id IS NOT NULL THEN
            PERFORM bd_notify(d.acting_member_id, 'agent_replied', v_agent || ' replied on ' || v_board, left(v_plain, 200), c.board_id, 'comment', v_cid, d.agent_member_id);
        END IF;
        RETURN v_cid;

    ELSIF p_status = 'refused' THEN
        v_text := v_agent || ' could not answer: ' || COALESCE(NULLIF(btrim(p_detail), ''), 'the kernel refused this turn.');
        v_cid := d.pending_comment_id;
        IF v_cid IS NOT NULL AND EXISTS (SELECT 1 FROM comments WHERE id = v_cid AND deleted_at IS NULL) THEN
            UPDATE comments SET body = bd_rich_text(v_text) WHERE id = v_cid;
        ELSE
            INSERT INTO comments (board_id, parent_comment_id, author_member_id, body, agent_run_id)
            VALUES (c.board_id, v_root, d.agent_member_id, bd_rich_text(v_text), COALESCE(p_run_id, d.run_id)) RETURNING id INTO v_cid;
        END IF;
        UPDATE agent_dispatches SET status = 'refused', detail = left(p_detail, 500), answered_at = p_now, reply_comment_id = v_cid, pending_comment_id = NULL,
               run_id = COALESCE(p_run_id, run_id), request_id = COALESCE(p_request_id, request_id)
         WHERE id = p_dispatch_id;
        RETURN v_cid;

    ELSE   -- failed: five calls in all, the pauses 2, 4, 8 and 16 minutes; the fifth failure is for good and the asker is told once
        IF d.attempts < 5 THEN
            UPDATE agent_dispatches SET status = 'sent', attempts = d.attempts + 1, run_id = NULL, detail = left(p_detail, 500),
                   next_attempt_at = p_now + make_interval(mins => least(60, (2 ^ d.attempts)::integer)) WHERE id = p_dispatch_id;
        ELSE
            UPDATE agent_dispatches SET status = 'failed', attempts = d.attempts + 1, run_id = NULL, detail = left(p_detail, 500), pending_comment_id = NULL
             WHERE id = p_dispatch_id;
            IF d.pending_comment_id IS NOT NULL THEN
                UPDATE comments SET deleted_at = p_now WHERE id = d.pending_comment_id AND deleted_at IS NULL;
            END IF;
            IF d.acting_member_id IS NOT NULL THEN
                PERFORM bd_notify(d.acting_member_id, 'agent_replied', v_agent || ' could not answer', left(p_detail, 500), c.board_id, 'comment', c.id, d.agent_member_id);
            END IF;
        END IF;
        RETURN NULL;
    END IF;
END$$;

REVOKE ALL ON FUNCTION bd_thread_text(uuid, timestamptz, integer), bd_dispatch_rows(text, integer, timestamptz), bd_dispatches_due(integer, timestamptz),
    bd_dispatches_running(integer, timestamptz), bd_dispatches_waiting(integer, timestamptz),
    bd_dispatch_record(bigint, text, bigint, text, jsonb, text, timestamptz) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION bd_thread_text(uuid, timestamptz, integer), bd_dispatch_rows(text, integer, timestamptz), bd_dispatches_due(integer, timestamptz),
    bd_dispatches_running(integer, timestamptz), bd_dispatches_waiting(integer, timestamptz),
    bd_dispatch_record(bigint, text, bigint, text, jsonb, text, timestamptz) TO boards_rw;

COMMIT;
