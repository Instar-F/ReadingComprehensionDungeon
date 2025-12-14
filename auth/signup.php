<?php
require_once __DIR__ . '/../config.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if ($name === '') $errors[] = 'Namn krävs.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ogiltig e-post.';
    if (strlen($password) < 6) $errors[] = 'Lösenord måste vara minst 6 tecken.';
    if ($password !== $password2) $errors[] = 'Lösenorden matchar inte.';

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'E-post används redan.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $ins->execute([$name, $email, $hash]);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            header('Location: ../dashboard.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Registrera - Lärportal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="bg-light">
  <div class="container py-5">
    <div class="card mx-auto" style="max-width:480px;">
      <div class="card-body">
        <h2 class="h5">Registrera</h2>
        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <ul class="mb-0">
              <?php foreach ($errors as $e): ?>
                <li><?php echo htmlspecialchars($e); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
        <form method="post">
          <div class="mb-2">
            <label class="form-label">Namn</label>
            <input name="name" class="form-control" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">E-post</label>
            <input name="email" type="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">Lösenord</label>
            <input name="password" type="password" class="form-control">
          </div>
          <div class="mb-3">
            <label class="form-label">Bekräfta lösenord</label>
            <input name="password2" type="password" class="form-control">
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <a href="signin.php">Redan registrerad?</a>
            <button class="btn btn-primary">Skapa konto</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
