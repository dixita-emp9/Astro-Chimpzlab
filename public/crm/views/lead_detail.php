<p><a href="/leads" class="text-decoration-none">&laquo; Back to leads</a></p>

<div class="d-flex align-items-center gap-3 page-title">
  <h1 class="mb-0">Lead #<?= (int) $lead['id'] ?></h1>
  <span class="badge badge-<?= h($lead['status']) ?>"><?= h($lead['status']) ?></span>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Details</div>
      <div class="card-body">
        <dl class="kv">
          <dt>Site</dt><dd><?= h($lead['site_name']) ?></dd>
          <dt>Name</dt><dd><?= h($lead['name'] ?: '-') ?></dd>
          <dt>Email</dt><dd><?= h($lead['email'] ?: '-') ?></dd>
          <dt>Phone</dt><dd><?= h($lead['phone'] ?: '-') ?></dd>
          <dt>Message</dt><dd><?= nl2br(h($lead['message'] ?: '-')) ?></dd>
          <?php $extra = $lead['extra_json'] ? json_decode($lead['extra_json'], true) : null; ?>
          <?php if ($extra): ?>
            <dt>Extra fields</dt>
            <dd>
              <?php foreach ($extra as $k => $v): ?>
                <div><strong><?= h((string) $k) ?>:</strong> <?= h(is_scalar($v) ? (string) $v : json_encode($v)) ?></div>
              <?php endforeach; ?>
            </dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <div class="card">
      <div class="card-header">Activity &amp; Notes</div>
      <div class="card-body">
        <form method="post" action="/leads/<?= (int) $lead['id'] ?>/note" class="mb-3">
          <?= csrf_field() ?>
          <textarea name="body" class="form-control mb-2" rows="2"
                    placeholder="Log a call, add a note, record next steps…" required></textarea>
          <button type="submit" class="btn btn-dark btn-sm">Add note</button>
        </form>

        <?php if (empty($activity)): ?>
          <p class="text-muted small mb-0">No activity yet.</p>
        <?php else: ?>
          <ul class="timeline">
            <?php foreach ($activity as $ev): ?>
              <li class="timeline-item t-<?= h($ev['type']) ?>">
                <div class="timeline-body">
                  <?php if ($ev['type'] === 'note'): ?>
                    <div class="timeline-text"><?= nl2br(h($ev['body'])) ?></div>
                  <?php else: ?>
                    <div class="timeline-text text-muted"><?= h($ev['body']) ?></div>
                  <?php endif; ?>
                  <div class="timeline-meta small text-muted">
                    <?php if (!empty($ev['author'])): ?><strong><?= h($ev['author']) ?></strong> · <?php endif; ?>
                    <span title="<?= h($ev['created_at']) ?>"><?= h(time_ago($ev['created_at'])) ?></span>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <?php
      $ua = \App\UserAgent::parse($lead['user_agent'] ?? '');
      $source = \App\UserAgent::source($extra ?: [], $lead['referrer'] ?? '');
      $langShort = $lead['language'] ? explode(',', $lead['language'])[0] : '';
    ?>
    <div class="card mb-3">
      <div class="card-header">Visitor intelligence</div>
      <div class="card-body">
        <dl class="kv mb-0" style="grid-template-columns: 110px 1fr;">
          <dt>Source</dt><dd class="small"><strong><?= h($source) ?></strong></dd>
          <dt>Device</dt><dd class="small"><?= h($ua['device']) ?><?= $ua['bot'] ? ' <span class="badge badge-spam">bot-like</span>' : '' ?></dd>
          <dt>OS</dt><dd class="small"><?= h($ua['os']) ?></dd>
          <dt>Browser</dt><dd class="small"><?= h($ua['browser']) ?></dd>
          <dt>Country</dt><dd class="small"><?= h($lead['country'] ?: '-') ?></dd>
          <dt>Language</dt><dd class="small"><?= h($langShort ?: '-') ?></dd>
        </dl>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">
        Spam analysis
        <?php if ($lead['is_spam']): ?>
          <span class="badge badge-spam ms-2">flagged</span>
        <?php else: ?>
          <span class="badge badge-converted ms-2">clean</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($lead['is_spam'] && $lead['spam_reason']): ?>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach (explode(',', (string) $lead['spam_reason']) as $r): ?>
              <span class="badge badge-contacted"><?= h(trim($r)) ?></span>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <p class="text-muted small mb-0">Passed all spam checks.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header">Raw metadata</div>
      <div class="card-body">
        <dl class="kv mb-0" style="grid-template-columns: 110px 1fr;">
          <dt>Submitted</dt><dd class="small"><?= h($lead['created_at']) ?></dd>
          <dt>IP address</dt><dd class="small"><?= h($lead['ip_address']) ?></dd>
          <dt>Referrer</dt><dd class="small" style="word-break:break-all"><?= h($lead['referrer'] ?: '-') ?></dd>
          <dt>User agent</dt><dd class="small" style="word-break:break-all"><?= h($lead['user_agent'] ?: '-') ?></dd>
        </dl>
      </div>
    </div>

    <?php if (!empty($deliveries)): ?>
      <div class="card mb-3">
        <div class="card-header">Connector deliveries</div>
        <div class="card-body">
          <?php foreach ($deliveries as $d): ?>
            <div class="d-flex align-items-center gap-2 mb-1 small">
              <span class="badge <?= $d['ok'] ? 'badge-converted' : 'badge-spam' ?>"><?= $d['ok'] ? 'sent' : 'failed' ?></span>
              <span><?= h($d['connector_name']) ?></span>
              <span class="text-muted ms-auto"><?= h($d['created_at']) ?></span>
            </div>
            <?php if (!$d['ok']): ?><div class="text-muted small mb-2"><?= h($d['detail']) ?></div><?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">Manage</div>
      <div class="card-body">
        <form method="post" action="/leads/<?= (int) $lead['id'] ?>/status" class="d-flex gap-2 mb-2">
          <?= csrf_field() ?>
          <select name="status" class="form-select form-select-sm">
            <?php foreach (['new', 'contacted', 'qualified', 'converted', 'archived', 'spam'] as $st): ?>
              <option value="<?= $st ?>" <?= $lead['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-dark btn-sm text-nowrap">Update</button>
        </form>
        <form method="post" action="/leads/<?= (int) $lead['id'] ?>/delete"
              onsubmit="return confirm('Delete this lead permanently?');">
          <?= csrf_field() ?>
          <button type="submit" class="btn btn-danger btn-sm w-100">Delete lead</button>
        </form>
      </div>
    </div>
  </div>
</div>
