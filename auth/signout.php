<?php
/**
 * Logs out the current user by destroying their session.
 * 
 * Flow:
 * 1. Start session (if not already started)
 * 2. Clear all session variables
 * 3. Destroy the session
 * 4. Redirect to signin page
 */

require_once __DIR__ . '/../config.php';

// Clear session data
$_SESSION = [];

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: signin.php');
exit;