<?php
/** 
 * User registration page for Reading Comprehension Dungeon.
 * 
 * Flow:
 * 1. Display registration form
 * 2. On POST submission:
 *    a. Validate all inputs (name, email, password, password confirmation)
 *    b. Check if email already exists in database
 *    c. Hash password using password_hash() with PASSWORD_DEFAULT algorithm
 *    d. Insert new user record
 *    e. Log user in by setting session user_id
 *    f. Redirect to menu
 * 
 * Validation rules:
 * - Name: Required (cannot be empty)
 * - Email: Must be valid email format
 * - Password: Minimum 6 characters
 * - Password confirmation: Must match password
 * - Email uniqueness: Must not already exist in database
 * 
 * Security notes:
 * - Passwords are hashed with bcrypt (via PASSWORD_DEFAULT)
 * - Never stores plain text passwords
 * - Email uniqueness prevents duplicate accounts
 * - Should add CSRF protection for production
 */

require_once __DIR__ . '/../config.php';

$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    // Validate inputs
    if ($name === '') {
        $errors[] = 'Namn krävs.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ogiltig e-post.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Lösenord måste vara minst 6 tecken.';
    }
    if ($password !== $password2) {
        $errors[] = 'Lösenorden matchar inte.';
    }

    // If validation passed, check email uniqueness and create account
    if (empty($errors)) {
        // Check if email already exists
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $errors[] = 'E-post används redan.';
        } else {
            // Create new user account
            // password_hash() uses bcrypt by default, which is secure and salted
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $ins = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $ins->execute([$name, $email, $hash]);
            
            // Auto-login the new user by setting session
            $_SESSION['user_id'] = $pdo->lastInsertId();
            header('Location: ../menu.php');
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
<body class="sky-bg">
  <div class="centercontainer container py-5">
    <div class="card auth-card mx-auto">
      <div class="card-body">
        <div class="text-center mb-3">
          <h1 class="gametext mb-1">RC Dungeon</h1>
          <div class="auth-subtitle">Skapa ett konto</div>
        </div>
        
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
            <input name="name" class="form-control" 
                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" autofocus>
          </div>
          <div class="mb-2">
            <label class="form-label">E-post</label>
            <input name="email" type="email" class="form-control" 
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
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
            <a href="signin.php" class="auth-link">Redan registrerad?</a>
            <button class="btn btn-warning">Skapa konto</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
