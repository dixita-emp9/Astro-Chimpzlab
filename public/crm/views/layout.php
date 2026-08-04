<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title ?? 'MicroCRM') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= rtrim(Flight::request()->base, '/') ?>/assets/bootstrap.min.css">
  <link rel="stylesheet" href="<?= rtrim(Flight::request()->base, '/') ?>/assets/admin.css">
</head>
<body>
<?php
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
  $nav = [
    '/dashboard'  => 'Dashboard',
    '/leads'      => 'Leads',
    '/sites'      => 'Sites',
    '/connectors' => 'Connectors',
    '/spam-rules' => 'Spam Rules',
    '/settings'   => 'Settings',
  ];
  $isActive = fn(string $href) => $href === '/dashboard'
    ? ($path === '/dashboard' || $path === '/')
    : str_starts_with($path, $href);
?>
<?php if ($currentUser): ?>
  <nav class="navbar navbar-expand-lg navbar-mc">
    <div class="container">
      <a class="navbar-brand" href="<?= rtrim(Flight::request()->base, '/') ?>/dashboard"><span class="mark">M</span>MicroCRM</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mcNav" aria-controls="mcNav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="mcNav">
        <ul class="navbar-nav me-auto ms-lg-4">
          <?php foreach ($nav as $href => $label): ?>
            <li class="nav-item">
              <a class="nav-link <?= $isActive($href) ? 'active' : '' ?>" href="<?= rtrim(Flight::request()->base, '/') ?><?= $href ?>"><?= $label ?></a>
            </li>
          <?php endforeach; ?>
        </ul>
        <span class="navbar-text small">
          <?= h($currentUser['username']) ?> &middot; <a href="<?= rtrim(Flight::request()->base, '/') ?>/logout">Logout</a>
        </span>
      </div>
    </div>
  </nav>
<?php endif; ?>
<main class="container py-4">
  <?php $ok = flash('success'); if ($ok): ?><div class="alert alert-success"><?= h($ok) ?></div><?php endif; ?>
  <?php $err = flash('error'); if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
  <?= $content ?>
</main>
<script src="<?= rtrim(Flight::request()->base, '/') ?>/assets/bootstrap.bundle.min.js"></script>
</body>
</html>
