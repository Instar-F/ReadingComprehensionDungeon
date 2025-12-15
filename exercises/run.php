<?php
require_once __DIR__ . '/../config.php';
if (!is_logged_in()) { header('Location: ../auth/signin.php'); exit; }

$exid = isset($_GET['exid']) ? (int)$_GET['exid'] : 0;
if (!$exid) { header('Location: ../menu.php'); exit; }

// Make sure exercise exists
$stmt = $pdo->prepare('SELECT id FROM exercises WHERE id = ? LIMIT 1');
$stmt->execute([$exid]);
$ex = $stmt->fetch();
if (!$ex) { echo "Övningen hittades inte."; exit; }

// Redirect to unified play page (handles all types)
header('Location: play.php?exercise_id=' . $exid);
exit;
