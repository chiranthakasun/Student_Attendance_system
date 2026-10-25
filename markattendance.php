<?php
session_start();

$adminEmail = 'admin@as.rjt.ac.lk';

if (!isset($_SESSION['email'])) { header('Location: index.php'); exit; }

// Admin and staff (lecturers) can open this page. Students cannot.
$superUsers = ['admin@as.rjt.ac.lk', 'hod@as.rjt.ac.lk'];
$role       = $_SESSION['role'] ?? '';
$isAdmin    = in_array(strtolower($_SESSION['email']), $superUsers, true) || $role === 'admin';   // admin and HOD
if (!$isAdmin && $role !== 'staff') {
    header('Location: ' . ($role === 'student' ? 'student.php' : 'index.php'));
    exit;
}

$username = $_SESSION['email'];

// ---- Database settings ----
$dbHost = 'localhost'; $dbName = '2026_secondsem'; $dbUser = 'root'; $dbPass = '';

$message     = '';
$type        = '';
$keyWarning  = '';
$courses     = [];
$weeks       = [];
$students    = [];
$saved       = [];
$markedWeeks = [];
$weekRow     = null;
$lecturer    = null;
$dbCount     = null;
$allWeekCount = 0;

$course = trim($_REQUEST['course'] ?? '');
$week   = trim($_REQUEST['week'] ?? '');

if (isset($_GET['done'])) {
    $message = 'Week ' . (int)$_GET['done'] . ' attendance saved for ' . (int)($_GET['n'] ?? 0) . ' student(s).';
    $classShown = preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['class'] ?? ''));
    if ($classShown !== '') $message .= ' Class ID: ' . $classShown . '.';
    $type    = 'success';
}

function findCol($cols, $pattern, $default = null) {
    foreach ($cols as $c) {
        if (preg_match($pattern, $c)) return $c;
    }
    return $default;
}

/*
 * Class ID = first 4 digits of the course code + week number + P (practical) or T (theory)
 *   ICT 1305P, week 3  ->  13053P  (Type P)
 *   ICT 1305,  week 3  ->  13053T  (Type T)
 * A course code that ends with the letter P is a practical course.
 */
function makeClass($course, $weekNo) {
    preg_match('/\d{4}/', $course, $m);
    $digits = $m ? $m[0] : preg_replace('/\D+/', '', $course);
    $type   = preg_match('/\d{4}\s*P$/i', trim($course)) ? 'P' : 'T';
    return ['id' => $digits . (int)$weekNo . $type, 'type' => $type];
}

/* One student can have one row for EVERY week of a course */
function fixAttendanceKeys($pdo, $col) {
    $idx = $pdo->query('SHOW INDEX FROM attendance')->fetchAll(PDO::FETCH_ASSOC);

    $uniques = [];
    foreach ($idx as $r) {
        if ((int)$r['Non_unique'] === 0) {
            $uniques[$r['Key_name']][(int)$r['Seq_in_index']] = strtolower($r['Column_name']);
        }
    }

    $info = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_ASSOC);
    $autoCols = [];
    foreach ($info as $c) {
        if (stripos($c['Extra'], 'auto_increment') !== false) $autoCols[] = strtolower($c['Field']);
    }

    $need    = [strtolower($col['reg']), strtolower($col['course']), strtolower($col['week'])];
    $hasGood = false;

    foreach ($uniques as $name => $cols) {
        ksort($cols);
        $cols = array_values($cols);

        if (count($cols) === 1 && in_array($cols[0], $autoCols, true)) continue;
        if (count($cols) === 3 && !array_diff($need, $cols)) { $hasGood = true; continue; }

        if ($name === 'PRIMARY') $pdo->exec('ALTER TABLE attendance DROP PRIMARY KEY');
        else                     $pdo->exec('ALTER TABLE attendance DROP INDEX `' . str_replace('`', '', $name) . '`');
    }

    if (!$hasGood) {
        $pdo->exec("ALTER TABLE attendance ADD UNIQUE KEY uq_student_course_week (`{$col['reg']}`, `{$col['course']}`, `{$col['week']}`)");
    }
}

function normName($s) {
    $s = strtolower($s);
    $s = preg_replace('/\b(prof|professor|dr|mr|mrs|ms|miss|rev|ven|eng|ps|snr|senior|lecturer)\b\.?/', ' ', $s);
    $s = preg_replace('/[^a-z\s]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function sameName($a, $b) {
    $a = normName($a); $b = normName($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    if ($a === str_replace(' ', '', $b) || $b === str_replace(' ', '', $a)) return true;
    $ta = explode(' ', $a); $tb = explode(' ', $b);
    $short = count($ta) <= count($tb) ? $ta : $tb;
    $long  = count($ta) <= count($tb) ? $tb : $ta;
    return count($short) >= 2 && !array_diff($short, $long);
}

/* Lecturer of a course: uploaded Excel data -> registered staff -> Staff_ID */
function findLecturer($pdo, $course) {
    $r = ['staff_id' => null, 'staff_name' => '', 'excel_name' => '', 'error' => ''];

    try {
        $sCols = $pdo->query('SHOW COLUMNS FROM academic_staff_subject')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $r['error'] = 'The academic_staff_subject table was not found. Upload the Excel file first.';
        return $r;
    }
    $sCourse  = findCol($sCols, '/course|code|subject/i');
    $sStaffId = findCol($sCols, '/staff.?id/i');
    $sName    = findCol($sCols, '/name|lectur/i');
    if ($sCourse === null) { $r['error'] = 'academic_staff_subject has no course code column.'; return $r; }

    $q = $pdo->prepare("SELECT * FROM academic_staff_subject WHERE `$sCourse` = ?");
    $q->execute([$course]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $r['error'] = "No lecturer was found for $course in the uploaded Excel data.";
        return $r;
    }

    $staff = [];
    try {
        $aCols = $pdo->query('SHOW COLUMNS FROM academic_staff')->fetchAll(PDO::FETCH_COLUMN);
        $aId   = findCol($aCols, '/id/i', $aCols[0]);
        $aName = findCol($aCols, '/name/i');
        if ($aName !== null) $staff = $pdo->query("SELECT `$aId` AS sid, `$aName` AS sname FROM academic_staff")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $staff = []; }

    foreach ($rows as $row) {
        $excelName = $sName !== null ? trim((string)($row[$sName] ?? '')) : '';
        if ($r['excel_name'] === '') $r['excel_name'] = $excelName;

        if ($sStaffId !== null && trim((string)($row[$sStaffId] ?? '')) !== '') {
            $r['staff_id']   = trim($row[$sStaffId]);
            $r['staff_name'] = $excelName;
            return $r;
        }
        foreach ($staff as $s) {
            if (sameName($excelName, $s['sname'])) {
                $r['staff_id']   = $s['sid'];
                $r['staff_name'] = $s['sname'];
                $r['excel_name'] = $excelName;
                return $r;
            }
        }
    }
    $r['error'] = 'The lecturer "' . $r['excel_name'] . '" of ' . $course
                . ' is not registered as staff yet, so Staff ID will be empty until the lecturer uses "Register as Staff" with the same name.';
    return $r;
}

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $pdo->exec('CREATE TABLE IF NOT EXISTS student_course (
        Registration_Number VARCHAR(50) NOT NULL,
        Course_Code         VARCHAR(50) NOT NULL,
        PRIMARY KEY (Registration_Number, Course_Code)
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS attendance (
        Attendance_ID       VARCHAR(3)  NOT NULL,
        Registration_Number VARCHAR(50) NOT NULL,
        Course_Code         VARCHAR(50) NOT NULL,
        Week_No             INT         NOT NULL,
        Staff_ID            VARCHAR(50) NULL,
        Attendance_Status   TINYINT(1)  NOT NULL DEFAULT 0,
        PRIMARY KEY (Registration_Number, Course_Code, Week_No),
        KEY idx_attendance_id (Attendance_ID)
    )');

    $attInfo = $pdo->query('SHOW COLUMNS FROM attendance')->fetchAll(PDO::FETCH_ASSOC);
    $attCols = array_column($attInfo, 'Field');
    $col = [
        'id'     => findCol($attCols, '/^attendance.?id$/i'),
        'reg'    => findCol($attCols, '/reg/i'),
        'course' => findCol($attCols, '/course/i'),
        'week'   => findCol($attCols, '/week/i'),
        'staff'  => findCol($attCols, '/staff/i'),
        'status' => findCol($attCols, '/status/i'),
    ];
    $idIsAuto = false;
    foreach ($attInfo as $ci) {
        if ($col['id'] !== null && $ci['Field'] === $col['id'] && stripos($ci['Extra'], 'auto_increment') !== false) $idIsAuto = true;
    }
    foreach (['reg', 'course', 'week', 'status'] as $need) {
        if ($col[$need] === null) {
            throw new PDOException('The attendance table needs a column for "' . $need . '". Columns found: ' . implode(', ', $attCols));
        }
    }

    try {
        fixAttendanceKeys($pdo, $col);
    } catch (PDOException $e) {
        $keyWarning = 'Could not fix the attendance table keys automatically: ' . $e->getMessage()
                    . ' | Run in phpMyAdmin: SHOW CREATE TABLE attendance; and send the result.';
    }

    /* ---------- COURSE table ---------- */
    $courseCols = $pdo->query('SHOW COLUMNS FROM course')->fetchAll(PDO::FETCH_COLUMN);
    $cCode = findCol($courseCols, '/code/i', $courseCols[0]);
    $cName = findCol($courseCols, '/name/i');
    $sql = "SELECT `$cCode` AS code" . ($cName ? ", `$cName` AS cname" : ", '' AS cname") . " FROM course";
    $courses = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    if ($course !== '' && !in_array($course, array_column($courses, 'code'), true)) $course = '';

    /* ---------- WEEK table ---------- */
    $weekCols = $pdo->query('SHOW COLUMNS FROM week')->fetchAll(PDO::FETCH_COLUMN);
    $wNo   = findCol($weekCols, '/no|number/i', $weekCols[0]);
    $wDate = findCol($weekCols, '/date/i');
    $sql = "SELECT `$wNo` AS wno" . ($wDate ? ", `$wDate` AS wdate" : ", '' AS wdate") . " FROM week ORDER BY `$wNo`";
    $weeks = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Weeks already marked for the selected course are hidden from the dropdown
    if ($course !== '') {
        $q = $pdo->prepare("SELECT DISTINCT `{$col['week']}` FROM attendance WHERE `{$col['course']}` = ?");
        $q->execute([$course]);
        $markedWeeks = array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
    $allWeekCount = count($weeks);
    $weeks = array_values(array_filter($weeks, function ($w) use ($markedWeeks) {
        return !in_array((string)$w['wno'], $markedWeeks, true);
    }));

    foreach ($weeks as $w) { if ((string)$w['wno'] === $week) $weekRow = $w; }
    if ($weekRow === null) $week = '';

    if ($course !== '') {
        // Students of the course come from the attends table (Registration_Number, CourseCode)
        $st = $pdo->prepare(
            'SELECT s.Registration_Number, s.Initial_Name
             FROM student s
             INNER JOIN attends a ON a.Registration_Number = s.Registration_Number
             WHERE a.CourseCode = ?'
        );
        $st->execute([$course]);
        $students = $st->fetchAll(PDO::FETCH_ASSOC);

        $lecturer = findLecturer($pdo, $course);
    }

    /* ---------- SAVE every student's attendance for the selected week ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
        if ($course === '' || $week === '') {
            $message = 'Please select a course and a week first (this week may already be marked).';
            $type    = 'error';
        } elseif (!$students) {
            $message = 'There are no students to save.';
            $type    = 'error';
        } else {
            $staffId  = $lecturer['staff_id'] ?? null;
            $regs     = $_POST['regs'] ?? [];
            $statuses = $_POST['status'] ?? [];
            $valid    = array_column($students, 'Registration_Number');
            $weekNo   = (int)$week;

            $find = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE `{$col['reg']}` = ? AND `{$col['course']}` = ? AND `{$col['week']}` = ?");

            $set = ["`{$col['status']}` = ?"];
            if ($col['staff'] !== null) $set[] = "`{$col['staff']}` = ?";
            if ($col['id'] !== null && !$idIsAuto) $set[] = "`{$col['id']}` = ?";
            $upd = $pdo->prepare('UPDATE attendance SET ' . implode(', ', $set) .
                " WHERE `{$col['reg']}` = ? AND `{$col['course']}` = ? AND `{$col['week']}` = ?");

            $iCols = ["`{$col['reg']}`", "`{$col['course']}`", "`{$col['week']}`", "`{$col['status']}`"];
            if ($col['staff'] !== null) $iCols[] = "`{$col['staff']}`";
            if ($col['id'] !== null && !$idIsAuto) $iCols[] = "`{$col['id']}`";
            $ins = $pdo->prepare('INSERT INTO attendance (' . implode(', ', $iCols) . ') VALUES (' . implode(', ', array_fill(0, count($iCols), '?')) . ')');

            // class table: Class_Id, Type, CourseCode
            $cls      = makeClass($course, $weekNo);
            $clsCheck = $pdo->prepare('SELECT COUNT(*) FROM `class` WHERE `Class_Id` = ?');
            $clsIns   = $pdo->prepare('INSERT INTO `class` (`Class_Id`, `Type`, `CourseCode`) VALUES (?, ?, ?)');

            $count = 0;
            $pdo->beginTransaction();
            foreach ($regs as $i => $reg) {
                if (!in_array($reg, $valid, true)) continue;
                $status = (isset($statuses[$i]) && $statuses[$i] === '1') ? 1 : 0;
                $attId  = substr($reg, -3);

                $find->execute([$reg, $course, $weekNo]);
                if ($find->fetchColumn() > 0) {
                    $p = [$status];
                    if ($col['staff'] !== null) $p[] = $staffId;
                    if ($col['id'] !== null && !$idIsAuto) $p[] = $attId;
                    array_push($p, $reg, $course, $weekNo);
                    $upd->execute($p);
                } else {
                    $p = [$reg, $course, $weekNo, $status];
                    if ($col['staff'] !== null) $p[] = $staffId;
                    if ($col['id'] !== null && !$idIsAuto) $p[] = $attId;
                    $ins->execute($p);
                }
                $count++;
            }

            // save the class of this course and week (only once)
            $clsCheck->execute([$cls['id']]);
            if ($clsCheck->fetchColumn() == 0) {
                $clsIns->execute([$cls['id'], $cls['type'], $course]);
            }

            $pdo->commit();

            // Reload without the week that was just marked
            header('Location: markattendance.php?course=' . urlencode($course) . '&done=' . $weekNo . '&n=' . $count . '&class=' . urlencode($cls['id']));
            exit;
        }
    }

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $message = 'Database error: ' . $e->getMessage();
    $type    = 'error';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance</title>
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
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th { background: #1e5bb8; color: #fff; text-align: left; padding: 11px 14px; font-size: 14px; }
        td { padding: 10px 14px; border-bottom: 1px solid #e3e6ec; font-size: 15px; }
        tr:hover td { background: #f5f8fd; }
        td.mark label { margin-right: 18px; cursor: pointer; }
        .present { color: #2a7a2a; font-weight: bold; }
        .absent  { color: #b02a2a; font-weight: bold; }
        .actions { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .save-btn { background: #1e5bb8; color: #fff; border: none; padding: 12px 36px; border-radius: 6px; font-size: 16px; cursor: pointer; }
        .save-btn:hover { background: #164a96; }
        .small-btn { background: #eef2fa; color: #1e5bb8; border: 1px solid #1e5bb8; padding: 10px 18px; border-radius: 6px; font-size: 14px; cursor: pointer; }
        .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; }
        .msg.success { background: #e6f4e6; color: #2a7a2a; }
        .msg.error   { background: #fdeaea; color: #b02a2a; }
        .msg.warn    { background: #fff6e0; color: #8a5a00; }
        .note { color: #666; font-size: 14px; }
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
            <?php endif; ?>
            <a href="markattendance.php" class="btn active">Mark Attendance</a>
            <a href="viewattendance.php" class="btn">View Attendance</a>
            <a href="document.php" class="btn">Add Document</a>
        </div>
        <div class="logout">
            <a href="<?= $isAdmin ? 'admin.php' : 'dashboard.php' ?>?logout=1" class="btn">Log Out</a>
        </div>
    </div>

    <div class="right">
        <h2>Mark Attendance</h2>

        <?php if ($message !== ''): ?>
            <div class="msg <?= $type ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($keyWarning !== ''): ?>
            <div class="msg error"><?= htmlspecialchars($keyWarning) ?></div>
        <?php endif; ?>

        <form method="get" action="markattendance.php" class="filters">
            <div>
                <label for="course">Course</label>
                <select name="course" id="course" onchange="this.form.submit()">
                    <option value="">-- Select course --</option>
                    <?php foreach ($courses as $c): ?>
                        <option value="<?= htmlspecialchars($c['code']) ?>" <?= $c['code'] === $course ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['code'] . ($c['cname'] ? ' - ' . $c['cname'] : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="week">Week Number and Date (marked weeks are hidden)</label>
                <select name="week" id="week" onchange="this.form.submit()">
                    <option value="">-- Select week --</option>
                    <?php foreach ($weeks as $w): ?>
                        <option value="<?= htmlspecialchars($w['wno']) ?>" <?= (string)$w['wno'] === $week ? 'selected' : '' ?>>
                            Week <?= htmlspecialchars($w['wno']) ?><?= $w['wdate'] ? ' - ' . htmlspecialchars($w['wdate']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($lecturer): ?>
            <div class="infobox">
                <div><span>Course</span><b><?= htmlspecialchars($course) ?></b></div>
                <div><span>Lecturer</span><b><?= htmlspecialchars($lecturer['staff_name'] !== '' ? $lecturer['staff_name'] : ($lecturer['excel_name'] ?: '-')) ?></b></div>
                <div><span>Staff ID</span><b><?= htmlspecialchars($lecturer['staff_id'] ?? '-') ?></b></div>
                <div><span>Weeks marked</span><b><?= count($markedWeeks) ?> of <?= $allWeekCount ?></b></div>
                <?php if ($week !== ''): $clsPreview = makeClass($course, (int)$week); ?>
                    <div><span>Class ID</span><b><?= htmlspecialchars($clsPreview['id']) ?></b></div>
                <?php endif; ?>
            </div>
            <?php if ($lecturer['staff_id'] === null && $lecturer['error'] !== ''): ?>
                <div class="msg warn"><?= htmlspecialchars($lecturer['error']) ?></div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$courses): ?>
            <p class="note">The course table is empty. Upload the Excel file first from the Upload File page.</p>
        <?php elseif ($course === ''): ?>
            <p class="note">Select a course to see the students.</p>
        <?php elseif ($week === ''): ?>
            <p class="note"><?= $weeks ? 'Select a week to mark attendance.' : 'All weeks of ' . htmlspecialchars($course) . ' have been marked.' ?></p>
        <?php elseif (!$students): ?>
            <p class="note">No students are linked to <?= htmlspecialchars($course) ?>. Upload the student Excel file again so the students are linked to their course.</p>
        <?php else: ?>
            <form method="post" action="markattendance.php?course=<?= urlencode($course) ?>&week=<?= urlencode($week) ?>">
                <input type="hidden" name="course" value="<?= htmlspecialchars($course) ?>">
                <input type="hidden" name="week"   value="<?= htmlspecialchars($week) ?>">

                <table>
                    <thead>
                        <tr>
                            <th style="width:60px">No</th>
                            <th>Student Name</th>
                            <th>Registration Number</th>
                            <th style="width:200px">Attendance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $i => $s):
                            $reg  = $s['Registration_Number'];
                            $mark = $saved[$reg] ?? 1;
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= htmlspecialchars($s['Initial_Name'] ?? '') ?></td>
                            <td>
                                <?= htmlspecialchars($reg) ?>
                                <input type="hidden" name="regs[<?= $i ?>]" value="<?= htmlspecialchars($reg) ?>">
                            </td>
                            <td class="mark">
                                <label class="present"><input type="radio" name="status[<?= $i ?>]" value="1" <?= $mark === 1 ? 'checked' : '' ?>> 1</label>
                                <label class="absent"><input type="radio" name="status[<?= $i ?>]" value="0" <?= $mark === 0 ? 'checked' : '' ?>> 0</label>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="actions">
                    <button type="submit" name="save" value="1" class="save-btn">Save Week <?= htmlspecialchars($week) ?></button>
                    <button type="button" class="small-btn" onclick="setAll('1')">All 1</button>
                    <button type="button" class="small-btn" onclick="setAll('0')">All 0</button>
                    <span class="note"><?= count($students) ?> student(s) &nbsp;|&nbsp; 1 = Present, 0 = Absent</span>
                </div>
            </form>

            <script>
            function setAll(v) {
                document.querySelectorAll('input[type="radio"][value="' + v + '"]').forEach(function (r) { r.checked = true; });
            }
            </script>
        <?php endif; ?>
    </div>

</body>
</html>