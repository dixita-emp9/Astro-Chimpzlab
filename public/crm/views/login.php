<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login · MicroCRM</title>
  <link rel="stylesheet" href="<?= rtrim(Flight::request()->base, '/') ?>/assets/bootstrap.min.css">
  <link rel="stylesheet" href="<?= rtrim(Flight::request()->base, '/') ?>/assets/admin.css">
</head>
<body class="login-page">
  <div class="login-box">
    <div class="brand-lg"><span class="mark">M</span>MicroCRM</div>
    <?php if (!empty($error)): ?>
      <div class="alert alert-danger py-2"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= rtrim(Flight::request()->base, '/') ?>/login">
      <?= csrf_field() ?>
      <div class="mb-3">
        <label class="form-label">Username</label>
        <input type="text" class="form-control" name="username" autocomplete="username" autofocus required>
      </div>
      <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" class="form-control" name="password" autocomplete="current-password" required>
      </div>
      <button type="submit" class="btn btn-dark w-100">Log in</button>
    </form>
  </div>
</body>
</html>
