<h1 class="page-title">Spam Rules</h1>
<p class="text-muted small">
  Rules run against every submission in addition to the built-in honeypot, rate limiting, and time-trap
  checks. <strong>Flag</strong> keeps the lead but marks it spam for review. <strong>Block</strong> does
  the same, and also excludes it from the default Leads view. The <strong>Hits</strong> column shows how
  many leads each rule has caught.
</p>

<div class="card mb-4">
  <div class="card-header">Add a rule</div>
  <div class="card-body">
    <form method="post" action="/spam-rules" class="row g-3 align-items-end">
      <?= csrf_field() ?>
      <div class="col-md-2">
        <label class="form-label">Type</label>
        <select class="form-select" name="type">
          <option value="keyword">Keyword (contains)</option>
          <option value="email_domain">Email domain (exact)</option>
          <option value="regex">Regex</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Field</label>
        <select class="form-select" name="field">
          <option value="any">Any field</option>
          <option value="name">Name</option>
          <option value="email">Email</option>
          <option value="message">Message</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Pattern</label>
        <input type="text" class="form-control" name="pattern" required placeholder="e.g. viagra, mailinator.com, or /free\s+money/i">
      </div>
      <div class="col-md-2">
        <label class="form-label">Action</label>
        <select class="form-select" name="action">
          <option value="flag">Flag</option>
          <option value="block">Block</option>
        </select>
      </div>
      <div class="col-md-2"><button type="submit" class="btn btn-dark w-100">Add rule</button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead><tr><th>Type</th><th>Field</th><th>Pattern</th><th>Action</th><th>Hits</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
        <?php foreach ($rules as $rule): ?>
          <tr>
            <td><?= h($rule['type']) ?></td>
            <td><?= h($rule['field']) ?></td>
            <td><code><?= h($rule['pattern']) ?></code></td>
            <td><span class="badge <?= $rule['action'] === 'block' ? 'badge-spam' : 'badge-contacted' ?>"><?= h($rule['action']) ?></span></td>
            <td><?= (int) $rule['hits'] ?></td>
            <td><span class="text-muted small"><?= $rule['active'] ? 'active' : 'disabled' ?></span></td>
            <td class="text-end text-nowrap">
              <form method="post" action="/spam-rules/<?= (int) $rule['id'] ?>/toggle" class="inline">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-outline-dark btn-sm"><?= $rule['active'] ? 'Disable' : 'Enable' ?></button>
              </form>
              <form method="post" action="/spam-rules/<?= (int) $rule['id'] ?>/delete" class="inline"
                    onsubmit="return confirm('Delete this rule?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rules): ?>
          <tr><td colspan="7" class="text-muted text-center py-4">No spam rules yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
