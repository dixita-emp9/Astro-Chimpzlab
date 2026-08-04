<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Thank You</title>
  <style>
    body {
      margin: 0;
      padding: 0;
      background: #f8f9fa;
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      display: flex;
      align-items: center;
      justify-content: center;
      min-height: 100vh;
      color: #333;
    }
    .card {
      background: #fff;
      padding: 40px;
      border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
      text-align: center;
      max-width: 500px;
      width: 90%;
    }
    .icon {
      font-size: 48px;
      color: #198754;
      margin-bottom: 20px;
    }
    h1 {
      margin: 0 0 16px;
      font-size: 24px;
      font-weight: 600;
    }
    p {
      margin: 0 0 24px;
      font-size: 16px;
      color: #666;
      line-height: 1.5;
    }
    a {
      display: inline-block;
      text-decoration: none;
      color: #fff;
      background: #000;
      padding: 10px 24px;
      border-radius: 6px;
      font-weight: 500;
      transition: opacity 0.2s;
    }
    a:hover {
      opacity: 0.8;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">✓</div>
    <h1>Success</h1>
    <p><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
    <a href="javascript:history.back()">Go Back</a>
  </div>
</body>
</html>
