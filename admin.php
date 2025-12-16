<?php
require_once __DIR__ . '/config.php';

if (!is_logged_in()) {
    header('Location: auth/signin.php');
    exit;
}

$user = current_user($pdo);
$isAdmin = !empty($user['is_admin']);

if (!$isAdmin) {
    die('Access denied. Admin only.');
}

$message = '';
$messageType = '';
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'exercises';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            $activeTab = $_POST['active_tab'] ?? 'exercises';
            
            switch ($_POST['action']) {
                case 'add_exercise':
                    if (!isset($_POST['title']) || empty(trim($_POST['title']))) {
                        throw new Exception("Titel kan inte vara tom");
                    }
                    
                    // EXP-percentage is default now; do not store per-exercise flag
                    $metadataJson = json_encode((object)[]);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO exercises (title, difficulty, min_level, storyline_id, metadata, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        trim($_POST['title']),
                        $_POST['difficulty'],
                        (int)($_POST['min_level'] ?? 1),
                        !empty($_POST['storyline_id']) ? $_POST['storyline_id'] : null,
                        $metadataJson
                    ]);
                    $message = "Övning skapad!";
                    $messageType = "success";
                    break;

                case 'edit_exercise':
                    if (!isset($_POST['title']) || empty(trim($_POST['title']))) {
                        throw new Exception("Titel kan inte vara tom");
                    }
                    
                    // EXP-percentage is default now; do not modify per-exercise metadata here
                    $metadataJson = json_encode((object)[]);
                    
                    $stmt = $pdo->prepare("
                        UPDATE exercises 
                        SET title = ?, difficulty = ?, min_level = ?, storyline_id = ?, metadata = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        trim($_POST['title']),
                        $_POST['difficulty'],
                        (int)($_POST['min_level'] ?? 1),
                        !empty($_POST['storyline_id']) ? $_POST['storyline_id'] : null,
                        $metadataJson,
                        $_POST['exercise_id']
                    ]);
                    $message = "Övning uppdaterad!";
                    $messageType = "success";
                    break;

                case 'delete_exercise':
                    $stmt = $pdo->prepare("DELETE FROM exercises WHERE id = ?");
                    $stmt->execute([$_POST['exercise_id']]);
                    $message = "Övning raderad!";
                    $messageType = "success";
                    break;

                case 'add_storyline':
                    if (!isset($_POST['name']) || empty(trim($_POST['name']))) {
                        throw new Exception("Namn kan inte vara tomt");
                    }
                    $stmt = $pdo->prepare("
                        INSERT INTO storylines (name, description, icon, display_order) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        trim($_POST['name']),
                        trim($_POST['description'] ?? ''),
                        trim($_POST['icon'] ?? '📖'),
                        $_POST['display_order']
                    ]);
                    $message = "Storyline skapad!";
                    $messageType = "success";
                    break;

                case 'edit_storyline':
                    if (!isset($_POST['name']) || empty(trim($_POST['name']))) {
                        throw new Exception("Namn kan inte vara tomt");
                    }
                    $stmt = $pdo->prepare("
                        UPDATE storylines 
                        SET name = ?, description = ?, icon = ?, display_order = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        trim($_POST['name']),
                        trim($_POST['description'] ?? ''),
                        trim($_POST['icon'] ?? '📖'),
                        $_POST['display_order'],
                        $_POST['storyline_id']
                    ]);
                    $message = "Storyline uppdaterad!";
                    $messageType = "success";
                    break;

                case 'delete_storyline':
                    $stmt = $pdo->prepare("DELETE FROM storylines WHERE id = ?");
                    $stmt->execute([$_POST['storyline_id']]);
                    $message = "Storyline raderad!";
                    $messageType = "success";
                    break;

                case 'add_question':
                    if (!isset($_POST['content']) || empty(trim($_POST['content']))) {
                        throw new Exception("Frågetext kan inte vara tom");
                    }
                    
                    $meta = json_encode(['points' => (int)$_POST['points']]);
                    $stmt = $pdo->prepare("
                        INSERT INTO questions (exercise_id, type, content, meta, pos) 
                        VALUES (?, ?, ?, ?, 0)
                    ");
                    $stmt->execute([
                        $_POST['exercise_id'],
                        $_POST['type'],
                        trim($_POST['content']),
                        $meta
                    ]);
                    $questionId = $pdo->lastInsertId();
                    
                    // Handle different question types
                    $type = $_POST['type'];
                    
                    if ($type === 'mcq') {
                        // MCQ - use user-provided choices (allow multiple correct answers)
                        
                        $choiceStmt = $pdo->prepare("
                            INSERT INTO question_choices (question_id, content, is_correct, pos) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $pos = 0;
                        $rawChoices = $_POST['choices'] ?? [];
                        $correctIndexes = array_map('strval', $_POST['correct_choices'] ?? []);
                        $nonEmpty = 0;
                        foreach ($rawChoices as $idx => $choice) {
                            $choice = trim($choice);
                            if ($choice === '') continue;
                            $isCorrect = in_array((string)$idx, $correctIndexes, true) ? 1 : 0;
                            $choiceStmt->execute([$questionId, $choice, $isCorrect, $pos]);
                            $pos++;
                            $nonEmpty++;
                        }

                        if ($nonEmpty < 2) {
                            $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$questionId]);
                            throw new Exception("MCQ måste ha minst 2 alternativa svar.");
                        }
                        // Ensure at least one correct answer
                        $correctCheck = $pdo->prepare("SELECT COUNT(*) AS c FROM question_choices WHERE question_id=? AND is_correct=1");
                        $correctCheck->execute([$questionId]);
                        if ((int)$correctCheck->fetch(PDO::FETCH_ASSOC)['c'] === 0) {
                            // default to first choice if none selected
                            $firstStmt = $pdo->prepare("UPDATE question_choices SET is_correct=1 WHERE question_id=? ORDER BY pos ASC LIMIT 1");
                            $firstStmt->execute([$questionId]);
                        }
                    } 
                    elseif ($type === 'truefalse') {
                        // True/False - always create Sant and Falskt
                        if (!isset($_POST['tf_correct'])) {
                            $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$questionId]);
                            throw new Exception("Du måste välja om Sant eller Falskt är rätt!");
                        }
                        
                        $choiceStmt = $pdo->prepare("
                            INSERT INTO question_choices (question_id, content, is_correct, pos) 
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        // Sant (index 0)
                        $choiceStmt->execute([
                            $questionId, 
                            'Sant', 
                            ($_POST['tf_correct'] == 'sant') ? 1 : 0, 
                            0
                        ]);
                        
                        // Falskt (index 1)
                        $choiceStmt->execute([
                            $questionId, 
                            'Falskt', 
                            ($_POST['tf_correct'] == 'falskt') ? 1 : 0, 
                            1
                        ]);
                    }
                    elseif ($type === 'fillblank') {
                        // Fill-blank - allow multiple correct answers (validation performed after inserting non-empty choices)
                        
                        $choiceStmt = $pdo->prepare("
                            INSERT INTO question_choices (question_id, content, is_correct, pos) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $pos = 0; $nonEmpty = 0;
                        $rawChoices = $_POST['fb_choices'] ?? [];
                        $correctIndexes = array_map('strval', $_POST['fb_corrects'] ?? []);
                        foreach ($rawChoices as $idx => $choice) {
                            $choice = trim($choice);
                            if ($choice === '') continue;
                            $isCorrect = in_array((string)$idx, $correctIndexes, true) ? 1 : 0;
                            $choiceStmt->execute([$questionId, $choice, $isCorrect, $pos]);
                            $pos++; $nonEmpty++;
                        }

                        if ($nonEmpty < 2) {
                            $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$questionId]);
                            throw new Exception("Fyll-i måste ha minst 2 alternativ.");
                        }
                        // Ensure at least one correct option
                        $correctCheck = $pdo->prepare("SELECT COUNT(*) AS c FROM question_choices WHERE question_id=? AND is_correct=1");
                        $correctCheck->execute([$questionId]);
                        if ((int)$correctCheck->fetch(PDO::FETCH_ASSOC)['c'] === 0) {
                            $firstStmt = $pdo->prepare("UPDATE question_choices SET is_correct=1 WHERE question_id=? ORDER BY pos ASC LIMIT 1");
                            $firstStmt->execute([$questionId]);
                        }
                    }
                    elseif ($type === 'ordering') {
                        // Ordering items
                        if (empty($_POST['ordering_items'])) {
                            $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$questionId]);
                            throw new Exception("Ordningsfråga måste ha minst 2 steg!");
                        }
                        
                        $orderStmt = $pdo->prepare("
                            INSERT INTO ordering_items (question_id, content, pos, correct_pos) 
                            VALUES (?, ?, 0, ?)
                        ");
                        $correctPos = 0;
                        foreach ($_POST['ordering_items'] as $item) {
                            if (!empty(trim($item))) {
                                $orderStmt->execute([$questionId, trim($item), $correctPos]);
                                $correctPos++;
                            }
                        }
                    }
                    
                    $message = "Fråga skapad!";
                    $messageType = "success";
                    break;

                case 'edit_question':
                    if (!isset($_POST['content']) || empty(trim($_POST['content']))) {
                        throw new Exception("Frågetext kan inte vara tom");
                    }
                    $meta = json_encode(['points' => (int)$_POST['points']]);
                    $stmt = $pdo->prepare("
                        UPDATE questions 
                        SET content = ?, meta = ?, type = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        trim($_POST['content']),
                        $meta,
                        $_POST['type'],
                        $_POST['question_id']
                    ]);
                    $message = "Fråga uppdaterad!";
                    $messageType = "success";
                    break;

                case 'delete_question':
                    $stmt = $pdo->prepare("DELETE FROM questions WHERE id = ?");
                    $stmt->execute([$_POST['question_id']]);
                    $message = "Fråga raderad!";
                    $messageType = "success";
                    break;

                case 'run_sql':
                    // Run arbitrary SQL supplied by admin. Confirmation checkbox required.
                    $sql = trim($_POST['sql'] ?? '');
                    if ($sql === '') {
                        throw new Exception("Ingen SQL angiven");
                    }
                    if (!isset($_POST['confirm_execute']) || $_POST['confirm_execute'] != '1') {
                        throw new Exception("Bekräfta att du vill köra SQL genom att kryssa i bekräftelsen.");
                    }
                    // Execute statements in a transaction
                    $pdo->beginTransaction();
                    try {
                        $stmts = array_filter(array_map('trim', explode(';', $sql)));
                        $count = 0;
                        foreach ($stmts as $s) {
                            if ($s === '') continue;
                            $pdo->exec($s);
                            $count++;
                        }
                        $pdo->commit();
                        $message = "SQL-körning slutförd. Antal statements: $count";
                        $messageType = "success";
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $e;
                    }
                    break;

                case 'add_passage':
                    if (!isset($_POST['content']) || empty(trim($_POST['content']))) {
                        throw new Exception("Textinnehåll kan inte vara tomt");
                    }
                    $stmt = $pdo->prepare("
                        INSERT INTO exercise_passages (exercise_id, content) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE content = VALUES(content)
                    ");
                    $stmt->execute([
                        $_POST['exercise_id'],
                        trim($_POST['content'])
                    ]);
                    $message = "Passage sparad!";
                    $messageType = "success";
                    break;
            }
        }
    } catch (Exception $e) {
        $message = "Fel: " . $e->getMessage();
        $messageType = "danger";
    }
    
    header("Location: admin.php?tab=" . urlencode($activeTab) . "&msg=" . urlencode($message) . "&type=" . urlencode($messageType));
    exit;
}

if (isset($_GET['msg'])) {
    $message = $_GET['msg'];
    $messageType = $_GET['type'] ?? 'info';
}

// Fetch data
$exercises = $pdo->query("
    SELECT e.*, s.name as storyline_name,
           (SELECT COUNT(*) FROM questions WHERE exercise_id = e.id) as question_count
    FROM exercises e
    LEFT JOIN storylines s ON e.storyline_id = s.id
    ORDER BY e.created_at DESC
")->fetchAll();

$storylines = $pdo->query("SELECT * FROM storylines ORDER BY display_order, id")->fetchAll();

$questions = $pdo->query("
    SELECT q.*, e.title as exercise_title,
           JSON_UNQUOTE(JSON_EXTRACT(q.meta, '$.points')) as points,
           (SELECT COUNT(*) FROM question_choices WHERE question_id = q.id) as choice_count,
           (SELECT COUNT(*) FROM ordering_items WHERE question_id = q.id) as ordering_count
    FROM questions q
    JOIN exercises e ON q.exercise_id = e.id
    ORDER BY e.id, q.pos, q.id
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="sv">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Panel — RC Dungeon</title>
<style>
* { box-sizing: border-box; }
body { 
    font-family: Arial, sans-serif; 
    margin: 0;
    padding: 20px;
    background: #1a1a1a;
    color: #fff;
}
.container { max-width: 1400px; margin: 0 auto; }
.header { 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #444;
}
.btn { 
    padding: 8px 16px; 
    border: none; 
    border-radius: 4px; 
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-size: 14px;
}
.btn-primary { background: #007bff; color: white; }
.btn-danger { background: #dc3545; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-warning { background: #ffc107; color: #000; font-weight: bold; }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.alert { 
    padding: 12px; 
    margin-bottom: 20px; 
    border-radius: 4px; 
}
.alert-success { background: #28a745; color: white; }
.alert-danger { background: #dc3545; color: white; }
.tabs { 
    display: flex; 
    gap: 5px; 
    margin-bottom: 20px;
    border-bottom: 2px solid #444;
}
.tab { 
    padding: 10px 20px; 
    cursor: pointer; 
    background: #2a2a2a;
    border: none;
    color: #fff;
    font-size: 14px;
}
.tab.active { 
    background: #444; 
    border-bottom: 3px solid #ffc107;
}
.tab-content { display: none; }
.tab-content.active { display: block; }
.form-group { margin-bottom: 15px; }
.form-group label { 
    display: block; 
    margin-bottom: 5px; 
    font-weight: bold;
}
.form-control { 
    width: 100%; 
    padding: 8px; 
    border: 1px solid #555;
    border-radius: 4px;
    background: #2a2a2a;
    color: #fff;
}
.form-control:focus {
    outline: none;
    border-color: #ffc107;
}
textarea.form-control { 
    min-height: 100px; 
    resize: vertical; 
    font-family: monospace;
}
table { 
    width: 100%; 
    border-collapse: collapse; 
    margin-top: 15px;
    font-size: 14px;
}
table th, table td { 
    padding: 10px; 
    text-align: left; 
    border-bottom: 1px solid #444;
}
table th { 
    background: #2a2a2a; 
    font-weight: bold;
}
table tr:hover { background: #2a2a2a; }
.card { 
    background: #2a2a2a; 
    padding: 20px; 
    border-radius: 8px; 
    margin-bottom: 20px;
}
.row { 
    display: flex; 
    gap: 20px; 
    margin-bottom: 20px;
}
.col-4 { flex: 0 0 32%; }
.col-8 { flex: 0 0 65%; }
.badge { 
    padding: 4px 8px; 
    border-radius: 4px; 
    font-size: 12px;
    display: inline-block;
}
.badge-easy { background: #28a745; }
.badge-medium { background: #ffc107; color: #000; }
.badge-hard { background: #dc3545; }
.badge-info { background: #17a2b8; }
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.8);
}
.modal.show { display: flex; align-items: center; justify-content: center; }
.modal-content {
    background: #2a2a2a;
    padding: 20px;
    border-radius: 8px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid #444;
}
.modal-footer {
    margin-top: 15px;
    padding-top: 10px;
    border-top: 1px solid #444;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}
.close {
    cursor: pointer;
    font-size: 24px;
    line-height: 1;
}
.input-group {
    display: flex;
    margin-bottom: 10px;
    gap: 10px;
    align-items: center;
}
.input-group input[type="text"] {
    flex: 1;
}
.input-group-append {
    display: flex;
    align-items: center;
    gap: 5px;
}
.question-type-fields {
    display: none;
    padding: 15px;
    background: #1a1a1a;
    border-radius: 4px;
    margin-top: 10px;
}
.question-type-fields.active {
    display: block;
}
.note {
    font-size: 13px;
    color: #ffc107;
    padding: 10px;
    background: rgba(255, 193, 7, 0.1);
    border-left: 3px solid #ffc107;
    margin-top: 10px;
}
.tf-choice {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 12px 20px;
    background: #333;
    border: 2px solid #555;
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.2s;
}
.tf-choice:hover {
    background: #444;
    border-color: #ffc107;
}
.tf-choice input[type="radio"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
}
.tf-choice.selected {
    background: #444;
    border-color: #ffc107;
}
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    padding: 8px;
    background: #333;
    border-radius: 4px;
}
.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>⚙️ Admin Panel</h1>
        <a href="menu.php" class="btn btn-secondary">← Tillbaka till Meny</a>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $messageType ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab <?= $activeTab === 'exercises' ? 'active' : '' ?>" onclick="showTab('exercises')">Övningar (<?= count($exercises) ?>)</button>
        <button class="tab <?= $activeTab === 'storylines' ? 'active' : '' ?>" onclick="showTab('storylines')">Storylines (<?= count($storylines) ?>)</button>
        <button class="tab <?= $activeTab === 'questions' ? 'active' : '' ?>" onclick="showTab('questions')">Frågor (<?= count($questions) ?>)</button>
        <button class="tab <?= $activeTab === 'passages' ? 'active' : '' ?>" onclick="showTab('passages')">Textpassager</button>
        <button class="tab <?= $activeTab === 'tools' ? 'active' : '' ?>" onclick="showTab('tools')">Verktyg</button>
    </div>

    <!-- EXERCISES TAB -->
    <div id="exercises" class="tab-content <?= $activeTab === 'exercises' ? 'active' : '' ?>">
        <div class="row">
            <div class="col-4">
                <div class="card">
                    <h3>Lägg till Övning</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_exercise">
                        <input type="hidden" name="active_tab" value="exercises">
                        <div class="form-group">
                            <label>Titel *</label>
                            <input type="text" name="title" class="form-control" required>
                            <small style="color:#aaa;display:block;margin-top:6px;">Kort titel, syns i listor — beskrivande men inte för lång.</small>
                        </div>
                        <div class="form-group">
                            <label>Svårighetsgrad *</label>
                            <select name="difficulty" class="form-control" required>
                                <option value="easy">Easy</option>
                                <option value="medium">Medium</option>
                                <option value="hard">Hard</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Minimum Nivå</label>
                            <input type="number" name="min_level" class="form-control" value="1" min="1">
                            <small style="color:#aaa;display:block;margin-top:6px;">Valfritt — lämna tomt för standard (1).</small>
                        </div>
                        <div class="form-group">
                            <label>Storyline</label>
                            <select name="storyline_id" class="form-control">
                                <option value="">Ingen</option>
                                <?php foreach ($storylines as $sl): ?>
                                    <option value="<?= $sl['id'] ?>"><?= htmlspecialchars($sl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <small style="display: block; margin-top: 5px; color: #aaa;">EXP-procent används automatiskt för ordningsuppgifter (ingen inställning krävs).</small>
                        </div>
                        <button type="submit" class="btn btn-warning">Skapa Övning</button>
                    </form>
                </div>
            </div>
            <div class="col-8">
                <div class="card">
                    <h3>Alla Övningar</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Titel</th>
                                <th>Svårighet</th>
                                <th>Min Nivå</th>
                                <th>Storyline</th>
                                <th>Frågor</th>
                                <th>Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($exercises as $ex): ?>
                            <tr>
                                <td><?= $ex['id'] ?></td>
                                <td>
                                    <?= htmlspecialchars($ex['title']) ?>

                                </td>
                                <td><span class="badge badge-<?= $ex['difficulty'] ?>"><?= ucfirst($ex['difficulty']) ?></span></td>
                                <td><?= $ex['min_level'] ?></td>
                                <td><?= htmlspecialchars($ex['storyline_name'] ?? 'Ingen') ?></td>
                                <td><?= $ex['question_count'] ?></td>
                                <td>
                                    <button class="btn btn-info btn-sm" onclick='editExercise(<?= json_encode($ex) ?>)'>Redigera</button>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Är du säker?')">
                                        <input type="hidden" name="action" value="delete_exercise">
                                        <input type="hidden" name="active_tab" value="exercises">
                                        <input type="hidden" name="exercise_id" value="<?= $ex['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Radera</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- STORYLINES TAB -->
    <div id="storylines" class="tab-content <?= $activeTab === 'storylines' ? 'active' : '' ?>">
        <div class="row">
            <div class="col-4">
                <div class="card">
                    <h3>Lägg till Storyline</h3>
                    <form method="post">
                        <input type="hidden" name="action" value="add_storyline">
                        <input type="hidden" name="active_tab" value="storylines">
                        <div class="form-group">
                            <label>Namn *</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Beskrivning</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Ikon (emoji)</label>
                            <input type="text" name="icon" class="form-control" value="📖">
                        </div>
                        <div class="form-group">
                            <label>Visningsordning *</label>
                            <input type="number" name="display_order" class="form-control" value="1" min="1" required>
                        </div>
                        <button type="submit" class="btn btn-warning">Skapa Storyline</button>
                    </form>
                </div>
            </div>
            <div class="col-8">
                <div class="card">
                    <h3>Alla Storylines</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Namn</th>
                                <th>Beskrivning</th>
                                <th>Ikon</th>
                                <th>Ordning</th>
                                <th>Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($storylines as $sl): ?>
                            <tr>
                                <td><?= $sl['id'] ?></td>
                                <td><?= htmlspecialchars($sl['name']) ?></td>
                                <td><?= htmlspecialchars(substr($sl['description'] ?? '', 0, 50)) ?><?= strlen($sl['description'] ?? '') > 50 ? '...' : '' ?></td>
                                <td><?= htmlspecialchars($sl['icon'] ?? '📖') ?></td>
                                <td><?= $sl['display_order'] ?></td>
                                <td>
                                    <button class="btn btn-info btn-sm" onclick='editStoryline(<?= json_encode($sl) ?>)'>Redigera</button>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Är du säker?')">
                                        <input type="hidden" name="action" value="delete_storyline">
                                        <input type="hidden" name="active_tab" value="storylines">
                                        <input type="hidden" name="storyline_id" value="<?= $sl['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Radera</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- QUESTIONS TAB -->
    <div id="questions" class="tab-content <?= $activeTab === 'questions' ? 'active' : '' ?>">
        <div class="row">
            <div class="col-4">
                <div class="card">
                    <h3>Lägg till Fråga</h3>
                    <div class="small" style="color:#aaa;margin-bottom:10px;">Minimalt: välj övning, typ och skriv en kort frågetext. Poäng och detaljfält kan lämnas med standardvärden.</div>
                    <form method="post" id="addQuestionForm">
                        <input type="hidden" name="action" value="add_question">
                        <input type="hidden" name="active_tab" value="questions">
                        <div class="form-group">
                            <label>Övning *</label>
                            <select name="exercise_id" class="form-control" required>
                                <option value="">Välj övning...</option>
                                <?php foreach ($exercises as $ex): ?>
                                    <option value="<?= $ex['id'] ?>"><?= htmlspecialchars($ex['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Frågetyp *</label>
                            <select name="type" class="form-control" id="questionType" required onchange="toggleQuestionTypes()">
                                <option value="mcq">Flerval (MCQ)</option>
                                <option value="truefalse">Sant/Falskt</option>
                                <option value="ordering">Ordning</option>
                                <option value="fillblank">Fyll i luckan</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Frågetext *</label>
                            <textarea name="content" class="form-control" rows="3" required></textarea>
                        </div>
                        <div class="form-group">
                            <label>Poäng *</label>
                            <input type="number" name="points" class="form-control" value="10" min="1" required>
                        </div>
                        
                        <!-- MCQ Choices -->
                        <div id="mcqFields" class="question-type-fields active">
                            <label style="font-weight: bold; margin-bottom: 10px; display: block;">Svarsalternativ *</label>
                            <div class="small" style="margin-bottom:8px;color:#aaa;">Minst två alternativ krävs. Du kan markera EN ELLER FLERA som korrekta genom att bocka i rutorna till höger.</div>
                            <div id="choicesList">
                                <div class="input-group">
                                    <input type="text" name="choices[]" class="form-control mcq-field" placeholder="Alternativ 1">
                                    <div class="input-group-append">
                                        <label style="margin: 0;"><input type="checkbox" name="correct_choices[]" class="mcq-checkbox" value="0"> Rätt</label>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <input type="text" name="choices[]" class="form-control mcq-field" placeholder="Alternativ 2">
                                    <div class="input-group-append">
                                        <label style="margin: 0;"><input type="checkbox" name="correct_choices[]" class="mcq-checkbox" value="1"> Rätt</label>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addChoice()">+ Lägg till alternativ</button>
                            <div class="note">
                                <strong>Tips:</strong> Om du vill ha flera korrekta svar så bockar du i flera rutor. Lämna inte alla rutor tomma — markera minst ett korrekt svar.
                            </div>
                        </div>
                        
                        <!-- True/False Choices -->
                        <div id="trueFalseFields" class="question-type-fields">
                            <label style="font-weight: bold; margin-bottom: 10px; display: block;">Sant eller Falskt? *</label>
                            <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                                <label class="tf-choice">
                                    <input type="radio" name="tf_correct" class="tf-radio" value="sant" required>
                                    <span style="font-size: 16px;">✓ Sant är rätt</span>
                                </label>
                                <label class="tf-choice">
                                    <input type="radio" name="tf_correct" class="tf-radio" value="falskt" required>
                                    <span style="font-size: 16px;">✗ Falskt är rätt</span>
                                </label>
                            </div>
                            <div class="note">
                                <strong>OBS:</strong> Välj vilket alternativ (Sant eller Falskt) som är det rätta svaret.
                            </div>
                        </div>
                        
                        <!-- Fill-blank Choices -->
                        <div id="fillBlankFields" class="question-type-fields">
                            <label style="font-weight: bold; margin-bottom: 10px; display: block;">Möjliga svar (fyll i luckan) *</label>
                            <div class="small" style="margin-bottom:8px;color:#aaa;">Minst två alternativ krävs. Markera ett eller flera korrekta svar.</div>
                            <div id="fillBlankList">
                                <div class="input-group">
                                    <input type="text" name="fb_choices[]" class="form-control fb-field" placeholder="Alternativ 1 (rätt svar)">
                                    <div class="input-group-append">
                                        <label style="margin: 0;"><input type="checkbox" name="fb_corrects[]" class="fb-checkbox" value="0"> Rätt</label>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <input type="text" name="fb_choices[]" class="form-control fb-field" placeholder="Alternativ 2">
                                    <div class="input-group-append">
                                        <label style="margin: 0;"><input type="checkbox" name="fb_corrects[]" class="fb-checkbox" value="1"> Rätt</label>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addFillBlankChoice()">+ Lägg till alternativ</button>
                            <div class="note">
                                <strong>Tips:</strong> Ge korta svarsalternativ och markera alla giltiga varianter.
                            </div>
                        </div>
                        
                        <!-- Ordering Items -->
                        <div id="orderingFields" class="question-type-fields">
                            <label style="font-weight: bold; margin-bottom: 10px; display: block;">Ordningsalternativ (i rätt ordning) *</label>
                            <div class="small" style="margin-bottom:8px;color:#aaa;">Skriv alternativen i rätt ordning. Minst 2 steg krävs.</div>
                            <div id="orderingList">
                                <div class="input-group">
                                    <span style="min-width: 30px;">1.</span>
                                    <input type="text" name="ordering_items[]" class="form-control ordering-field" placeholder="Första steget">
                                </div>
                                <div class="input-group">
                                    <span style="min-width: 30px;">2.</span>
                                    <input type="text" name="ordering_items[]" class="form-control ordering-field" placeholder="Andra steget">
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addOrderingItem()">+ Lägg till steg</button>
                            <div class="note">
                                <strong>Tips:</strong> Du kan lägga till så många steg som behövs. Användaren ser dem slumpmässigt när de svarar.
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-warning">Skapa Fråga</button>
                    </form>
                </div>
            </div>
            <div class="col-8">
                <div class="card">
                    <h3>Alla Frågor</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Övning</th>
                                <th>Typ</th>
                                <th>Text</th>
                                <th>Poäng</th>
                                <th>Items</th>
                                <th>Åtgärder</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($questions as $q): ?>
                            <tr>
                                <td><?= $q['id'] ?></td>
                                <td><?= htmlspecialchars($q['exercise_title']) ?></td>
                                <td><span class="badge badge-info"><?= htmlspecialchars($q['type']) ?></span></td>
                                <td><?= htmlspecialchars(substr($q['content'], 0, 40)) ?><?= strlen($q['content']) > 40 ? '...' : '' ?></td>
                                <td><?= $q['points'] ?? 10 ?></td>
                                <td><?= $q['choice_count'] + $q['ordering_count'] ?></td>
                                <td>
                                    <button class="btn btn-info btn-sm" onclick='editQuestion(<?= json_encode($q) ?>)'>Redigera</button>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Är du säker?')">
                                        <input type="hidden" name="action" value="delete_question">
                                        <input type="hidden" name="active_tab" value="questions">
                                        <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Radera</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- PASSAGES TAB -->
    <div id="passages" class="tab-content <?= $activeTab === 'passages' ? 'active' : '' ?>">
        <div class="card">
            <h3>Hantera Textpassager</h3>
            <form method="post">
                <input type="hidden" name="action" value="add_passage">
                <input type="hidden" name="active_tab" value="passages">
                <div class="row">
                    <div class="col-4">
                        <div class="form-group">
                            <label>Övning *</label>
                            <select name="exercise_id" class="form-control" required>
                                <option value="">Välj övning...</option>
                                <?php foreach ($exercises as $ex): ?>
                                    <option value="<?= $ex['id'] ?>"><?= htmlspecialchars($ex['title']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="col-8">
                        <div class="form-group">
                            <label>Textinnehåll *</label>
                            <textarea name="content" class="form-control" rows="15" placeholder="Skriv eller klistra in textpassagen här..." required></textarea>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-warning">Spara Passage</button>
            </form>
        </div>
    </div>

    <!-- TOOLS TAB -->
    <div id="tools" class="tab-content <?= $activeTab === 'tools' ? 'active' : '' ?>">
        <div class="card">
            <h3>Verktyg — Kör SQL</h3>
            <p class="small" style="color:#aaa;">Klistra in SQL som din AI genererat. Kör endast om du förstår vad frågorna gör. En bekräftelse krävs.</p>
            <form method="post">
                <input type="hidden" name="action" value="run_sql">
                <input type="hidden" name="active_tab" value="tools">
                <div class="form-group">
                    <label>SQL</label>
                    <textarea name="sql" class="form-control" rows="8" placeholder="Skriv eller klistra in SQL här..."></textarea>
                </div>
                <div class="form-group">
                    <label class="checkbox-label"><input type="checkbox" name="confirm_execute" value="1"> Jag förstår att detta körs direkt mot databasen</label>
                </div>
                <button type="submit" class="btn btn-danger">Kör SQL</button>
            </form>
        </div>
    </div>
</div>

<!-- Edit Exercise Modal -->
<div id="editExerciseModal" class="modal">
    <div class="modal-content">
        <form method="post">
            <input type="hidden" name="action" value="edit_exercise">
            <input type="hidden" name="active_tab" value="exercises">
            <input type="hidden" name="exercise_id" id="edit_ex_id">
            <div class="modal-header">
                <h3>Redigera Övning</h3>
                <span class="close" onclick="closeModal('editExerciseModal')">&times;</span>
            </div>
            <div class="form-group">
                <label>Titel *</label>
                <input type="text" name="title" id="edit_ex_title" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Svårighetsgrad *</label>
                <select name="difficulty" id="edit_ex_difficulty" class="form-control" required>
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </div>
            <div class="form-group">
                <label>Minimum Nivå *</label>
                <input type="number" name="min_level" id="edit_ex_min_level" class="form-control" min="1">
            <small style="color:#aaa;display:block;margin-top:6px;">Valfritt — lämna tomt för standard (1).</small>
            </div>
            <div class="form-group">
                <label>Storyline</label>
                <select name="storyline_id" id="edit_ex_storyline" class="form-control">
                    <option value="">Ingen</option>
                    <?php foreach ($storylines as $sl): ?>
                        <option value="<?= $sl['id'] ?>"><?= htmlspecialchars($sl['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <small style="color:#aaa;display:block;margin-top:6px;">EXP-procent är aktiverat globalt och behöver inte ändras per övning.</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editExerciseModal')">Avbryt</button>
                <button type="submit" class="btn btn-warning">Spara ändringar</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Storyline Modal -->
<div id="editStorylineModal" class="modal">
    <div class="modal-content">
        <form method="post">
            <input type="hidden" name="action" value="edit_storyline">
            <input type="hidden" name="active_tab" value="storylines">
            <input type="hidden" name="storyline_id" id="edit_sl_id">
            <div class="modal-header">
                <h3>Redigera Storyline</h3>
                <span class="close" onclick="closeModal('editStorylineModal')">&times;</span>
            </div>
            <div class="form-group">
                <label>Namn *</label>
                <input type="text" name="name" id="edit_sl_name" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Beskrivning</label>
                <textarea name="description" id="edit_sl_description" class="form-control" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label>Ikon</label>
                <input type="text" name="icon" id="edit_sl_icon" class="form-control">
            </div>
            <div class="form-group">
                <label>Visningsordning *</label>
                <input type="number" name="display_order" id="edit_sl_order" class="form-control" min="1" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editStorylineModal')">Avbryt</button>
                <button type="submit" class="btn btn-warning">Spara ändringar</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Question Modal -->
<div id="editQuestionModal" class="modal">
    <div class="modal-content">
        <form method="post">
            <input type="hidden" name="action" value="edit_question">
            <input type="hidden" name="active_tab" value="questions">
            <input type="hidden" name="question_id" id="edit_q_id">
            <div class="modal-header">
                <h3>Redigera Fråga</h3>
                <span class="close" onclick="closeModal('editQuestionModal')">&times;</span>
            </div>
            <div class="form-group">
                <label>Typ *</label>
                <select name="type" id="edit_q_type" class="form-control" required>
                    <option value="mcq">Flerval (MCQ)</option>
                    <option value="truefalse">Sant/Falskt</option>
                    <option value="ordering">Ordning</option>
                    <option value="fillblank">Fyll i luckan</option>
                </select>
            </div>
            <div class="form-group">
                <label>Frågetext *</label>
                <textarea name="content" id="edit_q_content" class="form-control" rows="3" required></textarea>
            </div>
            <div class="form-group">
                <label>Poäng *</label>
                <input type="number" name="points" id="edit_q_points" class="form-control" min="1" required>
            </div>
            <p style="font-size: 13px; color: #aaa;">OBS: För att redigera svarsalternativ/ordning, radera frågan och skapa en ny — eller använd fliken <strong>Verktyg</strong> och kör SQL för att uppdatera detaljer (för avancerade ändringar).</p>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('editQuestionModal')">Avbryt</button>
                <button type="submit" class="btn btn-warning">Spara ändringar</button>
            </div>
        </form>
    </div>
</div>

<script>
let choiceCount = 2;
let fillBlankCount = 2;
let orderingCount = 3;

function showTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(function(tab) {
        tab.classList.remove('active');
    });
    document.querySelectorAll('.tab').forEach(function(tab) {
        tab.classList.remove('active');
    });
    
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
}

function toggleQuestionTypes() {
    const type = document.getElementById('questionType').value;
    
    // Hide all type fields
    document.querySelectorAll('.question-type-fields').forEach(el => {
        el.classList.remove('active');
    });
    
    // Remove all required attributes from hidden fields
    document.querySelectorAll('.mcq-field, .mcq-checkbox, .tf-radio, .fb-field, .fb-checkbox, .ordering-field').forEach(el => {
        el.removeAttribute('required');
    });
    
    // Show relevant fields and add required attributes
    if (type === 'mcq') {
        document.getElementById('mcqFields').classList.add('active');
        document.querySelectorAll('.mcq-field').forEach(el => el.setAttribute('required', 'required'));
        // checkboxes don't support required, we'll validate on submit
    } else if (type === 'truefalse') {
        document.getElementById('trueFalseFields').classList.add('active');
        document.querySelectorAll('.tf-radio')[0].setAttribute('required', 'required');
    } else if (type === 'fillblank') {
        document.getElementById('fillBlankFields').classList.add('active');
        document.querySelectorAll('.fb-field').forEach(el => el.setAttribute('required', 'required'));
        // checkboxes validated on submit
    } else if (type === 'ordering') {
        document.getElementById('orderingFields').classList.add('active');
        document.querySelectorAll('.ordering-field').forEach(el => el.setAttribute('required', 'required'));
    }
}

function addChoice() {
    const list = document.getElementById('choicesList');
    const div = document.createElement('div');
    div.className = 'input-group';
    div.innerHTML = `
        <input type="text" name="choices[]" class="form-control mcq-field" placeholder="Alternativ ${choiceCount + 1}" required>
        <div class="input-group-append">
            <label style="margin: 0;"><input type="checkbox" name="correct_choices[]" class="mcq-checkbox" value="${choiceCount}"> Rätt</label>
        </div>
    `;
    list.appendChild(div);
    choiceCount++;
}

function addFillBlankChoice() {
    const list = document.getElementById('fillBlankList');
    const div = document.createElement('div');
    div.className = 'input-group';
    div.innerHTML = `
        <input type="text" name="fb_choices[]" class="form-control fb-field" placeholder="Alternativ ${fillBlankCount + 1}" required>
        <div class="input-group-append">
            <label style="margin: 0;"><input type="checkbox" name="fb_corrects[]" class="fb-checkbox" value="${fillBlankCount}"> Rätt</label>
        </div>
    `;
    list.appendChild(div);
    fillBlankCount++;
}

function addOrderingItem() {
    const list = document.getElementById('orderingList');
    const div = document.createElement('div');
    div.className = 'input-group';
    div.innerHTML = `
        <span style="min-width: 30px;">${orderingCount + 1}.</span>
        <input type="text" name="ordering_items[]" class="form-control ordering-field" placeholder="Steg ${orderingCount + 1}" required>
    `;
    list.appendChild(div);
    orderingCount++;
}

function editExercise(data) {
    document.getElementById('edit_ex_id').value = data.id;
    document.getElementById('edit_ex_title').value = data.title;
    document.getElementById('edit_ex_difficulty').value = data.difficulty;
    document.getElementById('edit_ex_min_level').value = data.min_level;
    document.getElementById('edit_ex_storyline').value = data.storyline_id || '';
    
    // Handle metadata
    // Metadata may contain historical flags, but EXP-percentage is handled globally now.
    
    document.getElementById('editExerciseModal').classList.add('show');
}

function editStoryline(data) {
    document.getElementById('edit_sl_id').value = data.id;
    document.getElementById('edit_sl_name').value = data.name;
    document.getElementById('edit_sl_description').value = data.description || '';
    document.getElementById('edit_sl_icon').value = data.icon || '';
    document.getElementById('edit_sl_order').value = data.display_order;
    document.getElementById('editStorylineModal').classList.add('show');
}

function editQuestion(data) {
    document.getElementById('edit_q_id').value = data.id;
    document.getElementById('edit_q_type').value = data.type;
    document.getElementById('edit_q_content').value = data.content;
    document.getElementById('edit_q_points').value = data.points || 10;
    document.getElementById('editQuestionModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}

// Add visual feedback for radio selection
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.tf-choice').forEach(function(choice) {
        choice.addEventListener('click', function() {
            document.querySelectorAll('.tf-choice').forEach(c => c.classList.remove('selected'));
            if (this.querySelector('input[type="radio"]').checked) {
                this.classList.add('selected');
            }
        });
    });
});

toggleQuestionTypes();

// Validate add question form to enforce minimal sensible rules
document.getElementById('addQuestionForm').addEventListener('submit', function(e) {
    const type = document.getElementById('questionType').value;
    if (type === 'mcq') {
        const choices = Array.from(document.querySelectorAll('.mcq-field')).map(i => i.value.trim()).filter(Boolean);
        const correct = Array.from(document.querySelectorAll('input[name="correct_choices[]"]:checked'));
        if (choices.length < 2) { e.preventDefault(); alert('MCQ måste ha minst 2 alternativ.'); return false; }
        if (correct.length < 1) { e.preventDefault(); alert('Markera minst ett korrekt alternativ.'); return false; }
    } else if (type === 'fillblank') {
        const choices = Array.from(document.querySelectorAll('.fb-field')).map(i => i.value.trim()).filter(Boolean);
        const correct = Array.from(document.querySelectorAll('input[name="fb_corrects[]"]:checked'));
        if (choices.length < 2) { e.preventDefault(); alert('Fyll-i måste ha minst 2 alternativ.'); return false; }
        if (correct.length < 1) { e.preventDefault(); alert('Markera minst ett korrekt alternativ.'); return false; }
    } else if (type === 'ordering') {
        const items = Array.from(document.querySelectorAll('.ordering-field')).map(i => i.value.trim()).filter(Boolean);
        if (items.length < 2) { e.preventDefault(); alert('Ordningsfråga måste ha minst 2 steg.'); return false; }
    }
});
</script>
</body>
</html>