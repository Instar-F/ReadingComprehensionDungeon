<?php
/**
 * User login page for Reading Comprehension Dungeon.
 * 
 * Flow:
 * 1. Display login form
 * 2. On POST submission:
 *    a. Validate email format
 *    b. Look up user by email
 *    c. Verify password using password_verify() (compares against password_hash stored in DB)
 *    d. If valid: Set session user_id and redirect to menu
 *    e. If invalid: Show error message
 * 
 * Security notes:
 * - Uses password_verify() which is timing-attack safe
 * - Passwords are stored as bcrypt hashes (created with password_hash())
 * - Generic error message "Fel e-post eller lösenord" prevents user enumeration
 * - CSRF protection should be added for production (e.g., session tokens)
 */

require_once __DIR__ . '/../config.php';

$errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Ogiltig e-post.';
    }

    // If validation passed, check credentials
    if (empty($errors)) {
        // Look up user by email
        $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $u = $stmt->fetch();
        
        // Verify password against stored hash
        if ($u && password_verify($password, $u['password_hash'])) {
            // Success: Set session and redirect
            $_SESSION['user_id'] = $u['id'];
            header('Location: ../menu.php');
            exit;
        } else {
            // Generic error to prevent username enumeration
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
    <div class="card auth-card mx-auto">
      <div class="card-body">
        <div class="text-center mb-3">
          <h1 class="gametext mb-1">RC Dungeon</h1>
          <div class="auth-subtitle">Logga in för att fortsätta</div>
        </div>
        
        <?php if ($errors): ?>
          <div class="alert alert-danger">
            <?php echo htmlspecialchars(implode('<br>', $errors)); ?>
          </div>
        <?php endif; ?>
        
        <form method="post">
          <div class="mb-3">
            <label class="form-label">E-post</label>
            <input name="email" type="email" class="form-control" 
                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label">Lösenord</label>
            <input name="password" type="password" class="form-control">
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <a href="signup.php" class="auth-link">Skapa konto</a>
            <button class="btn btn-warning">Logga in</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>