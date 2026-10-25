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

// Admin and staff have their own pages
if (strcasecmp($_SESSION['email'], $adminEmail) === 0) { header('Location: admin.php'); exit; }
if (($_SESSION['role'] ?? '') === 'staff') { header('Location: dashboard.php'); exit; }

$username = $_SESSION['email'];

// ---- Database settings ----
$dbHost = 'localhost'; $dbName = '2026_secondsem'; $dbUser = 'root'; $dbPass = '';

$message  = '';
$warn     = '';
$student  = null;
$regNo    = '';
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

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    /* ---------- the logged in student (login email -> student table) ---------- */
    $sq = $pdo->prepare('SELECT * FROM student WHERE User_Email = ? LIMIT 1');
    $sq->execute([$username]);
    $student = $sq->fetch(PDO::FETCH_ASSOC);
    if (!$student) {
        $message = 'No student record was found for this login email.';
    } else {
        $regNo = trim($student['Registration_Number']);

        /* ---------- calculation table: attendance percentage + number of documents ---------- */
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
            $warn = 'The calculation table could not be read.';
        }

        /* ---------- registered courses (attends table) ---------- */
        $aq = $pdo->prepare('SELECT DISTINCT CourseCode FROM attends WHERE Registration_Number = ?');
        $aq->execute([$regNo]);
        $codes = array_map('trim', $aq->fetchAll(PDO::FETCH_COLUMN));

        /* ---------- course details (course table) ---------- */
        $courseCols = $pdo->query('SHOW COLUMNS FROM course')->fetchAll(PDO::FETCH_COLUMN);
        $cCode = findCol($courseCols, '/code/i', $courseCols[0]);
        $cName = findCol($courseCols, '/name/i');
        $cLec  = findCol($courseCols, '/lecturer|staff/i');
        $courseInfo = [];
        foreach ($pdo->query("SELECT * FROM course")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $courseInfo[trim($r[$cCode])] = [
                'name' => $cName ? $r[$cName] : '',
                'lec'  => $cLec  ? trim((string)$r[$cLec]) : '',
            ];
        }

        // lecturer names (staff_member: staff_Id, Name)
        $staffNames = [];
        try {
            foreach ($pdo->query('SELECT staff_Id, Name FROM staff_member')->fetchAll(PDO::FETCH_ASSOC) as $s) {
                $staffNames[trim($s['staff_Id'])] = $s['Name'];
            }
        } catch (PDOException $e) { /* the staff id is shown instead */ }

        /* ---------- attendance table: weeks marked per course + this student's marks ---------- */
        $weeksOf = [];   // [course][week] = true      (weeks marked for the whole course)
        $att     = [];   // [course][week] = 1 / 0     (this student)
        try {
            $attCols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
            $aReg = findCol($attCols, '/reg/i');   $aCourse = findCol($attCols, '/course/i');
            $aWeek = findCol($attCols, '/week/i'); $aStatus = findCol($attCols, '/status/i');
            if ($aReg && $aCourse && $aWeek && $aStatus && $codes) {
                $in = implode(',', array_fill(0, count($codes), '?'));
                $q = $pdo->prepare("SELECT `$aReg` AS reg, `$aCourse` AS course, `$aWeek` AS wk, `$aStatus` AS st
                                      FROM attendance WHERE `$aCourse` IN ($in)");
                $q->execute($codes);
                foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $c = trim($r['course']);
                    $w = (int)$r['wk'];
                    $weeksOf[$c][$w] = true;
                    if (strcasecmp(trim($r['reg']), $regNo) === 0) {
                        $att[$c][$w] = ((int)$r['st'] === 1) ? 1 : 0;
                    }
                }
            }
        } catch (PDOException $e) {
            $warn .= ($warn ? ' ' : '') . 'The attendance table could not be read.';
        }

        /* ---------- medical / excuse documents of this student ---------- */
        $docs = [];
        try {
            $dq = $pdo->prepare('SELECT Document_Id FROM document WHERE Registration_Number = ?');
            $dq->execute([$regNo]);
            foreach ($dq->fetchAll(PDO::FETCH_COLUMN) as $id) $docs[$id] = true;
        } catch (PDOException $e) {
            $warn .= ($warn ? ' ' : '') . 'The document table could not be read, so medical / excuse documents are not counted.';
        }

        /* ---------- eligibility of every registered course ---------- */
        sort($codes);
        foreach ($codes as $code) {
            $weeks = array_keys($weeksOf[$code] ?? []);
            sort($weeks);

            $present = 0; $absent = 0; $docWeeks = 0; $detail = [];
            foreach ($weeks as $w) {
                if (($att[$code][$w] ?? 0) === 1) {
                    $present++;
                    $detail[] = ['w' => $w, 's' => 'P'];
                    continue;
                }
                $absent++;                       // no saved row for a marked week = absent
                $letterFound = '';
                foreach (['M', 'E'] as $letter) {
                    $id = documentId($code, $regNo, $w, $letter);
                    if ($id !== '' && isset($docs[$id])) { $letterFound = $letter; break; }
                }
                if ($letterFound !== '') $docWeeks++;
                $detail[] = ['w' => $w, 's' => 'A', 'doc' => $letterFound];
            }

            $counted = ($maxDocs !== null) ? min($docWeeks, $maxDocs) : 0;
            $percent = null; $status = null;
            if ($weeks && $required !== null) {
                $percent = (($present + $counted) / count($weeks)) * 100;
                $status  = round($percent, 2) >= $required;
            }

            $info = $courseInfo[$code] ?? ['name' => '', 'lec' => ''];
            $lecName = $info['lec'] !== '' ? ($staffNames[$info['lec']] ?? $info['lec']) : '';

            $tables[isPracticalCode($code) ? 'Practical' : 'Theory'][] = [
                'code' => $code, 'name' => $info['name'], 'lecturer' => $lecName,
                'weeks' => count($weeks), 'present' => $present, 'absent' => $absent,
                'docs' => $docWeeks, 'counted' => $counted,
                'percent' => $percent, 'eligible' => $status, 'detail' => $detail,
            ];
        }
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
    <title>Student Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; height: 100vh; display: flex; background: #ffffff; }

        .left { width: 20%; height: 100vh; background: #1e5bb8; display: flex; flex-direction: column; padding: 25px 15px; }
        .username { color: #ffffff; font-size: 15px; font-weight: bold; text-align: center; padding-bottom: 20px; margin-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.4); word-break: break-all; }
        .btn { display: block; width: 100%; padding: 14px; background: #1e5bb8; color: #ffffff; border: none; border-radius: 6px; font-size: 16px; text-align: center; text-decoration: none; cursor: pointer; transition: background 0.2s; }
        .btn:hover, .btn.active { background: #164a96; }
        .logout { margin-top: auto; }

        .right { width: 80%; height: 100vh; background: #ffffff; overflow-y: auto; padding: 30px 40px; }
        .right h1 { color: #1e5bb8; font-size: 26px; text-align: center; margin-bottom: 6px; }
        .who { text-align: center; color: #555; font-size: 14px; margin-bottom: 24px; }

        .infobox { display: flex; flex-wrap: wrap; gap: 30px; background: #f5f8fd; border: 1px solid #d5dcea; border-radius: 8px; padding: 14px 20px; margin-bottom: 24px; }
        .infobox span { display: block; font-size: 12px; color: #667; margin-bottom: 3px; }
        .infobox b { font-size: 16px; color: #1e5bb8; }

        .panel { border: 1px solid #d5d9e0; border-radius: 10px; padding: 22px 26px; margin-bottom: 26px; }
        .panel h2 { color: #1e5bb8; font-size: 18px; margin-bottom: 14px; }

        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th { background: #1e5bb8; color: #fff; text-align: left; padding: 10px 12px; font-size: 13px; }
        td { padding: 9px 12px; border-bottom: 1px solid #e3e6ec; font-size: 14px; vertical-align: top; }
        tr:hover td { background: #f5f8fd; }
        td.num, th.num { text-align: center; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 13px; font-weight: bold; white-space: nowrap; }
        .badge.ok   { background: #e6f4e6; color: #2a7a2a; }
        .badge.bad  { background: #fdeaea; color: #b02a2a; }
        .badge.none { background: #eef0f4; color: #667; }
        .code { color: #1e5bb8; font-weight: bold; }
        .sub { color: #667; font-size: 12px; }

        details { margin-top: 6px; }
        summary { cursor: pointer; color: #1e5bb8; font-size: 12px; }
        .weeks { margin-top: 6px; display: flex; flex-wrap: wrap; gap: 6px; }
        .w { padding: 3px 8px; border-radius: 4px; font-size: 12px; }
        .w.p { background: #e6f4e6; color: #2a7a2a; }
        .w.a { background: #fdeaea; color: #b02a2a; }
        .w.d { background: #fff6e0; color: #8a5a00; }

        .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
        .msg.error { background: #fdeaea; color: #b02a2a; }
        .msg.warn  { background: #fff6e0; color: #8a5a00; }
        .note { color: #666; font-size: 13px; margin-top: 8px; }

        @media (max-width: 800px) {
            body { flex-direction: column; height: auto; }
            .left, .right { width: 100%; height: auto; }
            .right { padding: 20px; }
            .logout { margin-top: 25px; }
            table { display: block; overflow-x: auto; }
        }
    </style>
</head>
<body>

    <div class="left">
        <div class="username"><?= htmlspecialchars($username) ?></div>
        <div class="logout">
            <a href="student.php?logout=1" class="btn">Log Out</a>
        </div>
    </div>

    <div class="right">
        <h1>WELCOME TO RUSL SMART ATTENDANCE</h1>
        <?php if ($student): ?>
            <p class="who">
                <?= htmlspecialchars($student['Initial_Name'] ?? '') ?> &nbsp;|&nbsp;
                Registration Number: <?= htmlspecialchars($regNo) ?>
            </p>
        <?php endif; ?>

        <?php if ($message !== ''): ?>
            <div class="msg error"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($warn !== ''): ?>
            <div class="msg warn"><?= htmlspecialchars($warn) ?></div>
        <?php endif; ?>

        <?php if ($student): ?>
            <?php if ($required !== null): ?>
                <div class="infobox">
                    <div><span>Required attendance</span><b><?= htmlspecialchars(fmtNum($required)) ?>%</b></div>
                    <div><span>Maximum medical / excuse documents counted</span><b><?= (int)$maxDocs ?></b></div>
                </div>
            <?php else: ?>
                <div class="msg warn">The required attendance percentage is not saved yet, so eligibility cannot be shown.</div>
            <?php endif; ?>

            <?php foreach (['Theory', 'Practical'] as $kind): ?>
                <div class="panel">
                    <h2>My <?= $kind ?> Courses</h2>
                    <?php if (!$tables[$kind]): ?>
                        <p class="note">No <?= strtolower($kind) ?> courses registered.</p>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th>Lecturer</th>
                                    <th class="num">Weeks Marked</th>
                                    <th class="num">Present</th>
                                    <th class="num">Absent</th>
                                    <th class="num">Medical / Excuse</th>
                                    <th class="num">Attendance %</th>
                                    <th class="num">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables[$kind] as $t): ?>
                                <tr>
                                    <td>
                                        <span class="code"><?= htmlspecialchars($t['code']) ?></span>
                                        <?php if ($t['name'] !== ''): ?><div class="sub"><?= htmlspecialchars($t['name']) ?></div><?php endif; ?>
                                        <?php if ($t['detail']): ?>
                                            <details>
                                                <summary>Week by week</summary>
                                                <div class="weeks">
                                                    <?php foreach ($t['detail'] as $d):
                                                        if ($d['s'] === 'P') { $cls = 'p'; $txt = 'W' . $d['w'] . ' Present'; }
                                                        elseif (!empty($d['doc'])) { $cls = 'd'; $txt = 'W' . $d['w'] . ' Absent (' . ($d['doc'] === 'M' ? 'Medical' : 'Excuse') . ')'; }
                                                        else { $cls = 'a'; $txt = 'W' . $d['w'] . ' Absent'; }
                                                    ?>
                                                        <span class="w <?= $cls ?>"><?= htmlspecialchars($txt) ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $t['lecturer'] !== '' ? htmlspecialchars($t['lecturer']) : '-' ?></td>
                                    <td class="num"><?= (int)$t['weeks'] ?></td>
                                    <td class="num"><?= (int)$t['present'] ?></td>
                                    <td class="num"><?= (int)$t['absent'] ?></td>
                                    <td class="num">
                                        <?= (int)$t['docs'] ?>
                                        <?php if ($t['docs'] > $t['counted']): ?>
                                            <div class="sub">(<?= (int)$t['counted'] ?> counted)</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num"><?= $t['percent'] === null ? '-' : htmlspecialchars(fmtNum(round($t['percent'], 2))) . '%' ?></td>
                                    <td class="num">
                                        <?php if ($t['eligible'] === null): ?>
                                            <span class="badge none">Not started</span>
                                        <?php elseif ($t['eligible']): ?>
                                            <span class="badge ok">Eligible</span>
                                        <?php else: ?>
                                            <span class="badge bad">Not Eligible</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <p class="note">Attendance % = (present weeks + counted medical / excuse weeks) / weeks marked x 100.</p>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</body>
</html>