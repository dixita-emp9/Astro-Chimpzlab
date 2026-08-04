-- MicroCRM schema (SQLite)

CREATE TABLE IF NOT EXISTS admin_users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS sites (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    api_key         TEXT UNIQUE NOT NULL,
    allowed_domain  TEXT,                 -- optional, e.g. https://example.com - used for CORS + Origin check
    redirect_url    TEXT,                 -- optional, used by the no-JS form fallback after a successful submit
    success_message TEXT,                 -- optional message shown after successful form submission
    fields_json     TEXT,                 -- optional custom field definitions (JSON array) for the form builder
    report_email    TEXT,                 -- optional weekly-report recipient for this site
    active          INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS leads (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    name        TEXT,
    email       TEXT,
    phone       TEXT,
    message     TEXT,
    extra_json  TEXT,                    -- any extra form fields not in the core set, as JSON
    ip_address  TEXT,
    country     TEXT,                    -- ISO code resolved from ip_address via the local GeoIP table
    language    TEXT,                    -- browser Accept-Language header
    user_agent  TEXT,
    referrer    TEXT,
    status      TEXT NOT NULL DEFAULT 'new',   -- new, contacted, qualified, converted, archived, spam
    is_spam     INTEGER NOT NULL DEFAULT 0,
    spam_reason TEXT,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Per-lead activity feed: manually-entered notes plus auto-logged events
-- (creation, status changes). Powers the timeline on the lead detail page.
CREATE TABLE IF NOT EXISTS lead_activity (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    lead_id    INTEGER NOT NULL REFERENCES leads(id) ON DELETE CASCADE,
    type       TEXT NOT NULL DEFAULT 'note',  -- note, status, created
    body       TEXT NOT NULL,
    author     TEXT,                          -- admin username, null for system events
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_lead_activity_lead ON lead_activity(lead_id, created_at);

CREATE TABLE IF NOT EXISTS spam_rules (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    type       TEXT NOT NULL,             -- keyword, email_domain, regex
    pattern    TEXT NOT NULL,
    field      TEXT NOT NULL DEFAULT 'any', -- name, email, message, any
    action     TEXT NOT NULL DEFAULT 'flag', -- flag or block
    active     INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Local IPv4 → country lookup table, populated by bin/update-geoip.php from
-- a public-domain dataset. Empty table = GeoIP checks silently skipped.
CREATE TABLE IF NOT EXISTS ip_country (
    ip_from INTEGER NOT NULL,
    ip_to   INTEGER NOT NULL,
    country TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_ip_country_from ON ip_country(ip_from);

-- Per-site outbound connectors (SMTP email, webhook POST) + delivery log.
CREATE TABLE IF NOT EXISTS connectors (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id     INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    type        TEXT NOT NULL,               -- 'smtp' | 'webhook'
    name        TEXT NOT NULL,
    config_json TEXT NOT NULL,               -- type-specific settings
    send_spam   INTEGER NOT NULL DEFAULT 0,  -- also forward spam leads?
    active      INTEGER NOT NULL DEFAULT 1,
    created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_connectors_site ON connectors(site_id);

CREATE TABLE IF NOT EXISTS connector_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    connector_id INTEGER NOT NULL REFERENCES connectors(id) ON DELETE CASCADE,
    lead_id      INTEGER,
    ok           INTEGER NOT NULL,
    detail       TEXT,
    created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_connlog_connector ON connector_log(connector_id, created_at);

-- Sliding-window log used purely for rate limiting; pruned automatically.
CREATE TABLE IF NOT EXISTS rate_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address TEXT NOT NULL,
    site_id    INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_leads_site       ON leads(site_id);
CREATE INDEX IF NOT EXISTS idx_leads_status     ON leads(status);
CREATE INDEX IF NOT EXISTS idx_leads_created    ON leads(created_at);
CREATE INDEX IF NOT EXISTS idx_ratelog_ip_time  ON rate_log(ip_address, created_at);
CREATE INDEX IF NOT EXISTS idx_sites_api_key    ON sites(api_key);
