<?php
$qs = fn(array $overrides = []) => http_build_query(array_merge($filters, $overrides, ['page' => $overrides['page'] ?? 1]));
$spamView = $filters['status'] === 'spam';
?>
<h1 class="page-title">Leads</h1>

<div class="card mb-3">
  <div class="card-body">
    <form class="row g-3 align-items-end" method="get" action="/leads">
      <div class="col-6 col-md-2">
        <label class="form-label">Site</label>
        <select class="form-select" name="site_id">
          <option value="0">All sites</option>
          <?php foreach ($sites as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $filters['site_id'] == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
          <option value="">Inbox (excl. spam)</option>
          <option value="all" <?= $filters['status'] === 'all' ? 'selected' : '' ?>>All</option>
          <?php foreach (['new', 'contacted', 'qualified', 'converted', 'archived', 'spam'] as $st): ?>
            <option value="<?= $st ?>" <?= $filters['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Search</label>
        <input type="text" class="form-control" name="q" value="<?= h($filters['q']) ?>" placeholder="name, email, message">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">From</label>
        <input type="date" class="form-control" name="from" value="<?= h($filters['from']) ?>">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">To</label>
        <input type="date" class="form-control" name="to" value="<?= h($filters['to']) ?>">
      </div>
      <div class="col-12 col-md-1 d-flex gap-2">
        <button type="submit" class="btn btn-dark w-100">Filter</button>
      </div>
    </form>
    <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
      <a class="btn btn-outline-dark btn-sm" href="/leads/export?<?= h($qs()) ?>">Export CSV</a>

      <form id="bulk-form" method="post" action="/leads/bulk" class="bulk-bar" hidden
            onsubmit="return bulkConfirm(this);">
        <?= csrf_field() ?>
        <input type="hidden" name="_back" value="/leads?<?= h($qs(['page' => $pg['page']])) ?>">
        <span class="small text-muted"><span id="bulk-count">0</span> selected</span>
        <select name="bulk_action" class="form-select form-select-sm" style="width:auto" required>
          <option value="" selected disabled>Bulk action…</option>
          <optgroup label="Change status to">
            <?php foreach (['new', 'contacted', 'qualified', 'converted', 'archived', 'spam'] as $st): ?>
              <option value="status:<?= $st ?>"><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <option value="delete">Delete selected…</option>
        </select>
        <button type="submit" class="btn btn-dark btn-sm">Apply</button>
      </form>

      <?php if ($spamView && $leads): ?>
        <form method="post" action="/leads/spam/empty" class="inline ms-auto"
              onsubmit="return confirm('Permanently delete ALL spam leads? This cannot be undone.');">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-danger btn-sm">Empty spam folder</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($spamView): ?>
  <p class="text-muted small">Spam quarantine - review below, restore false positives with “Not spam”, or empty the folder.</p>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr>
          <th style="width:1%"><input type="checkbox" class="form-check-input" id="bulk-all" aria-label="Select all"></th>
          <th>Date</th><th>Site</th><th>Name</th><th>Email</th><th>Phone</th>
          <th>Source</th><th>Country</th><th>Status</th>
          <?php if ($spamView): ?><th>Reason</th><?php endif; ?>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $lead): ?>
          <?php
            $extra = !empty($lead['extra_json']) ? json_decode((string) $lead['extra_json'], true) : [];
            $source = \App\UserAgent::source($extra ?: [], $lead['referrer'] ?? '');
          ?>
          <tr>
            <td><input type="checkbox" class="form-check-input lead-check" name="ids[]" form="bulk-form" value="<?= (int) $lead['id'] ?>" aria-label="Select lead <?= (int) $lead['id'] ?>"></td>
            <td class="text-muted small text-nowrap"><?= h($lead['created_at']) ?></td>
            <td><?= h($lead['site_name']) ?></td>
            <td><?= h($lead['name'] ?: '(no name)') ?></td>
            <td><?= h($lead['email']) ?></td>
            <td><?= h($lead['phone']) ?></td>
            <td class="small"><?= h($source) ?></td>
            <td class="small"><?= h($lead['country'] ?: '-') ?></td>
            <td><span class="badge badge-<?= h($lead['status']) ?>"><?= h($lead['status']) ?></span></td>
            <?php if ($spamView): ?>
              <td><code class="small"><?= h($lead['spam_reason'] ?: '-') ?></code></td>
            <?php endif; ?>
            <td class="text-end text-nowrap">
              <?php if ($lead['is_spam']): ?>
                <form method="post" action="/leads/<?= (int) $lead['id'] ?>/not-spam" class="inline me-1">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_back" value="/leads?<?= h($qs(['page' => $pg['page']])) ?>">
                  <button type="submit" class="btn btn-outline-dark btn-sm">Not spam</button>
                </form>
              <?php endif; ?>
              <div class="dropdown d-inline-block">
                <button class="btn btn-outline-dark btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                  Actions
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                  <li><a class="dropdown-item" href="/leads/<?= (int) $lead['id'] ?>">View Details</a></li>
                  <li><hr class="dropdown-divider"></li>
                  <li><h6 class="dropdown-header">Change Status</h6></li>
                  <?php foreach (['new', 'contacted', 'qualified', 'converted', 'archived', 'spam'] as $st): if ($st === $lead['status']) continue; ?>
                    <li>
                      <form method="post" action="/leads/<?= (int) $lead['id'] ?>/status" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="status" value="<?= h($st) ?>">
                        <button type="submit" class="dropdown-item py-1"><?= ucfirst($st) ?></button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <form method="post" action="/leads/<?= (int) $lead['id'] ?>/delete" class="m-0" onsubmit="return confirm('Permanently delete this lead?');">
                      <?= csrf_field() ?>
                      <button type="submit" class="dropdown-item text-danger py-1">Delete Lead</button>
                    </form>
                  </li>
                </ul>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$leads): ?>
          <tr><td colspan="<?= $spamView ? 11 : 10 ?>" class="text-muted text-center py-4">No leads match these filters.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="d-flex align-items-center gap-3 mt-3">
  <?php if ($pg['page'] > 1): ?>
    <a class="btn btn-outline-dark btn-sm" href="/leads?<?= h($qs(['page' => $pg['page'] - 1])) ?>">&laquo; Prev</a>
  <?php endif; ?>
  <span class="small">Page <strong><?= (int) $pg['page'] ?></strong> of <?= (int) $pg['total_pages'] ?></span>
  <?php if ($pg['page'] < $pg['total_pages']): ?>
    <a class="btn btn-outline-dark btn-sm" href="/leads?<?= h($qs(['page' => $pg['page'] + 1])) ?>">Next &raquo;</a>
  <?php endif; ?>
  <span class="text-muted small"><?= (int) $pg['total_rows'] ?> total</span>
</div>

<script>
(function () {
  var checks = Array.prototype.slice.call(document.querySelectorAll('.lead-check'));
  var all = document.getElementById('bulk-all');
  var bar = document.getElementById('bulk-form');
  var count = document.getElementById('bulk-count');
  if (!checks.length || !bar) return;

  function refresh() {
    var n = checks.filter(function (c) { return c.checked; }).length;
    count.textContent = n;
    bar.hidden = n === 0;
    if (all) {
      all.checked = n === checks.length;
      all.indeterminate = n > 0 && n < checks.length;
    }
  }

  if (all) {
    all.addEventListener('change', function () {
      checks.forEach(function (c) { c.checked = all.checked; });
      refresh();
    });
  }
  checks.forEach(function (c) { c.addEventListener('change', refresh); });
  refresh();
})();

function bulkConfirm(form) {
  var action = form.bulk_action.value;
  var n = document.querySelectorAll('.lead-check:checked').length;
  if (!n) { alert('Select at least one lead.'); return false; }
  if (action === 'delete') {
    return confirm('Permanently delete ' + n + ' selected lead(s)? This cannot be undone.');
  }
  return true;
}
</script>
