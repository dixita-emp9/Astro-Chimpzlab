<?php $base = site_base_url(); ?>
<h1 class="page-title">Sites</h1>

<div class="row">
  <div class="col-md-3 mb-4">
    <div class="nav flex-column nav-pills" id="sites-tabs" role="tablist" aria-orientation="vertical">
      <button class="nav-link active" id="tab-new-site" data-bs-toggle="pill" data-bs-target="#pane-new-site" type="button" role="tab">
        <strong style="color:var(--accent)">+ Add new site</strong>
      </button>
      <?php if ($sites): ?><hr class="my-2" style="border-color:var(--line)"><?php endif; ?>
      <?php foreach ($sites as $site): ?>
        <button class="nav-link d-flex justify-content-between align-items-center" id="tab-site-<?= $site['id'] ?>" data-bs-toggle="pill" data-bs-target="#pane-site-<?= $site['id'] ?>" type="button" role="tab">
          <span class="text-truncate" style="max-width:80%"><?= h($site['name']) ?></span>
          <?php if (!$site['active']): ?><span class="badge bg-secondary" style="font-size:0.55rem;padding:0.25rem 0.4rem;color:#fff">OFF</span><?php endif; ?>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="col-md-9">
    <div class="tab-content" id="sites-tabContent">
      
      <!-- ADD NEW SITE PANE -->
      <div class="tab-pane fade show active" id="pane-new-site" role="tabpanel">
        <div class="card mb-4">
          <div class="card-header">Add a new site</div>
          <div class="card-body">
            <form method="post" action="/sites" class="row g-3 align-items-end">
              <?= csrf_field() ?>
              <div class="col-md-4">
                <label class="form-label">Name</label>
                <input type="text" class="form-control" name="name" required placeholder="e.g. Marketing site">
              </div>
              <div class="col-md-8">
                <label class="form-label">Allowed domains (comma separated, optional)</label>
                <input type="text" class="form-control" name="allowed_domain" placeholder="https://example.com, https://other.com">
              </div>
              <div class="col-md-5">
                <label class="form-label">Redirect URL (optional)</label>
                <input type="url" class="form-control" name="redirect_url" placeholder="https://example.com/thanks">
              </div>
              <div class="col-md-7">
                <label class="form-label">Success message (optional)</label>
                <input type="text" class="form-control" name="success_message" placeholder="Thanks! We will be in touch.">
              </div>
              <div class="col-12 mt-4 text-end">
                <button type="submit" class="btn btn-dark">Create site</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- EXISTING SITES PANES -->
      <?php foreach ($sites as $site): $fields = site_form_fields($site); ?>
        <div class="tab-pane fade" id="pane-site-<?= $site['id'] ?>" role="tabpanel">
          <div class="card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
              <span><?= h($site['name']) ?> Settings</span>
              <span class="badge <?= $site['active'] ? 'badge-converted' : 'badge-archived' ?>"><?= $site['active'] ? 'active' : 'disabled' ?></span>
            </div>
            <div class="card-body">

              <form method="post" action="<?= rtrim(Flight::request()->base, '/') ?>/sites/<?= (int) $site['id'] ?>/update" class="mb-4">
                <?= csrf_field() ?>
                <div class="row g-3">
                  <div class="col-md-3">
                    <label class="form-label small text-muted">API key (read-only)</label>
                    <div class="input-group input-group-sm">
                      <input type="text" class="form-control bg-light" value="<?= h($site['api_key']) ?>" readonly>
                    </div>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small text-muted">Site Name</label>
                    <input type="text" class="form-control form-control-sm" name="name" value="<?= h($site['name']) ?>" required>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label small text-muted">Allowed Domains (comma separated)</label>
                    <input type="text" class="form-control form-control-sm" name="allowed_domain" value="<?= h($site['allowed_domain']) ?>" placeholder="https://example.com, https://other.com">
                  </div>
                  <div class="col-md-4">
                    <label class="form-label small text-muted">Redirect URL</label>
                    <input type="url" class="form-control form-control-sm" name="redirect_url" value="<?= h($site['redirect_url']) ?>" placeholder="https://example.com/thanks">
                  </div>
                  <div class="col-md-8">
                    <label class="form-label small text-muted">Custom Success Message</label>
                    <input type="text" class="form-control form-control-sm" name="success_message" value="<?= h($site['success_message'] ?? '') ?>" placeholder="Thanks! We will be in touch soon.">
                  </div>
                  <div class="col-12 text-end">
                    <button type="submit" class="btn btn-outline-dark btn-sm">Save settings</button>
                  </div>
                </div>
              </form>

              <h3>Form builder</h3>
              <p class="text-muted small">
                Define the fields your lead form should have. Leave empty to use the default
                Name / Email / Phone / Message form. Fields named <code>name</code>, <code>email</code>,
                or <code>phone</code> populate the matching lead columns; anything else is stored as extra JSON data.
              </p>
              <div class="d-flex flex-wrap gap-2 mb-3">
                <select data-fb-template-select class="form-select form-select-sm" style="max-width:400px">
                  <option value="realestate">Real estate (name/phone/email + selects + tracking)</option>
                  <option value="utm">UTM lead form (name/mobile/email + hidden UTMs)</option>
                </select>
                <button type="button" class="btn btn-outline-dark btn-sm" data-fb-template>Load template</button>
              </div>
              
              <form method="post" action="/sites/<?= (int) $site['id'] ?>/fields" class="form-builder mb-4" data-form-builder>
                <?= csrf_field() ?>
                <div class="table-responsive">
                  <table class="fb-table">
                    <thead>
                      <tr>
                        <th>Field name</th><th>Label</th><th>Type</th><th>Required</th><th>Options (select) / Value (hidden)</th><th></th>
                      </tr>
                    </thead>
                    <tbody data-fb-rows>
                      <?php if (!$fields): $fields = [['name' => '', 'label' => '', 'type' => 'text', 'required' => false, 'options' => []]]; endif; ?>
                      <?php foreach ($fields as $f):
                        $type = $f['type'] ?? 'text';
                        $optionsText = $type === 'hidden' ? ($f['value'] ?? '') : implode("\n", array_map(
                            fn($o) => ($o['value'] ?? '') . '|' . ($o['label'] ?? ''), $f['options'] ?? []
                        ));
                      ?>
                        <tr class="fb-row">
                          <td><input type="text" class="form-control form-control-sm" name="field_name[]" value="<?= h($f['name'] ?? '') ?>" placeholder="fullname"></td>
                          <td><input type="text" class="form-control form-control-sm" name="field_label[]" value="<?= h($f['label'] ?? '') ?>" placeholder="Full Name"></td>
                          <td>
                            <select name="field_type[]" class="fb-type form-select form-select-sm">
                              <?php foreach (['text' => 'Text', 'email' => 'Email', 'tel' => 'Phone', 'textarea' => 'Textarea', 'select' => 'Select', 'hidden' => 'Hidden'] as $val => $lbl): ?>
                                <option value="<?= $val ?>" <?= $type === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                              <?php endforeach; ?>
                            </select>
                          </td>
                          <td class="text-center"><input type="checkbox" class="form-check-input" name="field_required[]" value="1" <?= !empty($f['required']) ? 'checked' : '' ?>></td>
                          <td>
                            <textarea name="field_options[]" class="fb-options form-control form-control-sm" rows="2" placeholder="535|2BHK - 535 sq.ft.&#10;612|2BHK - 612 sq.ft."
                              style="<?= in_array($type, ['select', 'hidden'], true) ? '' : 'display:none' ?>"><?= h($optionsText) ?></textarea>
                          </td>
                          <td><button type="button" class="btn btn-outline-dark btn-sm" data-fb-remove>×</button></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="d-flex justify-content-between mt-2">
                  <button type="button" class="btn btn-outline-primary btn-sm" data-fb-add>+ Add field</button>
                  <button type="submit" class="btn btn-dark btn-sm">Save fields</button>
                </div>
              </form>

              <hr class="my-4">

              <?php if (!$fields || (count($fields) === 1 && $fields[0]['name'] === '')): ?>
                <h3>Option A - JS widget (recommended)</h3>
                <p class="text-muted small">Drops a spam-hardened form (default Name/Email/Phone/Message fields) into the page.</p>
                <pre><?= h(
                  '<div data-microcrm="' . $site['api_key'] . '"></div>' . "\n" .
                  '<script src="' . $base . '/embed/' . $site['api_key'] . '/widget.js" async></script>'
                ) ?></pre>
                <h3 class="mt-4">Option B - Plain HTML form (no JS)</h3>
              <?php else: ?>
                <h3>Embed code (custom fields, no JS)</h3>
                <p class="text-muted small">The JS widget only supports the default field set, so custom-field forms use the plain HTML embed below.</p>
              <?php endif; ?>
              <p class="text-muted small">Works without JavaScript. Protected by honeypot, rate limiting, and content filters.</p>
              <pre><?= h(render_lead_form_snippet($site, $fields)) ?></pre>

              <hr class="my-4">

              <div class="d-flex flex-wrap justify-content-between">
                <div>
                  <form method="post" action="/sites/<?= (int) $site['id'] ?>/toggle" class="inline me-2">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-dark btn-sm"><?= $site['active'] ? 'Disable Site' : 'Enable Site' ?></button>
                  </form>
                  <form method="post" action="/sites/<?= (int) $site['id'] ?>/regenerate" class="inline"
                        onsubmit="return confirm('Regenerate the API key? The old embed code will stop working immediately.');">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-dark btn-sm">Regenerate Key</button>
                  </form>
                </div>
                <form method="post" action="/sites/<?= (int) $site['id'] ?>/delete" class="inline"
                      onsubmit="return confirm('Delete this site and all of its leads permanently?');">
                  <?= csrf_field() ?>
                  <button type="submit" class="btn btn-danger btn-sm">Delete Site</button>
                </form>
              </div>

            </div>
          </div>
        </div>
      <?php endforeach; ?>

    </div>
  </div>
</div>

<script>
// Template mirroring the Amavi-style real-estate lead form
var FB_TEMPLATES = {
  realestate: [
    { name: 'fullname', label: 'Full Name', type: 'text', required: true, options: '' },
    { name: 'phone', label: 'Mobile Number', type: 'tel', required: true, options: '' },
    { name: 'email', label: 'Email Address', type: 'email', required: false, options: '' },
    { name: 'config', label: 'Preferred Configuration', type: 'select', required: false,
      options: '535|2BHK – 535 sq.ft. (₹65.07 Lacs)\n612|2BHK – 612 sq.ft. (₹74.48 Lacs)\ndiscuss|Open to Discussion' },
    { name: 'timeline', label: 'Looking to Buy In', type: 'select', required: false,
      options: '1month|Within 1 month\n3months|1–3 months\n6months|3–6 months\nexploring|Just exploring' },
    { name: 'site_url', label: 'site_url', type: 'hidden', required: false, options: 'https://example.com/' },
    { name: 'contact', label: 'contact', type: 'hidden', required: false, options: '' }
  ],
  utm: [
    { name: 'utm_campaign', label: 'utm_campaign', type: 'hidden', required: false, options: '' },
    { name: 'utm_source', label: 'utm_source', type: 'hidden', required: false, options: '' },
    { name: 'utm_medium', label: 'utm_medium', type: 'hidden', required: false, options: '' },
    { name: 'fname', label: 'Name', type: 'text', required: true, options: '' },
    { name: 'mobile', label: 'Mobile', type: 'tel', required: true, options: '' },
    { name: 'email', label: 'Email', type: 'email', required: true, options: '' }
  ]
};

document.querySelectorAll('[data-form-builder]').forEach(function (form) {
  var rows = form.querySelector('[data-fb-rows]');
  var prototype = rows.querySelector('.fb-row').cloneNode(true);
  prototype.querySelectorAll('input[type=text], textarea').forEach(function (el) { el.value = ''; });
  prototype.querySelector('input[type=checkbox]').checked = false;
  prototype.querySelector('.fb-type').value = 'text';
  prototype.querySelector('.fb-options').style.display = 'none';

  function wireRow(row) {
    var typeSelect = row.querySelector('.fb-type');
    var optionsBox = row.querySelector('.fb-options');
    typeSelect.addEventListener('change', function () {
      optionsBox.style.display = (typeSelect.value === 'select' || typeSelect.value === 'hidden') ? '' : 'none';
    });
    row.querySelector('[data-fb-remove]').addEventListener('click', function () {
      if (rows.querySelectorAll('.fb-row').length > 1) {
        row.remove();
      } else {
        row.querySelectorAll('input[type=text], textarea').forEach(function (el) { el.value = ''; });
        row.querySelector('input[type=checkbox]').checked = false;
      }
    });
  }

  function addRow(data) {
    var row = prototype.cloneNode(true);
    if (data) {
      row.querySelector('input[name="field_name[]"]').value = data.name;
      row.querySelector('input[name="field_label[]"]').value = data.label;
      row.querySelector('.fb-type').value = data.type;
      row.querySelector('input[type=checkbox]').checked = !!data.required;
      var optionsBox = row.querySelector('.fb-options');
      optionsBox.value = data.options;
      optionsBox.style.display = (data.type === 'select' || data.type === 'hidden') ? '' : 'none';
    }
    rows.appendChild(row);
    wireRow(row);
    return row;
  }

  rows.querySelectorAll('.fb-row').forEach(wireRow);

  form.querySelector('[data-fb-add]').addEventListener('click', function () { addRow(null); });

  var templateBtn = form.closest('.card').querySelector('[data-fb-template]');
  var templateSelect = form.closest('.card').querySelector('[data-fb-template-select]');
  if (templateBtn && templateSelect) {
    templateBtn.addEventListener('click', function () {
      var key = templateSelect.value;
      var template = FB_TEMPLATES[key];
      if (!template || !confirm('Replace the current fields with the "' + templateSelect.options[templateSelect.selectedIndex].text + '" template?')) {
        return;
      }
      rows.querySelectorAll('.fb-row').forEach(function (r) { r.remove(); });
      template.forEach(function (f) { addRow(f); });
    });
  }
});

// Restore last selected tab if any, else default to 'New Site'
document.addEventListener("DOMContentLoaded", function() {
  const lastTab = localStorage.getItem('activeSiteTab');
  if (lastTab) {
    const tabEl = document.querySelector(`[data-bs-target="${lastTab}"]`);
    if (tabEl) {
      new bootstrap.Tab(tabEl).show();
    }
  }
  
  // Listen for tab changes
  const triggerTabList = document.querySelectorAll('#sites-tabs button[data-bs-toggle="pill"]');
  triggerTabList.forEach(triggerEl => {
    triggerEl.addEventListener('shown.bs.tab', event => {
      localStorage.setItem('activeSiteTab', event.target.getAttribute('data-bs-target'));
    });
  });
});
</script>
