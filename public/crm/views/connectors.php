<h1 class="page-title">Connectors</h1>
<p class="text-muted small">
  Forward captured leads from each site to external destinations in real time, and schedule weekly
  summary emails. Every delivery attempt is logged. Spam leads are only forwarded to a connector if
  its “also send spam” option is on.
</p>

<?php if (!$sites): ?>
  <p class="text-muted">No sites yet - add one under <a href="/sites">Sites</a> first.</p>
<?php else: ?>

<div class="row">
  <div class="col-md-3 mb-4">
    <div class="nav flex-column nav-pills" id="connectors-tabs" role="tablist" aria-orientation="vertical">
      <?php foreach ($sites as $i => $site): ?>
        <button class="nav-link d-flex justify-content-between align-items-center <?= $i === 0 ? 'active' : '' ?>" id="tab-connector-<?= $site['id'] ?>" data-bs-toggle="pill" data-bs-target="#pane-connector-<?= $site['id'] ?>" type="button" role="tab" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>">
          <span class="text-truncate" style="max-width:80%"><?= h($site['name']) ?></span>
          <?php if (!$site['active']): ?><span class="badge bg-secondary" style="font-size:0.55rem;padding:0.25rem 0.4rem;color:#fff">OFF</span><?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-md-9">
    <div class="tab-content" id="connectors-tabContent">
      
      <?php foreach ($sites as $i => $site): ?>
        <div class="tab-pane fade <?= $i === 0 ? 'show active' : '' ?>" id="pane-connector-<?= $site['id'] ?>" role="tabpanel" aria-labelledby="tab-connector-<?= $site['id'] ?>">
          <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
              <span><?= h($site['name']) ?> Connectors</span>
              <span class="badge <?= $site['active'] ? 'badge-converted' : 'badge-archived' ?>"><?= $site['active'] ? 'active' : 'disabled' ?></span>
            </div>
            <div class="card-body">

              <!-- Existing connectors -->
              <?php if ($site['connectors']): ?>
                <div class="table-responsive mb-4">
                  <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Name</th><th>Type</th><th>Destination</th><th>Spam?</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                      <?php foreach ($site['connectors'] as $c): $cfg = json_decode($c['config_json'], true) ?: []; ?>
                        <tr>
                          <td><?= h($c['name']) ?></td>
                          <td><span class="badge badge-qualified"><?= h($c['type']) ?></span></td>
                          <td class="small">
                            <?php if ($c['type'] === 'smtp'): ?>
                              <?= h($cfg['to'] ?? '') ?> <span class="text-muted">via <?= h($cfg['host'] ?? '') ?></span>
                            <?php else: ?>
                              <code><?= h($cfg['url'] ?? '') ?></code>
                            <?php endif; ?>
                          </td>
                          <td class="small"><?= $c['send_spam'] ? 'yes' : 'no' ?></td>
                          <td class="small"><?= $c['active'] ? 'active' : 'disabled' ?></td>
                          <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="collapse" data-bs-target="#edit-conn-<?= (int) $c['id'] ?>">Edit</button>
                            <form method="post" action="/connectors/<?= (int) $c['id'] ?>/test" class="inline">
                              <?= csrf_field() ?>
                              <button type="submit" class="btn btn-outline-dark btn-sm">Test</button>
                            </form>
                            <form method="post" action="/connectors/<?= (int) $c['id'] ?>/toggle" class="inline">
                              <?= csrf_field() ?>
                              <button type="submit" class="btn btn-outline-dark btn-sm"><?= $c['active'] ? 'Disable' : 'Enable' ?></button>
                            </form>
                            <form method="post" action="/connectors/<?= (int) $c['id'] ?>/delete" class="inline"
                                  onsubmit="return confirm('Delete this connector?');">
                              <?= csrf_field() ?>
                              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                          </td>
                        </tr>
                        <tr class="collapse" id="edit-conn-<?= (int) $c['id'] ?>">
                          <td colspan="6" class="bg-light">
                            <form method="post" action="/connectors/<?= (int) $c['id'] ?>/update">
                              <?= csrf_field() ?>
                              <div class="row g-3 align-items-end mb-3">
                                <div class="col-md-3">
                                  <label class="form-label">Type</label>
                                  <input type="text" class="form-control form-control-sm" value="<?= h($c['type']) ?>" disabled>
                                  <div class="form-text small">Type can't be changed.</div>
                                </div>
                                <div class="col-md-5">
                                  <label class="form-label">Connector name</label>
                                  <input type="text" class="form-control form-control-sm" name="name" value="<?= h($c['name']) ?>">
                                </div>
                                <div class="col-md-4">
                                  <div class="form-check mt-4 mb-2">
                                    <input class="form-check-input" type="checkbox" name="send_spam" value="1" id="ess<?= (int) $c['id'] ?>" <?= $c['send_spam'] ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="ess<?= (int) $c['id'] ?>">Forward flagged spam</label>
                                  </div>
                                </div>
                              </div>

                              <?php if ($c['type'] === 'smtp'): ?>
                                <div class="row g-3 bg-white p-3 rounded border">
                                  <div class="col-md-4"><label class="form-label">SMTP host</label><input class="form-control form-control-sm" name="host" value="<?= h($cfg['host'] ?? '') ?>"></div>
                                  <div class="col-md-2"><label class="form-label">Port</label><input class="form-control form-control-sm" name="port" value="<?= h((string) ($cfg['port'] ?? 587)) ?>"></div>
                                  <div class="col-md-3">
                                    <label class="form-label">Encryption</label>
                                    <select class="form-select form-select-sm" name="secure">
                                      <option value="tls" <?= ($cfg['secure'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                                      <option value="ssl" <?= ($cfg['secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
                                      <option value="" <?= ($cfg['secure'] ?? '') === '' ? 'selected' : '' ?>>None (25)</option>
                                    </select>
                                  </div>
                                  <div class="col-md-3"><label class="form-label">Recipient(s)</label><input class="form-control form-control-sm" name="to" value="<?= h($cfg['to'] ?? '') ?>"><div class="form-text small">Comma-separate for multiple.</div></div>
                                  <div class="col-md-6"><label class="form-label">Cc (optional)</label><input class="form-control form-control-sm" name="cc" value="<?= h($cfg['cc'] ?? '') ?>"></div>
                                  <div class="col-md-6"><label class="form-label">Bcc (optional)</label><input class="form-control form-control-sm" name="bcc" value="<?= h($cfg['bcc'] ?? '') ?>"></div>
                                  <div class="col-md-4"><label class="form-label">Username</label><input class="form-control form-control-sm" name="username" autocomplete="off" value="<?= h($cfg['username'] ?? '') ?>"></div>
                                  <div class="col-md-4"><label class="form-label">Password</label><input class="form-control form-control-sm" type="password" name="password" autocomplete="new-password" placeholder="leave blank to keep current"></div>
                                  <div class="col-md-4"><label class="form-label">From address</label><input class="form-control form-control-sm" name="from_email" value="<?= h($cfg['from_email'] ?? '') ?>"></div>
                                  <div class="col-md-12"><label class="form-label">From name</label><input class="form-control form-control-sm" name="from_name" value="<?= h($cfg['from_name'] ?? 'MicroCRM') ?>"></div>
                                </div>
                              <?php else: ?>
                                <div class="row g-3 bg-white p-3 rounded border">
                                  <div class="col-md-8"><label class="form-label">POST URL</label><input class="form-control form-control-sm" name="url" value="<?= h($cfg['url'] ?? '') ?>"></div>
                                  <div class="col-md-4"><label class="form-label">Signing secret (optional)</label><input class="form-control form-control-sm" name="secret" value="<?= h($cfg['secret'] ?? '') ?>"></div>
                                </div>
                              <?php endif; ?>

                              <div class="mt-3 d-flex gap-2">
                                <button type="submit" class="btn btn-dark btn-sm">Save changes</button>
                                <button type="button" class="btn btn-outline-dark btn-sm" data-bs-toggle="collapse" data-bs-target="#edit-conn-<?= (int) $c['id'] ?>">Cancel</button>
                              </div>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              <?php else: ?>
                <div class="alert alert-light text-muted small text-center mb-4 border border-dashed">
                  No connectors configured for this site yet.
                </div>
              <?php endif; ?>

              <!-- Recent delivery log -->
              <?php if ($site['recent_log']): ?>
                <details class="mb-4">
                  <summary class="small text-muted" style="cursor:pointer">Recent delivery log (<?= count($site['recent_log']) ?>)</summary>
                  <div class="table-responsive mt-2">
                    <table class="table table-sm align-middle mb-0">
                      <thead><tr><th>When</th><th>Connector</th><th>Result</th><th>Detail</th></tr></thead>
                      <tbody>
                        <?php foreach ($site['recent_log'] as $l): ?>
                          <tr>
                            <td class="small text-nowrap text-muted"><?= h($l['created_at']) ?></td>
                            <td class="small"><?= h($l['connector_name']) ?></td>
                            <td><span class="badge <?= $l['ok'] ? 'badge-converted' : 'badge-spam' ?>"><?= $l['ok'] ? 'ok' : 'failed' ?></span></td>
                            <td class="small"><?= h($l['detail']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </details>
              <?php endif; ?>

              <hr class="my-4">

              <!-- Add connector -->
              <h3>Add connector</h3>
              <form method="post" action="/connectors" data-connector-form>
                <?= csrf_field() ?>
                <input type="hidden" name="site_id" value="<?= (int) $site['id'] ?>">
                <div class="row g-3 align-items-end mb-3">
                  <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select name="type" class="form-select" data-connector-type>
                      <option value="smtp">SMTP email</option>
                      <option value="webhook">Webhook (POST URL)</option>
                    </select>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label">Connector name</label>
                    <input type="text" class="form-control" name="name" placeholder="e.g. Sales inbox">
                  </div>
                  <div class="col-md-4">
                    <div class="form-check mt-4 mb-2">
                      <input class="form-check-input" type="checkbox" name="send_spam" value="1" id="ss<?= (int) $site['id'] ?>">
                      <label class="form-check-label small" for="ss<?= (int) $site['id'] ?>">Forward flagged spam</label>
                    </div>
                  </div>
                </div>

                <!-- SMTP fields -->
                <div data-fields="smtp">
                  <div class="row g-3 bg-light p-3 rounded border">
                    <div class="col-md-4"><label class="form-label">SMTP host</label><input class="form-control form-control-sm" name="host" placeholder="smtp.gmail.com"></div>
                    <div class="col-md-2"><label class="form-label">Port</label><input class="form-control form-control-sm" name="port" value="587"></div>
                    <div class="col-md-3">
                      <label class="form-label">Encryption</label>
                      <select class="form-select form-select-sm" name="secure">
                        <option value="tls">STARTTLS (587)</option>
                        <option value="ssl">SSL/TLS (465)</option>
                        <option value="">None (25)</option>
                      </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Recipient(s)</label><input class="form-control form-control-sm" name="to" placeholder="sales@yourco.com, ops@yourco.com"><div class="form-text small">Comma-separate for multiple.</div></div>
                    <div class="col-md-6"><label class="form-label">Cc (optional)</label><input class="form-control form-control-sm" name="cc" placeholder="manager@yourco.com"></div>
                    <div class="col-md-6"><label class="form-label">Bcc (optional)</label><input class="form-control form-control-sm" name="bcc" placeholder="archive@yourco.com"></div>
                    <div class="col-md-4"><label class="form-label">Username</label><input class="form-control form-control-sm" name="username" autocomplete="off" placeholder="smtp login"></div>
                    <div class="col-md-4"><label class="form-label">Password</label><input class="form-control form-control-sm" type="password" name="password" autocomplete="new-password"></div>
                    <div class="col-md-4"><label class="form-label">From address</label><input class="form-control form-control-sm" name="from_email" placeholder="crm@yourco.com"></div>
                    <div class="col-md-12"><label class="form-label">From name</label><input class="form-control form-control-sm" name="from_name" value="MicroCRM"></div>
                  </div>
                </div>

                <!-- Webhook fields -->
                <div data-fields="webhook" style="display:none">
                  <div class="row g-3 bg-light p-3 rounded border">
                    <div class="col-md-8"><label class="form-label">POST URL</label><input class="form-control form-control-sm" name="url" placeholder="https://hooks.yourapp.com/leads"></div>
                    <div class="col-md-4"><label class="form-label">Signing secret (optional)</label><input class="form-control form-control-sm" name="secret" placeholder="used for HMAC header"></div>
                    <div class="col-12">
                      <p class="text-muted small mt-1 mb-0">Leads are POSTed as JSON. If a secret is set, an <code>X-MicroCRM-Signature: sha256=…</code> HMAC header is included so your endpoint can verify authenticity.</p>
                    </div>
                  </div>
                </div>

                <div class="mt-3"><button type="submit" class="btn btn-dark btn-sm">Add connector</button></div>
              </form>

              <hr class="my-4">

              <!-- Weekly report -->
              <h3>Weekly report</h3>
              <form method="post" action="/sites/<?= (int) $site['id'] ?>/report-email" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <div class="col-md-7">
                  <label class="form-label">Send a weekly lead summary to</label>
                  <input type="email" class="form-control" name="report_email" value="<?= h($site['report_email'] ?? '') ?>" placeholder="you@yourco.com (blank = disabled)">
                </div>
                <div class="col-md-5 d-flex gap-2">
                  <button type="submit" class="btn btn-outline-dark btn-sm">Save</button>
                  <a href="/reports/preview/<?= (int) $site['id'] ?>" target="_blank" class="btn btn-outline-primary btn-sm">Preview</a>
                </div>
              </form>
              <p class="text-muted small mt-2 mb-0">
                Delivered via this site's first active SMTP connector. Schedule it with cron:
                <code>php bin/send-reports.php</code> (e.g. every Monday 8am).
              </p>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

    </div>
  </div>
</div>

<script>
document.querySelectorAll('[data-connector-form]').forEach(function (form) {
  var typeSelect = form.querySelector('[data-connector-type]');
  function sync() {
    form.querySelectorAll('[data-fields]').forEach(function (box) {
      box.style.display = box.getAttribute('data-fields') === typeSelect.value ? '' : 'none';
    });
  }
  typeSelect.addEventListener('change', sync);
  sync();
});

// Restore last selected tab if any
document.addEventListener("DOMContentLoaded", function() {
  const lastTab = localStorage.getItem('activeConnectorTab');
  if (lastTab) {
    const tabEl = document.querySelector(`[data-bs-target="${lastTab}"]`);
    if (tabEl) {
      new bootstrap.Tab(tabEl).show();
    }
  }
  
  // Listen for tab changes
  const triggerTabList = document.querySelectorAll('#connectors-tabs button[data-bs-toggle="pill"]');
  triggerTabList.forEach(triggerEl => {
    triggerEl.addEventListener('shown.bs.tab', event => {
      localStorage.setItem('activeConnectorTab', event.target.getAttribute('data-bs-target'));
    });
  });
});
</script>

<?php endif; ?>
