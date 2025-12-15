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
                    
                    // Build metadata
                    $metadata = [];
                    if (isset($_POST['use_exp_percentage']) && $_POST['use_exp_percentage'] == '1') {
                        $metadata['use_exp_percentage'] = true;
                    }
                    $metadataJson = !empty($metadata) ? json_encode($metadata) : null;
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO exercises (title, difficulty, min_level, storyline_id, metadata, created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        trim($_POST['title']),
                        $_POST['difficulty'],
                        $_POST['min_level'],
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
                    
                    // Build metadata
                    $metadata = [];
                    if (isset($_POST['use_exp_percentage']) && $_POST['use_exp_percentage'] == '1') {
                        $metadata['use_exp_percentage'] = true;
                    }
                    $metadataJson = !empty($metadata) ? json_encode($metadata) : null;
                    
                    $stmt = $pdo->prepare("
                        UPDATE exercises 
                        SET title = ?, difficulty = ?, min_level = ?, storyline_id = ?, metadata = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([
                        trim($_POST['title']),
                        $_POST['difficulty'],
                        $_POST['min_level'],
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
                        // MCQ - use user-provided choices
                        if (empty($_POST['choices']) || !isset($_POST['correct_choice'])) {
                            $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$questionId]);
                            throw new Exception("MCQ måste ha minst 2 alternativ och ett rätt svar!");
                        }
                        
                        $choiceStmt = $pdo->prepare("
                            INSERT INTO question_choices (question_id, content, is_correct, pos) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $pos = 0;
                        foreach ($_POST['choices'] as $idx => $choice) {
                            if (!empty(trim($choice))) {
                                $isCorrect = (isset($_POST['correct_choice']) && $_POST['correct_choice'] == $idx) ? 1 : 0;
                                $choiceStmt->execute([$questionId, trim($choice), $isCorrect, $pos]);
                                $pos++;
                            }
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
                        // Fill-blank - use user-provided choices
                        if (empty($_POST['fb_choices']) || !isset($_POST['fb_correct'])) {
                            $pdo->prepare("DELETE FROM questions WHERE id = ?")->execute([$questionId]);
                            throw new Exception("Fyll-i måste ha minst 2 alternativ och ett rätt svar!");
                        }
                        
                        $choiceStmt = $pdo->prepare("
                            INSERT INTO question_choices (question_id, content, is_correct, pos) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $pos = 0;
                        foreach ($_POST['fb_choices'] as $idx => $choice) {
                            if (!empty(trim($choice))) {
                                $isCorrect = (isset($_POST['fb_correct']) && $_POST['fb_correct'] == $idx) ? 1 : 0;
                                $choiceStmt->execute([$questionId, trim($choice), $isCorrect, $pos]);
                                $pos++;
                            }
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
                            <label>Minimum Nivå *</label>
                            <input type="number" name="min_level" class="form-control" value="1" min="1" required>
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
                            <label class="checkbox-label">
                                <input type="checkbox" name="use_exp_percentage" value="1">
                                <span>Använd EXP-procent för sorteringsuppgifter</span>
                            </label>
                            <small style="display: block; margin-top: 5px; color: #aaa;">
                                För övningar med ordningsfrågor: använd partiell poäng istället för allt-eller-inget
                            </small>
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
                                    <?php 
                                    $meta = json_decode($ex['metadata'] ?? '{}', true);
                                    if (isset($meta['use_exp_percentage']) && $meta['use_exp_percentage']): 
                                    ?>
                                        <span style="color: #ffc107; font-size: 11px;">📊 EXP%</span>
                                    <?php endif; ?>
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
                            <div id="choicesList">
                                <div class="input-group">
                                    <input type="text" name="choices[]" class="form-control mcq-field" placeholder="Alternativ 1">
                                    <div class="input-group-append">
                                        <label style="margin: 0;"><input type="radio" name="correct_choice" class="mcq-radio" value="0"> Rätt</label>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <input type="text" name="choices[]" class="form-control mcq-field" placeholder="Alternativ 2">
                                    <div class="input-group-append">
                                        <label style="margin: 0;"><input type="radio" name="correct_choice" class="mcq-radio" value="1"> Rätt</label>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addChoice()">+ Lägg till alternativ</button>
                            <div class="note">
                                <strong>OBS:</strong> Välj vilket alternativ som är rätt genom att klicka på "Rätt"-knappen.
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
                            <label style="font-weight: bold; margin-bottom: 10px; display: block;">Möjliga svar ("____") fyra för blank *</label>
                            <div id="fillBlankList">
                                <div class="input-group">
                                    <input type="text" name="fb_choices[]" class="form-control fb-field" placeholder="Alternativ 1 (rätt svar)">
                                    <div class="input-group-append">
                                        <label style="margin: 0;"><input type="radio" name="fb_correct" class="fb-radio" value="0"> Rätt</label>
                                    </div>
                                </div>
                                <div class="input-group">
                                    <input type="text" name="fb_choices[]" class="form-control fb-field" placeholder="Alternativ 2">
                                    <div class="input-group-append">
                                        <label style="margin: 0;"><input type="radio" name="fb_correct" class="fb-radio" value="1"> Rätt</label>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addFillBlankChoice()">+ Lägg till alternativ</button>
                            <div class="note">
                                <strong>OBS:</strong> Lägg till alla möjliga svar som användaren kan välja mellan. Markera det rätta svaret.
                            </div>
                        </div>
                        
                        <!-- Ordering Items -->
                        <div id="orderingFields" class="question-type-fields">
                            <label style="font-weight: bold; margin-bottom: 10px; display: block;">Ordningsalternativ (i rätt ordning) *</label>
                            <div id="orderingList">
                                <div class="input-group">
                                    <span style="min-width: 30px;">1.</span>
                                    <input type="text" name="ordering_items[]" class="form-control ordering-field" placeholder="Första steget">
                                </div>
                                <div class="input-group">
                                    <span style="min-width: 30px;">2.</span>
                                    <input type="text" name="ordering_items[]" class="form-control ordering-field" placeholder="Andra steget">
                                </div>
                                <div class="input-group">
                                    <span style="min-width: 30px;">3.</span>
                                    <input type="text" name="ordering_items[]" class="form-control ordering-field" placeholder="Tredje steget">
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm" onclick="addOrderingItem()">+ Lägg till steg</button>
                            <div class="note">
                                <strong>OBS:</strong> Skriv alternativen i den ordning de ska vara. Användaren får dem i slumpmässig ordning.
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
                <input type="number" name="min_level" id="edit_ex_min_level" class="form-control" min="1" required>
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
                <label class="checkbox-label">
                    <input type="checkbox" name="use_exp_percentage" id="edit_ex_exp" value="1">
                    <span>Använd EXP-procent för sorteringsuppgifter</span>
                </label>
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
            <p style="font-size: 13px; color: #aaa;">OBS: För att redigera svarsalternativ/ordning, radera frågan och skapa en ny.</p>
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
    document.querySelectorAll('.mcq-field, .mcq-radio, .tf-radio, .fb-field, .fb-radio, .ordering-field').forEach(el => {
        el.removeAttribute('required');
    });
    
    // Show relevant fields and add required attributes
    if (type === 'mcq') {
        document.getElementById('mcqFields').classList.add('active');
        document.querySelectorAll('.mcq-field').forEach(el => el.setAttribute('required', 'required'));
        document.querySelectorAll('.mcq-radio')[0].setAttribute('required', 'required');
    } else if (type === 'truefalse') {
        document.getElementById('trueFalseFields').classList.add('active');
        document.querySelectorAll('.tf-radio')[0].setAttribute('required', 'required');
    } else if (type === 'fillblank') {
        document.getElementById('fillBlankFields').classList.add('active');
        document.querySelectorAll('.fb-field').forEach(el => el.setAttribute('required', 'required'));
        document.querySelectorAll('.fb-radio')[0].setAttribute('required', 'required');
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
            <label style="margin: 0;"><input type="radio" name="correct_choice" class="mcq-radio" value="${choiceCount}"> Rätt</label>
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
            <label style="margin: 0;"><input type="radio" name="fb_correct" class="fb-radio" value="${fillBlankCount}"> Rätt</label>
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
    try {
        const metadata = JSON.parse(data.metadata || '{}');
        document.getElementById('edit_ex_exp').checked = metadata.use_exp_percentage || false;
    } catch(e) {
        document.getElementById('edit_ex_exp').checked = false;
    }
    
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
</script>
</body>
</html>