-- 005: the application's SETTINGS (one row) and the KEY rules that read them (design §6.5; D3, D6, D7, D9, §15).
--
-- THE ESTATE'S DATA MODEL (design §6.7): bd_settings is the siblings' one-row `app_settings` skeleton (id smallint = 1, updated_at)
-- with the kit's generic columns (business name, time zone, the key rates) and THIS application's columns: a new board's `everyone`
-- level (D3), the item budget and cap and a batch's size (D7), the retention windows (D6), the upload ceiling (D9), a drawing's points
-- (D7), the live relay's room size, its check and the polling fallback's interval (§8). The key functions are Knowledge db/011's =
-- Sales CRM db/005's = the Data Lake db/005's (bd_resolve_key, bd_rate_ok, bd_key_calls_today, bd_key_rotate, bd_prune_key_usage)
-- — here because they read the settings.
--
-- THE RELAY READS THESE TOO: the live relay takes room_max_sockets, live_check_seconds and live_enabled from this row at start and on
-- every check — a setting changed on the admin screen holds from the next check.
BEGIN;

CREATE TABLE bd_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,
    timezone                    text NOT NULL DEFAULT 'UTC',
    -- sharing (D3): the `everyone` row a new board gets — none = private (the recommendation the owner took)
    default_everyone_level      text NOT NULL DEFAULT 'none' CHECK (default_everyone_level IN ('none', 'view', 'comment', 'edit')),
    -- limits (D7)
    item_soft_budget            integer NOT NULL DEFAULT 4000  CHECK (item_soft_budget BETWEEN 100 AND 100000),     -- a warning on the board
    item_hard_cap               integer NOT NULL DEFAULT 10000 CHECK (item_hard_cap BETWEEN 100 AND 100000),        -- a create above it is refused
    batch_max_ops               integer NOT NULL DEFAULT 500   CHECK (batch_max_ops BETWEEN 1 AND 5000),            -- ops in one committed batch
    drawing_max_points          integer NOT NULL DEFAULT 2000  CHECK (drawing_max_points BETWEEN 10 AND 100000),    -- after simplification
    max_upload_mb               integer NOT NULL DEFAULT 25    CHECK (max_upload_mb BETWEEN 1 AND 1024),            -- an image or a file (D9)
    -- retention (D6)
    op_retention_days           integer NOT NULL DEFAULT 90  CHECK (op_retention_days BETWEEN 7 AND 3650),         -- then compacted into a version
    interval_version_days       integer NOT NULL DEFAULT 30  CHECK (interval_version_days BETWEEN 1 AND 3650),     -- the hourly-while-active versions
    daily_version_days          integer NOT NULL DEFAULT 365 CHECK (daily_version_days BETWEEN 1 AND 36500),       -- then one a day; named versions forever
    trash_days                  integer NOT NULL DEFAULT 30  CHECK (trash_days BETWEEN 1 AND 3650),                -- a deleted board, then purged
    -- live (§8)
    room_max_sockets            integer NOT NULL DEFAULT 100 CHECK (room_max_sockets BETWEEN 2 AND 10000),
    live_enabled                boolean NOT NULL DEFAULT true,                                                       -- false = every client polls
    live_check_seconds          integer NOT NULL DEFAULT 60  CHECK (live_check_seconds BETWEEN 5 AND 3600),        -- the relay's session and level check
    poll_seconds                integer NOT NULL DEFAULT 3   CHECK (poll_seconds BETWEEN 1 AND 60),               -- the fallback's ops?after= interval
    -- keys (the kit's; db/003)
    key_rate_per_minute         integer NOT NULL DEFAULT 60 CHECK (key_rate_per_minute BETWEEN 1 AND 100000),
    key_rate_per_day            integer NOT NULL DEFAULT 5000 CHECK (key_rate_per_day BETWEEN 1 AND 10000000),
    key_rotation_overlap_hours  smallint NOT NULL DEFAULT 24 CHECK (key_rotation_overlap_hours BETWEEN 0 AND 168),
    storage_dir                 text,                                                                                  -- NULL = BD_STORAGE_DIR from the environment
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    CHECK (item_soft_budget < item_hard_cap),
    CHECK (interval_version_days <= daily_version_days)
);
INSERT INTO bd_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER bd_settings_touch BEFORE UPDATE ON bd_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One setting by name, for the rules that read them (never for a column that is not a number / not text).
CREATE OR REPLACE FUNCTION bd_setting_int(p_name text) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v bigint;
BEGIN
    EXECUTE format('SELECT %I::bigint FROM bd_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION bd_setting_text(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v text;
BEGIN
    EXECUTE format('SELECT %I::text FROM bd_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION bd_setting_bool(p_name text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v boolean;
BEGIN
    EXECUTE format('SELECT %I::boolean FROM bd_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- KEYS (db/003): a key's defaults, its resolution, the rate rule, rotation and pruning — Knowledge db/011 minus the base
-- (Sales CRM's and the Data Lake's copies, without the Data Lake's per-key SQL switch).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION bd_access_tokens_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.scope = 'api' THEN
        NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, bd_setting_int('key_rate_per_minute')::integer);
        NEW.rate_per_day := COALESCE(NEW.rate_per_day, bd_setting_int('key_rate_per_day')::integer);
        IF NEW.rotated_from IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_access_tokens o WHERE o.id = NEW.rotated_from AND o.member_id = NEW.member_id AND o.scope = 'api') THEN
            RAISE EXCEPTION 'A key is rotated from a key of the same person' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mcp_access_tokens_before BEFORE INSERT OR UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION bd_access_tokens_before();

-- Resolve a key by its hash: the token, its person, its label and its limits, with last_used_at touched. Nothing for a
-- revoked, expired or mcp-scope token, or one whose person is no longer admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION bd_resolve_key(p_hash text)
    RETURNS TABLE (token_id bigint, member_id bigint, label text, rate_per_minute integer, rate_per_day integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;

-- Count one call. ok = the call may proceed; when not, limit_hit says which bucket and retry_after how many seconds until it
-- opens. The minute bucket is charged first; a call the minute refuses is not charged to the day (so a burst never eats the
-- day); a call the day refuses stays counted in the minute (it was made). `refused` is kept per bucket for the keys screen.
CREATE OR REPLACE FUNCTION bd_rate_ok(p_token_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token_id AND scope = 'api' FOR UPDATE;   -- serialises the two upserts per key
    IF NOT FOUND THEN
        RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN;
    END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN
        RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN;
    END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

-- Today's count for a key (the keys screen, find_keys).
CREATE OR REPLACE FUNCTION bd_key_calls_today(p_token_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key for the same person, label and limits; the old one expires after the overlap (24 h) and points nowhere —
-- the new one says `rotated_from`. Returns the new row's id; the raw key is the caller's (PHP mints the 48 hex, hashes them, and
-- passes the hash in).
CREATE OR REPLACE FUNCTION bd_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := bd_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, rotated_from)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.rate_per_minute, o.rate_per_day, o.id)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- The worker prunes buckets once a minute: minute buckets older than a day, day buckets older than 35 days.
CREATE OR REPLACE FUNCTION bd_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, UPDATE ON bd_settings TO boards_rw;
GRANT EXECUTE ON FUNCTION bd_setting_int(text), bd_setting_text(text), bd_setting_bool(text), bd_key_calls_today(bigint) TO boards_rw, boards_records_ro, boards_activity_ro;
REVOKE ALL ON FUNCTION bd_resolve_key(text), bd_rate_ok(bigint, timestamptz), bd_key_rotate(bigint, text), bd_prune_key_usage() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION bd_resolve_key(text), bd_rate_ok(bigint, timestamptz), bd_key_rotate(bigint, text), bd_prune_key_usage() TO boards_rw;

COMMIT;
