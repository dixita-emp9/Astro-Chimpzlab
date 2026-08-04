<h1 class="page-title">Settings</h1>
<p class="text-muted small">
  Changes take effect immediately for all sites. Secrets and infrastructure config
  (<code>APP_KEY</code>, <code>DB_PATH</code>, <code>TRUST_PROXY</code>) live in <code>.env</code> on the server.
</p>

<form method="post" action="/settings">
  <?= csrf_field() ?>

  <div class="card mb-4">
    <div class="card-header">Rate limiting</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Max submissions per IP per window</label>
          <input type="number" class="form-control" name="rate_limit_max" min="1" max="1000" value="<?= (int) $settings['rate_limit_max'] ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Window (seconds)</label>
          <input type="number" class="form-control" name="rate_limit_window" min="10" max="86400" value="<?= (int) $settings['rate_limit_window'] ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Max per IP per day (all sites)</label>
          <input type="number" class="form-control" name="daily_limit_max" min="1" max="10000" value="<?= (int) $settings['daily_limit_max'] ?>">
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">Spam checks</div>
    <div class="card-body">
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label">Min seconds before submit (JS widget)</label>
          <input type="number" class="form-control" name="min_submit_seconds" min="0" max="120" value="<?= (int) $settings['min_submit_seconds'] ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Token max age (seconds)</label>
          <input type="number" class="form-control" name="max_token_age_seconds" min="60" max="86400" value="<?= (int) $settings['max_token_age_seconds'] ?>">
        </div>
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="check_email_dns" value="1" id="c1" <?= $settings['check_email_dns'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="c1">Flag emails whose domain has no MX/A DNS record</label>
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="check_heuristics" value="1" id="c2" <?= $settings['check_heuristics'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="c2">Content heuristics (URLs in name, link-stuffed messages, missing user agent)</label>
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" name="check_duplicates" value="1" id="c3" <?= $settings['check_duplicates'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="c3">Flag duplicate email/phone submitted to the same site within 24 hours</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" name="flag_direct_api" value="1" id="c4" <?= $settings['flag_direct_api'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="c4">Flag submissions that bypass the form and POST the API directly</label>
      </div>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">Country blocking</div>
    <div class="card-body">
      <label class="form-label">Blocked countries (comma-separated ISO codes, e.g. <code>RU,BY,CN</code>)</label>
      <input type="text" class="form-control" name="blocked_countries" value="<?= h($settings['blocked_countries']) ?>" placeholder="RU,BY" style="max-width:320px">
      <p class="text-muted small mb-0 mt-2">
        Requires GeoIP data (below). Submissions from these countries are flagged as spam
        with the country recorded, not silently rejected.
      </p>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-header">Spam handling</div>
    <div class="card-body">
      <label class="form-label">Auto-delete spam after (days, 0 = keep forever)</label>
      <input type="number" class="form-control" name="spam_retention_days" min="0" max="3650" value="<?= (int) $settings['spam_retention_days'] ?>" style="max-width:200px">
      <p class="text-muted small mb-0 mt-2">
        Spam is quarantined, never silently dropped - review it under Leads &rarr; Status: Spam.
        You can also empty the spam folder manually from there.
      </p>
    </div>
  </div>

  <button type="submit" class="btn btn-dark">Save settings</button>
</form>

<div class="card mt-4">
  <div class="card-header">GeoIP data</div>
  <div class="card-body">
    <dl class="kv mb-3" style="grid-template-columns:150px 1fr">
      <dt>IP ranges loaded</dt>
      <dd><?= number_format($geoipRanges) ?><?= $geoipRanges === 0 ? ' - country capture and blocking are inactive' : '' ?></dd>
      <dt>Last synced</dt>
      <dd><?= h($settings['geoip_last_sync'] ?: 'never') ?></dd>
    </dl>
    <form method="post" action="/settings/geoip-sync"
          onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'Syncing…';">
      <?= csrf_field() ?>
      <button type="submit" class="btn btn-outline-dark btn-sm">Sync now (~10&nbsp;MB download from db-ip.com dataset)</button>
    </form>
    <p class="text-muted small mb-0 mt-2">Re-sync monthly to keep country data accurate. Also available via <code>php bin/update-geoip.php</code> (e.g. from cron).</p>
  </div>
</div>
