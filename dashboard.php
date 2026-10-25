<?php
session_start();

$adminEmail = 'admin@as.rjt.ac.lk';

// Logout: destroy the session and go to index.php
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: index.php');
    exit;
}

// Not logged in -> go to index.php
if (!isset($_SESSION['email'])) { header('Location: index.php'); exit; }

// The administrator has admin.php
if (strcasecmp($_SESSION['email'], $adminEmail) === 0) { header('Location: admin.php'); exit; }

$username = $_SESSION['email'];

// ---- Database settings ----
$dbHost = 'localhost'; $dbName = '2026_secondsem'; $dbUser = 'root'; $dbPass = '';

$message  = '';
$warn     = '';
$staff    = null;
$isHod    = false;
$required = null;    // attendance percentage (calculation table)
$maxDocs  = null;    // maximum number of excuse / medical documents (calculation table)
$tables   = ['Theory' => [], 'Practical' => []];

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

/*
 * Is one student eligible in a course?
 * percentage = (present weeks + medical / excuse weeks, up to the maximum number) / weeks marked * 100
 */
function isEligible($code, $reg, $weeks, $att, $docs, $required, $maxDocs) {
    $present = 0; $docWeeks = 0;
    foreach ($weeks as $w) {
        if (($att[$code][$reg][$w] ?? 0) === 1) { $present++; continue; }     // no saved row for a marked week = absent
        foreach (['M', 'E'] as $letter) {
            $id = documentId($code, $reg, $w, $letter);
            if ($id !== '' && isset($docs[$id]) && strcasecmp($docs[$id], $reg) === 0) { $docWeeks++; break; }
        }
    }
    $percent = (($present + min($docWeeks, $maxDocs)) / count($weeks)) * 100;
    return round($percent, 2) >= $required;
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    /* ---------- the logged in lecturer / demonstrator ---------- */
    $sq = $pdo->prepare('SELECT staff_Id, Name, Position FROM staff_member WHERE User_Email = ? LIMIT 1');
    $sq->execute([$username]);
    $staff = $sq->fetch(PDO::FETCH_ASSOC);
    if (!$staff) { header('Location: markattendance.php'); exit; }          // only staff members use this dashboard
    $isHod = strtoupper(trim($staff['Position'])) === 'HOD';      // HOD sees every module, others only their own

    /* ---------- calculation table: attendance percentage + maximum documents ---------- */
    try {
        $calcCols = $pdo->query('SHOW COLUMNS FROM calculation')->fetchAll(PDO::FETCH_COLUMN);
        $pctCol = findCol($calcCols, '/percent/i');
        $docCol = findCol($calcCols, '/doc/i');
        if ($pctCol !== null && $docCol !== null) {
            $row = $pdo->query("SELECT `$pctCol` AS p, `$docCol` AS d FROM calculation LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['p'] !== null && $row['d'] !== null) {
                $required = (float)$row['p'];
                $maxDocs  = (int)$row['d'];
            }
        }
    } catch (PDOException $e) {
        $warn = 'The calculation table could not be read: ' . $e->getMessage();
    }

    /* ---------- learning modules (courses) of this person ---------- */
    $courseCols = $pdo->query('SHOW COLUMNS FROM course')->fetchAll(PDO::FETCH_COLUMN);
    $cCode = findCol($courseCols, '/code/i', $courseCols[0]);
    $cName = findCol($courseCols, '/name/i');
    $select = "SELECT `$cCode` AS code" . ($cName ? ", `$cName` AS cname" : ", '' AS cname") . " FROM course";
    if ($isHod) {
        $courses = $pdo->query("$select ORDER BY `$cCode`")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $cq = $pdo->prepare("$select WHERE `$cCode` IN (SELECT `Subject` FROM academic_staff_subject WHERE `staff_id` = ?) ORDER BY `$cCode`");
        $cq->execute([$staff['staff_Id']]);
        $courses = $cq->fetchAll(PDO::FETCH_ASSOC);
    }
    $visible = array_map('trim', array_column($courses, 'code'));

    /* ---------- enrolled students, saved attendance, documents ---------- */
    $enrolled = [];
    foreach ($pdo->query('SELECT CourseCode, Registration_Number FROM attends')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = trim($r['CourseCode']);
        if (in_array($c, $visible, true)) $enrolled[$c][trim($r['Registration_Number'])] = true;
    }

    $att = []; $weeksOf = [];
    try {
        $attCols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
        $aReg = findCol($attCols, '/reg/i');   $aCourse = findCol($attCols, '/course/i');
        $aWeek = findCol($attCols, '/week/i'); $aStatus = findCol($attCols, '/status/i');
        if ($aReg && $aCourse && $aWeek && $aStatus) {
            $q = $pdo->query("SELECT `$aReg` AS reg, `$aCourse` AS course, `$aWeek` AS wk, `$aStatus` AS st FROM attendance");
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $c = trim($r['course']);
                if (!in_array($c, $visible, true)) continue;
                $w = (int)$r['wk'];
                $att[$c][trim($r['reg'])][$w] = ((int)$r['st'] === 1) ? 1 : 0;
                $weeksOf[$c][$w] = true;
            }
        }
    } catch (PDOException $e) {
        $warn .= ($warn ? ' ' : '') . 'The attendance table could not be read: ' . $e->getMessage();
    }

    $docs = [];
    try {
        foreach ($pdo->query('SELECT Document_Id, Registration_Number FROM document')->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $docs[$d['Document_Id']] = trim($d['Registration_Number']);
        }
    } catch (PDOException $e) {
        $warn .= ($warn ? ' ' : '') . 'The document table could not be read, so medical / excuse documents are not counted: ' . $e->getMessage();
    }

    /* ---------- eligible / ineligible students of every module ---------- */
    foreach ($courses as $c) {
        $code  = trim($c['code']);
        $regs  = array_keys($enrolled[$code] ?? []);
        $weeks = array_keys($weeksOf[$code] ?? []);
        sort($weeks);

        $el = null; $in = null;
        if ($weeks && $required !== null) {
            $el = 0; $in = 0;
            foreach ($regs as $reg) {
                if (isEligible($code, $reg, $weeks, $att, $docs, $required, $maxDocs)) $el++; else $in++;
            }
        }
        $tables[isPracticalCode($code) ? 'Practical' : 'Theory'][] = [
            'code' => $code, 'name' => $c['cname'], 'total' => count($regs),
            'weeks' => count($weeks), 'eligible' => $el, 'ineligible' => $in,
        ];
    }
} catch (PDOException $e) {
    $message = 'Database error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; height: 100vh; display: flex; background: #ffffff; }

        /* ---------- LEFT SECTION (20%) ---------- */
        .left { width: 20%; height: 100vh; background: #1e5bb8; display: flex; flex-direction: column; padding: 25px 15px; }
        .username { color: #ffffff; font-size: 15px; font-weight: bold; text-align: center; padding-bottom: 20px; margin-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.4); word-break: break-all; }
        .menu { display: flex; flex-direction: column; gap: 15px; }
        .btn { display: block; width: 100%; padding: 14px; background: #1e5bb8; color: #ffffff; border: none; border-radius: 6px; font-size: 16px; text-align: center; text-decoration: none; cursor: pointer; transition: background 0.2s; }
        .btn:hover, .btn.active { background: #164a96; }
        .logout { margin-top: auto; }

        /* ---------- RIGHT SECTION (80%) ---------- */
        .right { width: 80%; height: 100vh; background: #ffffff; overflow-y: auto; padding: 30px 40px; }
        .right h1 { color: #1e5bb8; font-size: 26px; text-align: center; margin-bottom: 6px; }
        .who { text-align: center; color: #555; font-size: 14px; margin-bottom: 24px; }

        .infobox { display: flex; flex-wrap: wrap; gap: 30px; background: #f5f8fd; border: 1px solid #d5dcea; border-radius: 8px; padding: 14px 20px; margin-bottom: 24px; }
        .infobox span { display: block; font-size: 12px; color: #667; margin-bottom: 3px; }
        .infobox b { font-size: 16px; color: #1e5bb8; }

        .panel { border: 1px solid #d5d9e0; border-radius: 10px; padding: 22px 26px; margin-bottom: 26px; }
        .panel h2 { color: #1e5bb8; font-size: 18px; margin-bottom: 14px; }

        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { background: #1e5bb8; color: #fff; text-align: left; padding: 10px 14px; font-size: 14px; }
        td { padding: 9px 14px; border-bottom: 1px solid #e3e6ec; font-size: 15px; }
        tr:hover td { background: #f5f8fd; }
        td.num, th.num { text-align: center; width: 130px; }
        .ok  { color: #2a7a2a; font-weight: bold; }
        .bad { color: #b02a2a; font-weight: bold; }
        tr.total td { background: #eef2fa; font-weight: bold; }
        a.code { color: #1e5bb8; text-decoration: none; font-weight: bold; }
        a.code:hover { text-decoration: underline; }

        .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
        .msg.error { background: #fdeaea; color: #b02a2a; }
        .msg.warn  { background: #fff6e0; color: #8a5a00; }
        .note { color: #666; font-size: 13px; margin-top: 8px; }

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
            <a href="lecmark.php" class="btn">Mark Attendance</a>
            <a href="viewattendance.php" class="btn">View Attendance</a>
            <a href="document.php" class="btn">Add Document</a>
        </div>

        <div class="logout">
            <a href="dashboard.php?logout=1" class="btn">Log Out</a>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="right">
        <h1>WELCOME TO RUSL SMART ATTENDANCE</h1>
        <?php if ($staff): ?>
            <p class="who"><?= htmlspecialchars($staff['Name']) ?> &nbsp;|&nbsp; <?= htmlspecialchars($staff['Position']) ?> &nbsp;|&nbsp; Staff ID: <?= htmlspecialchars($staff['staff_Id']) ?></p>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <div class="msg error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($warn !== ''): ?>
            <div class="msg warn"><?= htmlspecialchars($warn) ?></div>
        <?php endif; ?>

        <?php if ($staff): ?>
            <?php if ($required !== null): ?>
                <div class="infobox">
                    <div><span>Required attendance</span><b><?= htmlspecialchars(fmtNum($required)) ?>%</b></div>
                    <div><span>Maximum medical / excuse documents counted</span><b><?= (int)$maxDocs ?></b></div>
                </div>
            <?php else: ?>
                <div class="msg warn">The attendance percentage and the maximum number of documents are not saved yet, so eligibility cannot be shown. The administrator can save them on the admin page.</div>
            <?php endif; ?>

            <?php foreach (['Theory', 'Practical'] as $kind): ?>
                <div class="panel">
                    <h2><?= $isHod ? 'All ' : 'My ' ?><?= $kind ?> Learning Modules</h2>
                    <?php if (!$tables[$kind]): ?>
                        <p class="note">No <?= strtolower($kind) ?> modules found<?= $isHod ? '' : ' for your Staff ID' ?>.</p>
                    <?php else:
                        $sumTotal = 0; $sumElig = 0; $sumInel = 0;
                    ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Learning Module</th>
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
                                    <td>
                                        <a class="code" href="viewattendance.php?course=<?= urlencode($t['code']) ?>"><?= htmlspecialchars($t['code']) ?></a>
                                        <?= ($t['name'] !== '' && $t['name'] !== null) ? ' - ' . htmlspecialchars($t['name']) : '' ?>
                                    </td>
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
                        <p class="note">Click a module code to see every student and week. "-" means no attendance has been marked yet.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>