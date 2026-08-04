<?php
$totalLeads = (int)$stats['total'];
$convertedLeads = (int)($statusBreakdown['converted'] ?? 0);
$convRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 1) : 0;

$chartData = [];
$maxCount = 1;
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $cnt = (int)($dailyTrend[$d] ?? 0);
    $chartData[$d] = $cnt;
    if ($cnt > $maxCount) $maxCount = $cnt;
}
?>
<h1 class="page-title">Dashboard</h1>

<!-- TOP STATS -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card">
      <div class="n"><?= $totalLeads ?></div>
      <div class="l">Total leads</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card sc-green">
      <div class="n"><?= $convRate ?>%</div>
      <div class="l">Conversion Rate</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card sc-amber">
      <div class="n"><?= (int) $stats['week'] ?></div>
      <div class="l">Last 7 days</div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card sc-red">
      <div class="n"><?= (int) $stats['spam'] ?></div>
      <div class="l">Marked spam</div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- TREND CHART -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">14-Day Traffic Trend</div>
      <div class="card-body pt-4">
        <div class="css-chart">
          <?php foreach ($chartData as $d => $cnt): 
              $height = round(($cnt / $maxCount) * 100);
              // Make sure a tiny height exists so hover works on zero days
              $height = max(2, $height);
          ?>
            <div class="css-chart-bar" style="height: <?= $height ?>%" data-val="<?= $cnt ?>">
              <div class="css-chart-label"><?= date('M j', strtotime($d)) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  
  <!-- STATUS BREAKDOWN -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Sales Pipeline</div>
      <div class="card-body">
        <div class="d-flex flex-column gap-3">
          <?php 
          $stages = [
            'new' => ['New Leads', 'badge-new'],
            'contacted' => ['Contacted', 'badge-contacted'],
            'qualified' => ['Qualified', 'badge-qualified'],
            'converted' => ['Converted', 'badge-converted'],
            'archived' => ['Archived', 'badge-archived'],
          ];
          foreach ($stages as $key => $info):
            $count = (int)($statusBreakdown[$key] ?? 0);
            $pct = $totalLeads > 0 ? round(($count / $totalLeads) * 100) : 0;
          ?>
            <div class="d-flex align-items-center">
              <div style="width: 120px;"><span class="badge <?= $info[1] ?> w-100"><?= $info[0] ?></span></div>
              <div class="ms-3 me-3 text-end" style="width: 40px; font-weight:600;"><?= $count ?></div>
              <div class="flex-grow-1 bg-light rounded" style="height: 8px;">
                <div class="rounded h-100" style="background:var(--ink-soft); width: <?= $pct ?>%;"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($recentFailures): ?>
<div class="alert alert-danger mb-4 d-flex align-items-start">
  <div class="me-3 fs-4">⚠️</div>
  <div>
    <strong>Delivery Warning:</strong> We detected recent webhook or email failures.
    <ul class="mb-0 mt-1 small">
      <?php foreach ($recentFailures as $fail): ?>
        <li><?= h($fail['connector_name']) ?> (<?= h($fail['site_name']) ?>) - <?= h($fail['detail']) ?> <em>(<?= h($fail['created_at']) ?>)</em></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Leads by site</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Site</th><th class="text-end">Leads</th></tr></thead>
          <tbody>
            <?php foreach ($bySite as $row): ?>
              <tr>
                <td><?= h($row['name']) ?></td>
                <td class="text-end fw-semibold"><?= (int) $row['lead_count'] ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$bySite): ?>
              <tr><td colspan="2" class="text-muted">No sites yet - add one under <a href="/sites">Sites</a>.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Recent leads</div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead><tr><th>Date</th><th>Site</th><th>Name</th><th>Email</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($recent as $lead): ?>
              <tr>
                <td class="text-muted small text-nowrap"><?= date('M j, g:ia', strtotime($lead['created_at'])) ?></td>
                <td class="small"><?= h($lead['site_name']) ?></td>
                <td><a href="/leads/<?= (int) $lead['id'] ?>" class="text-decoration-none fw-semibold"><?= h($lead['name'] ?: '(no name)') ?></a></td>
                <td class="small text-muted"><?= h($lead['email']) ?></td>
                <td><span class="badge badge-<?= h($lead['status']) ?>"><?= h($lead['status']) ?></span></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?>
              <tr><td colspan="5" class="text-muted text-center py-4">No leads yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
