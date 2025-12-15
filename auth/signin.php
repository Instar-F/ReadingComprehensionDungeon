<?php
require_once __DIR__ . '/../config.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Ogiltig e-post.';

    if (empty($errors)) {
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        if ($u && password_verify($password, $u['password_hash'])) {
            $_SESSION['user_id'] = $u['id'];
            header('Location: ../menu.php');
            exit;
        } else {
            $errors[] = 'Fel e-post eller lösenord.';
        }
    }
}
?>
<!doctype html>
<html lang="sv">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Logga in - Lärportal</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="sky-bg">
  <div class="centercontainer container py-5">
    <div class="card mx-auto" style="min-width:50vw; max-height:750px;">
      <div class="card-body">
        <h2 class="h5">Logga in</h2>
        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <?php echo htmlspecialchars(implode('<br>', $errors)); ?>
          </div>
        <?php endif; ?>
        <form method="post">
          <div class="mb-2">
            <label class="form-label">E-post</label>
            <input name="email" type="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Lösenord</label>
            <input name="password" type="password" class="form-control">
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <a href="signup.php">Skapa konto</a>
            <button class="btn btn-primary">Logga in</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
