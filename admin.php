<?php
session_start();

// Super users: only these (and a staff member whose Position is HOD, set at login) can open admin.php
$superUsers = ['admin@as.rjt.ac.lk', 'hod@as.rjt.ac.lk'];

// Logout: destroy the session and go to index.php
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// Not logged in -> go to index.php
if (!isset($_SESSION['email'])) {
    header('Location: index.php');
    exit;
}

// Logged in but not a super user -> go to their own page
$isSuper = in_array(strtolower($_SESSION['email']), $superUsers, true)
        || ($_SESSION['role'] ?? '') === 'admin';
if (!$isSuper) {
    header('Location: ' . (($_SESSION['role'] ?? '') === 'student' ? 'student.php' : 'dashboard.php'));
    exit;
}

$username = $_SESSION['email'];

// Security token for the form
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ---- Database settings ----
$dbHost = 'localhost'; $dbName = '2026_secondsem'; $dbUser = 'root'; $dbPass = '';

$message  = '';
$type     = '';
$warn     = '';
$savedPct = null;     // attendance percentage saved in the calculation table
$savedDoc = null;     // maximum number of excuse / medical documents saved in the calculation table
$pctInput = '';       // values shown in the text boxes
$docInput = '';
$tables   = ['Theory' => [], 'Practical' => []];

if (!empty($_SESSION['flash_admin'])) {
    $message = $_SESSION['flash_admin'];
    $type    = 'success';
    unset($_SESSION['flash_admin']);
}

function findCol($cols, $pattern, $default = null) {
    foreach ($cols as $c) {
        if (preg_match($pattern, $c)) return $c;
    }
    return $default;
}

function fmtNum($v) {
    $v = (string)$v;
    if (strpos($v, '.') !== false) $v = rtrim(rtrim($v, '0'), '.');
    return $v;
}

/* A course code that ends with the letter P (ICT 1305P) is a practical course */
function isPracticalCode($code) {
    return (bool)preg_match('/\d{4}\s*P$/i', trim($code));
}

/*
 * Document ID, built the same way as in document.php:
 * course number + last 3 digits of the registration number + week number + M (medical) / E (excuse letter)
 */
function documentId($course, $reg, $weekNo, $letter) {
    if (!preg_match('/\d+/', $course, $cm) || !preg_match('/(\d+)\D*$/', $reg, $rm)) return '';
    $last3 = substr(str_pad($rm[1], 3, '0', STR_PAD_LEFT), -3);
    return $cm[0] . $last3 . (int)$weekNo . $letter;
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    /* ---------- calculation table: attendancePercentage, number_of_document ---------- */
    $calcCols = $pdo->query('SHOW COLUMNS FROM calculation')->fetchAll(PDO::FETCH_COLUMN);
    $pctCol = findCol($calcCols, '/percent/i');
    $docCol = findCol($calcCols, '/doc/i');
    if ($pctCol === null || $docCol === null) {
        throw new PDOException('The calculation table needs the columns attendancePercentage and number_of_document. Columns found: ' . implode(', ', $calcCols));
    }

    // values saved earlier
    $row = $pdo->query("SELECT `$pctCol` AS p, `$docCol` AS d FROM calculation LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['p'] !== null && $row['d'] !== null) {
        $savedPct = (float)$row['p'];
        $savedDoc = (int)$row['d'];
        $pctInput = fmtNum($row['p']);
        $docInput = fmtNum($row['d']);
    }

    /* ---------- SAVE the two values ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pctIn = trim($_POST['attendance_percentage'] ?? '');
        $docIn = trim($_POST['max_documents'] ?? '');
        $pctInput = $pctIn;
        $docInput = $docIn;

        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $message = 'Security check failed. Please reload the page and try again.';
            $type    = 'error';
        } elseif ($pctIn === '' || $docIn === '') {
            $message = 'Please fill in both boxes.';
            $type    = 'error';
        } elseif (!is_numeric($pctIn) || (float)$pctIn < 0 || (float)$pctIn > 100) {
            $message = 'Attendance percentage must be a number from 0 to 100.';
            $type    = 'error';
        } elseif (!ctype_digit($docIn)) {
            $message = 'Maximum number of documents must be a whole number (0 or more).';
            $type    = 'error';
        } else {
            $cnt = (int)$pdo->query('SELECT COUNT(*) FROM calculation')->fetchColumn();
            if ($cnt > 0) {
                $pdo->prepare("UPDATE calculation SET `$pctCol` = ?, `$docCol` = ?")->execute([$pctIn, $docIn]);
            } else {
                $pdo->prepare("INSERT INTO calculation (`$pctCol`, `$docCol`) VALUES (?, ?)")->execute([$pctIn, $docIn]);
            }
            $_SESSION['flash_admin'] = 'Saved: attendance percentage ' . fmtNum($pctIn) . '%, maximum excuse / medical documents ' . (int)$docIn . '.';
            header('Location: admin.php');
            exit;
        }
    }

    /* ---------- Eligible / ineligible students of every course ---------- */
    if ($savedPct !== null) {
        $required = $savedPct;
        $maxDocs  = $savedDoc;

        // courses
        $courseCols = $pdo->query('SHOW COLUMNS FROM course')->fetchAll(PDO::FETCH_COLUMN);
        $cCode = findCol($courseCols, '/code/i', $courseCols[0]);
        $cName = findCol($courseCols, '/name/i');
        $courses = $pdo->query("SELECT `$cCode` AS code" . ($cName ? ", `$cName` AS cname" : ", '' AS cname") . " FROM course ORDER BY `$cCode`")
                       ->fetchAll(PDO::FETCH_ASSOC);

        // enrolled students (attends table)
        $enrolled = [];
        foreach ($pdo->query('SELECT CourseCode, Registration_Number FROM attends')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $enrolled[trim($r['CourseCode'])][trim($r['Registration_Number'])] = true;
        }

        // saved attendance:  $att[course][reg][week] = 1 / 0,   $weeksOf[course][week]
        $att = []; $weeksOf = [];
        try {
            $attCols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
            $aReg = findCol($attCols, '/reg/i');  $aCourse = findCol($attCols, '/course/i');
            $aWeek = findCol($attCols, '/week/i'); $aStatus = findCol($attCols, '/status/i');
            if ($aReg && $aCourse && $aWeek && $aStatus) {
                $q = $pdo->query("SELECT `$aReg` AS reg, `$aCourse` AS course, `$aWeek` AS wk, `$aStatus` AS st FROM attendance");
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $c = trim($r['course']); $w = (int)$r['wk'];
                    $att[$c][trim($r['reg'])][$w] = ((int)$r['st'] === 1) ? 1 : 0;
                    $weeksOf[$c][$w] = true;
                }
            }
        } catch (PDOException $e) {
            $warn = 'The attendance table could not be read: ' . $e->getMessage();
        }

        // uploaded medical / excuse letter documents
        $docs = [];
        try {
            foreach ($pdo->query('SELECT Document_Id, Registration_Number FROM document')->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $docs[$d['Document_Id']] = trim($d['Registration_Number']);
            }
        } catch (PDOException $e) {
            $warn .= ($warn ? ' ' : '') . 'The document table could not be read, so medical / excuse documents are not counted: ' . $e->getMessage();
        }

        foreach ($courses as $c) {
            $code  = trim($c['code']);
            $regs  = array_keys($enrolled[$code] ?? []);
            $weeks = array_keys($weeksOf[$code] ?? []);
            $total = count($regs);
            $nWeeks = count($weeks);

            $eligible = null; $ineligible = null;
            if ($nWeeks > 0) {
                $eligible = 0; $ineligible = 0;
                foreach ($regs as $reg) {
                    $present = 0; $docAbsent = 0;
                    foreach ($weeks as $w) {
                        $s = $att[$code][$reg][$w] ?? 0;          // no saved row for a marked week = absent
                        if ($s === 1) { $present++; continue; }
                        foreach (['M', 'E'] as $letter) {          // absent but medical / excuse letter uploaded
                            $id = documentId($code, $reg, $w, $letter);
                            if ($id !== '' && isset($docs[$id]) && strcasecmp($docs[$id], $reg) === 0) { $docAbsent++; break; }
                        }
                    }
                    // documents count as attended only up to the maximum number
                    $percent = (($present + min($docAbsent, $maxDocs)) / $nWeeks) * 100;
                    if (round($percent, 2) >= $required) $eligible++; else $ineligible++;
                }
            }

            $tables[isPracticalCode($code) ? 'Practical' : 'Theory'][] = [
                'code' => $code, 'name' => $c['cname'], 'total' => $total,
                'eligible' => $eligible, 'ineligible' => $ineligible, 'weeks' => $nWeeks,
            ];
        }
    }
} catch (PDOException $e) {
    $message = 'Database error: ' . $e->getMessage();
    $type    = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            height: 100vh;
            display: flex;
            background: #ffffff;
        }

        /* ---------- LEFT SECTION (20%) ---------- */
        .left {
            width: 20%;
            height: 100vh;
            background: #1e5bb8;
            display: flex;
            flex-direction: column;
            padding: 25px 15px;
        }

        .username {
            color: #ffffff;
            font-size: 15px;
            font-weight: bold;
            text-align: center;
            padding-bottom: 20px;
            margin-bottom: 25px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.4);
            word-break: break-all;
        }

        .menu {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 14px;
            background: #1e5bb8;
            color: #ffffff;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn:hover, .btn.active {
            background: #164a96;
        }

        .logout {
            margin-top: auto;
        }

        /* ---------- RIGHT SECTION (80%) ---------- */
        .right {
            width: 80%;
            height: 100vh;
            background: #ffffff;
            overflow-y: auto;
            padding: 30px 40px;
        }

        .right h1 {
            color: #1e5bb8;
            font-size: 26px;
            text-align: center;
            margin-bottom: 26px;
        }

        .panel {
            border: 1px solid #d5d9e0;
            border-radius: 10px;
            padding: 22px 26px;
            margin-bottom: 26px;
        }

        .panel h2 { color: #1e5bb8; font-size: 18px; margin-bottom: 14px; }

        .fields { display: flex; flex-wrap: wrap; gap: 22px; align-items: flex-end; }
        .fields label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; }
        .fields input[type="number"] {
            padding: 10px 12px; border: 1px solid #b8c1d1; border-radius: 6px;
            font-size: 15px; width: 260px;
        }
        .save-btn { background: #1e5bb8; color: #fff; border: none; padding: 11px 34px; border-radius: 6px; font-size: 16px; cursor: pointer; }
        .save-btn:hover { background: #164a96; }

        .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
        .msg.success { background: #e6f4e6; color: #2a7a2a; }
        .msg.error   { background: #fdeaea; color: #b02a2a; }
        .msg.warn    { background: #fff6e0; color: #8a5a00; }
        .note { color: #666; font-size: 13px; margin-top: 8px; }

        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { background: #1e5bb8; color: #fff; text-align: left; padding: 10px 14px; font-size: 14px; }
        td { padding: 9px 14px; border-bottom: 1px solid #e3e6ec; font-size: 15px; }
        tr:hover td { background: #f5f8fd; }
        td.num, th.num { text-align: center; width: 140px; }
        .ok  { color: #2a7a2a; font-weight: bold; }
        .bad { color: #b02a2a; font-weight: bold; }
        tr.total td { background: #eef2fa; font-weight: bold; }

        @media (max-width: 700px) {
            body { flex-direction: column; height: auto; }
            .left, .right { width: 100%; height: auto; }
            .right { padding: 20px; }
            .logout { margin-top: 25px; }
        }
    </style>
</head>
<body>

    <!-- LEFT SIDE -->
    <div class="left">
        <div class="username"><?= htmlspecialchars($username) ?></div>

        <div class="menu">
            <a href="uploadfile.php" class="btn">Upload File</a>
            <a href="markattendance.php" class="btn">Mark Attendance</a>
            <a href="viewattendance.php" class="btn">View Attendance</a>
            <a href="document.php" class="btn">Add Document</a>
        </div>

        <div class="logout">
            <a href="admin.php?logout=1" class="btn">Log Out</a>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="right">
        <h1>WELCOME TO RUSL SMART ATTENDANCE</h1>

        <?php if ($message !== ''): ?>
            <div class="msg <?= $type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($warn !== ''): ?>
            <div class="msg warn"><?= htmlspecialchars($warn) ?></div>
        <?php endif; ?>

        <!-- Eligibility rules -->
        <div class="panel">
            <h2>Attendance Eligibility Settings</h2>
            <form method="post" action="admin.php">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <div class="fields">
                    <div>
                        <label for="attendance_percentage">Course Attendance Percentage (%)</label>
                        <input type="number" id="attendance_percentage" name="attendance_percentage"
                               min="0" max="100" step="0.01" placeholder="e.g. 80"
                               value="<?= htmlspecialchars($pctInput) ?>" required>
                    </div>
                    <div>
                        <label for="max_documents">Maximum Number of Excuse or Medical Documents</label>
                        <input type="number" id="max_documents" name="max_documents"
                               min="0" step="1" placeholder="e.g. 3"
                               value="<?= htmlspecialchars($docInput) ?>" required>
                    </div>
                    <div>
                        <button type="submit" class="save-btn">Save</button>
                    </div>
                </div>
            </form>
            <p class="note">A student is eligible when present weeks plus excused weeks (medical / excuse letter, up to the maximum above) reach this percentage of the weeks marked.</p>
        </div>

        <!-- Enrolled / eligible / ineligible tables -->
        <?php if ($savedPct === null): ?>
            <p class="note">Save the attendance percentage and the maximum number of documents to see the eligible and ineligible students of each course.</p>
        <?php else: ?>
            <?php foreach (['Theory', 'Practical'] as $kind): ?>
                <div class="panel">
                    <h2><?= $kind ?> Courses</h2>
                    <?php if (!$tables[$kind]): ?>
                        <p class="note">No <?= strtolower($kind) ?> courses found.</p>
                    <?php else:
                        $sumTotal = 0; $sumElig = 0; $sumInel = 0;
                    ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th class="num">Total Enrolled</th>
                                    <th class="num">Eligible</th>
                                    <th class="num">Ineligible</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables[$kind] as $t):
                                    $sumTotal += $t['total'];
                                    $sumElig  += (int)$t['eligible'];
                                    $sumInel  += (int)$t['ineligible'];
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars($t['code'] . ($t['name'] !== '' && $t['name'] !== null ? ' - ' . $t['name'] : '')) ?></td>
                                    <td class="num"><?= (int)$t['total'] ?></td>
                                    <td class="num ok"><?= $t['eligible'] === null ? '-' : (int)$t['eligible'] ?></td>
                                    <td class="num bad"><?= $t['ineligible'] === null ? '-' : (int)$t['ineligible'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="total">
                                    <td>Total</td>
                                    <td class="num"><?= $sumTotal ?></td>
                                    <td class="num"><?= $sumElig ?></td>
                                    <td class="num"><?= $sumInel ?></td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="note">"-" means no attendance has been marked for that course yet.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>