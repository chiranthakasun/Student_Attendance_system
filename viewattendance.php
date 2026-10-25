<?php
session_start();

// Super users: admin and HOD (a staff member whose Position is HOD gets the 'admin' role at login)
$superUsers = ['admin@as.rjt.ac.lk', 'hod@as.rjt.ac.lk'];

if (!isset($_SESSION['email'])) { header('Location: index.php'); exit; }

$userEmail = $_SESSION['email'];
$role      = $_SESSION['role'] ?? '';

// Students have their own page
if ($role === 'student') { header('Location: student.php'); exit; }

$isAdmin   = in_array(strtolower($userEmail), $superUsers, true) || $role === 'admin';
$username  = $userEmail;

// ---- Database settings ----
$dbHost = 'localhost'; $dbName = '2026_secondsem'; $dbUser = 'root'; $dbPass = '';

$message   = '';
$type      = '';
$warn      = '';
$courses   = [];       // courses this person is allowed to see
$summary   = [];       // one row per course (eligibility totals)
$students  = [];       // students of the selected course
$reports   = [];       // $reports[registration] = attendance report of one student
$markedWeeks = [];     // weeks marked for the selected course
$required  = null;     // attendance percentage from the calculation table
$maxDocs   = null;     // maximum number of excuse / medical documents from the calculation table
$staff     = null;
$isHod     = false;
$canSeeAll = $isAdmin;

$course = trim($_GET['course'] ?? '');
$week   = trim($_GET['week'] ?? '');

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
 *   ICT 1305, ICT/2024/035, week 2, excuse letter -> 13050352E
 */
function documentId($course, $reg, $weekNo, $letter) {
    if (!preg_match('/\d+/', $course, $cm) || !preg_match('/(\d+)\D*$/', $reg, $rm)) return '';
    $last3 = substr(str_pad($rm[1], 3, '0', STR_PAD_LEFT), -3);
    return $cm[0] . $last3 . (int)$weekNo . $letter;
}

/*
 * What is shown for one week:
 *   1                                   -> Present
 *   not 1 + medical document uploaded   -> Medical
 *   not 1 + excuse letter uploaded      -> Excuse
 *   not 1 and no document               -> Absent
 */
function attendanceLabel($status, $course, $reg, $weekNo, $docs) {
    if ($status === 1) return ['Present', 'present'];
    foreach (['M' => ['Medical', 'medical'], 'E' => ['Excuse', 'excuse']] as $letter => $info) {
        $id = documentId($course, $reg, $weekNo, $letter);
        if ($id !== '' && isset($docs[$id]) && strcasecmp(trim($docs[$id]), trim($reg)) === 0) return $info;
    }
    return ['Absent', 'absent'];
}

/* Weeks that have attendance for a course, in order */
function courseWeeks($weeksOf, $code) {
    $w = array_keys($weeksOf[$code] ?? []);
    sort($w);
    return $w;
}

/*
 * Attendance report of one student in one course:
 *  - every marked week: Present / Absent / Medical / Excuse  (no saved row for a marked week = absent)
 *  - medical / excuse weeks count as attended only up to the maximum number of documents
 *  - percentage = (present + counted documents) / weeks marked * 100
 */
function studentReport($code, $reg, $weeks, $att, $docs, $required, $maxDocs) {
    $cells = []; $present = 0; $absent = 0; $docWeeks = 0;
    foreach ($weeks as $w) {
        $status = ($att[$code][$reg][$w] ?? 0) === 1 ? 1 : 0;
        $lab    = attendanceLabel($status, $code, $reg, $w, $docs);
        $counted = true;
        if ($lab[1] === 'present') {
            $present++;
        } elseif ($lab[1] === 'medical' || $lab[1] === 'excuse') {
            $docWeeks++;
            if ($maxDocs !== null && $docWeeks > $maxDocs) $counted = false;   // maximum reached
        } else {
            $absent++;
        }
        $cells[$w] = ['label' => $lab[0], 'class' => $lab[1], 'counted' => $counted];
    }
    $counted = ($maxDocs === null) ? $docWeeks : min($docWeeks, $maxDocs);
    $n       = count($weeks);
    $percent = $n > 0 ? (($present + $counted) / $n) * 100 : 0;

    return [
        'cells'    => $cells,
        'present'  => $present,
        'absent'   => $absent,
        'docs'     => $docWeeks,
        'counted'  => $counted,
        'percent'  => $percent,
        'eligible' => ($required !== null && $n > 0) ? (round($percent, 2) >= $required) : null,
    ];
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    /* ---------- who is allowed to see what ---------- */
    if (!$isAdmin) {
        $sq = $pdo->prepare('SELECT staff_Id, Name, Position FROM staff_member WHERE User_Email = ? LIMIT 1');
        $sq->execute([$userEmail]);
        $staff = $sq->fetch(PDO::FETCH_ASSOC);
        if (!$staff) { header('Location: index.php'); exit; }     // only admin / HOD and staff can open this page
        $isHod = strtoupper(trim($staff['Position'])) === 'HOD';
    }
    $canSeeAll = $isAdmin || $isHod;      // admin and HOD see every course, other staff only the courses they teach

    /* ---------- attendance table columns ---------- */
    $attCols = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_COLUMN);
    $col = [
        'reg'    => findCol($attCols, '/reg/i'),
        'course' => findCol($attCols, '/course/i'),
        'week'   => findCol($attCols, '/week/i'),
        'status' => findCol($attCols, '/status/i'),
    ];
    foreach ($col as $name => $c) {
        if ($c === null) throw new PDOException('The attendance table needs a column for "' . $name . '". Columns found: ' . implode(', ', $attCols));
    }

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

    /* ---------- courses this person can see ---------- */
    $courseCols = $pdo->query('SHOW COLUMNS FROM course')->fetchAll(PDO::FETCH_COLUMN);
    $cCode = findCol($courseCols, '/code/i', $courseCols[0]);
    $cName = findCol($courseCols, '/name/i');
    $select = "SELECT `$cCode` AS code" . ($cName ? ", `$cName` AS cname" : ", '' AS cname") . " FROM course";
    if ($canSeeAll) {
        $courses = $pdo->query("$select ORDER BY `$cCode`")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $cq = $pdo->prepare("$select WHERE `$cCode` IN (SELECT `Subject` FROM academic_staff_subject WHERE `staff_id` = ?) ORDER BY `$cCode`");
        $cq->execute([$staff['staff_Id']]);
        $courses = $cq->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($course !== '' && !in_array($course, array_column($courses, 'code'), true)) $course = '';

    /* ---------- data of the visible courses ---------- */
    $visible = array_map('trim', array_column($courses, 'code'));

    $enrolled = [];
    foreach ($pdo->query('SELECT CourseCode, Registration_Number FROM attends')->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = trim($r['CourseCode']);
        if (in_array($c, $visible, true)) $enrolled[$c][trim($r['Registration_Number'])] = true;
    }

    $att = []; $weeksOf = [];
    $q = $pdo->query("SELECT `{$col['reg']}` AS reg, `{$col['course']}` AS course, `{$col['week']}` AS wk, `{$col['status']}` AS st FROM attendance");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = trim($r['course']);
        if (!in_array($c, $visible, true)) continue;
        $w = (int)$r['wk'];
        $att[$c][trim($r['reg'])][$w] = ((int)$r['st'] === 1) ? 1 : 0;
        $weeksOf[$c][$w] = true;
    }

    $docs = [];
    try {
        foreach ($pdo->query('SELECT Document_Id, Registration_Number FROM document')->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $docs[$d['Document_Id']] = trim($d['Registration_Number']);
        }
    } catch (PDOException $e) {
        $warn .= ($warn ? ' ' : '') . 'The document table could not be read, so Medical / Excuse is not shown: ' . $e->getMessage();
    }

    /* ---------- selected course: every student with every week ---------- */
    if ($course !== '') {
        $st = $pdo->prepare(
            'SELECT s.Registration_Number, s.Initial_Name
               FROM student s
              INNER JOIN attends a ON a.Registration_Number = s.Registration_Number
              WHERE a.CourseCode = ?
              ORDER BY s.Registration_Number'
        );
        $st->execute([$course]);
        $students = $st->fetchAll(PDO::FETCH_ASSOC);

        $markedWeeks = courseWeeks($weeksOf, $course);
        if ($week !== '' && !in_array((int)$week, $markedWeeks, true)) $week = '';

        foreach ($students as $s) {
            $reg = trim($s['Registration_Number']);
            $reports[$reg] = studentReport($course, $reg, $markedWeeks, $att, $docs, $required, $maxDocs);
        }
    }

    /* ---------- summary of every visible course ---------- */
    if ($course === '') {
        foreach ($courses as $c) {
            $code  = trim($c['code']);
            $regs  = array_keys($enrolled[$code] ?? []);
            $weeks = courseWeeks($weeksOf, $code);
            $el = 0; $in = 0;
            if ($weeks && $required !== null) {
                foreach ($regs as $reg) {
                    $rep = studentReport($code, $reg, $weeks, $att, $docs, $required, $maxDocs);
                    if ($rep['eligible']) $el++; else $in++;
                }
            }
            $summary[] = [
                'code' => $code, 'name' => $c['cname'], 'type' => isPracticalCode($code) ? 'Practical' : 'Theory',
                'enrolled' => count($regs), 'weeks' => count($weeks),
                'eligible' => ($weeks && $required !== null) ? $el : null,
                'ineligible' => ($weeks && $required !== null) ? $in : null,
            ];
        }
    }
} catch (PDOException $e) {
    $message = 'Database error: ' . $e->getMessage();
    $type    = 'error';
}

/* totals of the selected course */
$totEligible = 0; $totIneligible = 0;
foreach ($reports as $rep) {
    if ($rep['eligible'] === true) $totEligible++;
    elseif ($rep['eligible'] === false) $totIneligible++;
}
$showEligibility = ($required !== null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Attendance</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; height: 100vh; display: flex; background: #fff; }
        .left { width: 20%; height: 100vh; background: #1e5bb8; display: flex; flex-direction: column; padding: 25px 15px; }
        .username { color: #fff; font-size: 15px; font-weight: bold; text-align: center; padding-bottom: 20px; margin-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.4); word-break: break-all; }
        .menu { display: flex; flex-direction: column; gap: 15px; }
        .btn { display: block; width: 100%; padding: 14px; background: #1e5bb8; color: #fff; border: none; border-radius: 6px; font-size: 16px; text-align: center; text-decoration: none; cursor: pointer; transition: background 0.2s; }
        .btn:hover, .btn.active { background: #164a96; }
        .logout { margin-top: auto; }
        .right { width: 80%; height: 100vh; overflow-y: auto; padding: 30px 40px; background: #fff; }
        .right h2 { color: #1e5bb8; margin-bottom: 20px; }
        .filters { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; margin-bottom: 22px; }
        .filters label { display: block; font-size: 13px; color: #555; margin-bottom: 5px; }
        .filters select { padding: 10px 12px; border: 1px solid #b8c1d1; border-radius: 6px; font-size: 15px; min-width: 220px; }
        .infobox { display: flex; flex-wrap: wrap; gap: 30px; background: #f5f8fd; border: 1px solid #d5dcea; border-radius: 8px; padding: 14px 20px; margin-bottom: 22px; }
        .infobox span { display: block; font-size: 12px; color: #667; margin-bottom: 3px; }
        .infobox b { font-size: 16px; color: #1e5bb8; }
        .scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #1e5bb8; color: #fff; text-align: left; padding: 11px 14px; font-size: 14px; white-space: nowrap; }
        td { padding: 10px 14px; border-bottom: 1px solid #e3e6ec; font-size: 15px; white-space: nowrap; }
        tr:hover td { background: #f5f8fd; }
        td.c, th.c { text-align: center; }
        .present { color: #2a7a2a; font-weight: bold; }
        .absent  { color: #b02a2a; font-weight: bold; }
        .medical { color: #1e5bb8; font-weight: bold; }
        .excuse  { color: #b8860b; font-weight: bold; }
        .none    { color: #999; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 13px; font-weight: bold; }
        .badge.yes { background: #e6f4e6; color: #2a7a2a; }
        .badge.no  { background: #fdeaea; color: #b02a2a; }
        a.code { color: #1e5bb8; text-decoration: none; font-weight: bold; }
        a.code:hover { text-decoration: underline; }
        .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
        .msg.error { background: #fdeaea; color: #b02a2a; }
        .msg.warn  { background: #fff6e0; color: #8a5a00; }
        .note { color: #666; font-size: 14px; margin-bottom: 12px; }
        @media (max-width: 800px) {
            body { flex-direction: column; height: auto; }
            .left, .right { width: 100%; height: auto; }
            .right { padding: 20px; }
            .logout { margin-top: 25px; }
        }
    </style>
</head>
<body>

    <div class="left">
        <div class="username"><?= htmlspecialchars($username) ?></div>
        <div class="menu">
            <?php if ($isAdmin): ?>
                <a href="uploadfile.php" class="btn">Upload File</a>
                <a href="markattendance.php" class="btn">Mark Attendance</a>
            <?php else: ?>
                <a href="dashboard.php" class="btn">Dashboard</a>
                <a href="lecmark.php" class="btn">Mark Attendance</a>
            <?php endif; ?>
            <a href="viewattendance.php" class="btn active">View Attendance</a>
            <a href="document.php" class="btn">Add Document</a>
        </div>
        <div class="logout">
            <a href="<?= $isAdmin ? 'admin.php' : 'dashboard.php' ?>?logout=1" class="btn">Log Out</a>
        </div>
    </div>

    <div class="right">
        <h2>View Attendance</h2>

        <?php if ($message !== ''): ?>
            <div class="msg <?= $type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($warn !== ''): ?>
            <div class="msg warn"><?= htmlspecialchars($warn) ?></div>
        <?php endif; ?>
        <?php if (!$showEligibility): ?>
            <div class="msg warn">The attendance percentage and the maximum number of documents are not saved yet, so eligibility is not shown. The administrator can save them on the admin page.</div>
        <?php endif; ?>

        <p class="note">
            <?= $canSeeAll ? 'You can see all courses.' : 'You can see only the courses you teach.' ?>
        </p>

        <form method="get" action="viewattendance.php" class="filters">
            <div>
                <label for="course">Course</label>
                <select name="course" id="course" onchange="this.form.submit()">
                    <option value="">-- All my courses (summary) --</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= htmlspecialchars($c['code']) ?>" <?= $c['code'] === $course ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['code'] . ($c['cname'] ? ' - ' . $c['cname'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($course !== ''): ?>
            <div>
                <label for="week">Week</label>
                <select name="week" id="week" onchange="this.form.submit()">
                    <option value="">All weeks</option>
                    <?php foreach ($markedWeeks as $w): ?>
                        <option value="<?= (int)$w ?>" <?= $week !== '' && (int)$week === (int)$w ? 'selected' : '' ?>>Week <?= (int)$w ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </form>

        <?php if (!$courses): ?>
            <p class="note"><?= $canSeeAll ? 'No courses found.' : 'No courses are assigned to your Staff ID.' ?></p>

        <?php elseif ($course === ''): ?>
            <!-- ===== Summary of all visible courses ===== -->
            <div class="scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Type</th>
                            <th class="c">Total Enrolled</th>
                            <th class="c">Weeks Marked</th>
                            <th class="c">Eligible</th>
                            <th class="c">Not Eligible</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary as $s): ?>
                        <tr>
                            <td>
                                <a class="code" href="viewattendance.php?course=<?= urlencode($s['code']) ?>"><?= htmlspecialchars($s['code']) ?></a>
                                <?= $s['name'] ? ' - ' . htmlspecialchars($s['name']) : '' ?>
                            </td>
                            <td><?= $s['type'] ?></td>
                            <td class="c"><?= (int)$s['enrolled'] ?></td>
                            <td class="c"><?= (int)$s['weeks'] ?></td>
                            <td class="c present"><?= $s['eligible'] === null ? '-' : (int)$s['eligible'] ?></td>
                            <td class="c absent"><?= $s['ineligible'] === null ? '-' : (int)$s['ineligible'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="note">Select a course (or click its code) to see every student and every week. "-" means no attendance has been marked yet.</p>

        <?php elseif (!$students): ?>
            <p class="note">No students are linked to <?= htmlspecialchars($course) ?>. Upload the student Excel file first.</p>

        <?php elseif (!$markedWeeks): ?>
            <p class="note">No attendance has been marked for <?= htmlspecialchars($course) ?> yet.</p>

        <?php else: ?>
            <div class="infobox">
                <div><span>Course</span><b><?= htmlspecialchars($course) ?></b></div>
                <div><span>Type</span><b><?= isPracticalCode($course) ? 'Practical' : 'Theory' ?></b></div>
                <div><span>Students</span><b><?= count($students) ?></b></div>
                <div><span>Weeks marked</span><b><?= count($markedWeeks) ?></b></div>
                <?php if ($showEligibility): ?>
                    <div><span>Required attendance</span><b><?= htmlspecialchars(fmtNum($required)) ?>%</b></div>
                    <div><span>Max medical / excuse counted</span><b><?= (int)$maxDocs ?></b></div>
                    <div><span>Eligible</span><b class="present"><?= $totEligible ?></b></div>
                    <div><span>Not eligible</span><b class="absent"><?= $totIneligible ?></b></div>
                <?php endif; ?>
            </div>

            <div class="scroll">
            <table>
                <thead>
                    <tr>
                        <th class="c" style="width:50px">No</th>
                        <th>Registration Number</th>
                        <th>Student Name</th>
                        <?php if ($week !== ''): ?>
                            <th class="c">Week <?= (int)$week ?></th>
                        <?php else: foreach ($markedWeeks as $w): ?>
                            <th class="c">Week <?= (int)$w ?></th>
                        <?php endforeach; endif; ?>
                        <th class="c">Present</th>
                        <th class="c">Absent</th>
                        <th class="c">Medical / Excuse</th>
                        <?php if ($showEligibility): ?>
                            <th class="c">Counted</th>
                            <th class="c">Attendance %</th>
                            <th class="c">Result</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $i => $s):
                        $reg = trim($s['Registration_Number']);
                        $rep = $reports[$reg];
                    ?>
                    <tr>
                        <td class="c"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($reg) ?></td>
                        <td><?= htmlspecialchars($s['Initial_Name'] ?? '') ?></td>

                        <?php foreach ($markedWeeks as $w):
                            if ($week !== '' && (int)$week !== (int)$w) continue;
                            $cell = $rep['cells'][$w];
                        ?>
                            <td class="c <?= $cell['class'] ?>">
                                <?= htmlspecialchars($cell['label']) ?><?= (!$cell['counted'] && $showEligibility) ? '*' : '' ?>
                            </td>
                        <?php endforeach; ?>

                        <td class="c"><?= $rep['present'] ?></td>
                        <td class="c"><?= $rep['absent'] ?></td>
                        <td class="c"><?= $rep['docs'] ?></td>
                        <?php if ($showEligibility): ?>
                            <td class="c"><?= $rep['counted'] ?></td>
                            <td class="c"><b><?= number_format($rep['percent'], 1) ?>%</b></td>
                            <td class="c">
                                <span class="badge <?= $rep['eligible'] ? 'yes' : 'no' ?>"><?= $rep['eligible'] ? 'Eligible' : 'Not Eligible' ?></span>
                            </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <p class="note">
                Present = attendance 1. An absent week shows Medical or Excuse when a medical / excuse letter was uploaded for that student, course and week.
                <?php if ($showEligibility): ?>
                    Medical / Excuse weeks count as attended only up to <?= (int)$maxDocs ?> per student (* = not counted, maximum reached).
                    Attendance % = (Present + Counted) / <?= count($markedWeeks) ?> weeks marked. Eligibility always uses all weeks.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

</body>
</html>