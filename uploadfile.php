<?php
session_start();

// Super users: admin and HOD (a staff member whose Position is HOD gets the 'admin' role at login)
$superUsers = ['admin@as.rjt.ac.lk', 'hod@as.rjt.ac.lk'];

if (!isset($_SESSION['email'])) { header('Location: index.php'); exit; }

$isSuper = in_array(strtolower($_SESSION['email']), $superUsers, true)
        || ($_SESSION['role'] ?? '') === 'admin';
if (!$isSuper) {
    header('Location: ' . (($_SESSION['role'] ?? '') === 'student' ? 'student.php' : 'dashboard.php'));
    exit;
}

$username = $_SESSION['email'];

// Security token for the upload forms
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ---- Database settings ----
$dbHost = 'localhost';
$dbName = '2026_secondsem';
$dbUser = 'root';
$dbPass = '';

/* Labels for student and week data (case, spaces, _ - . / ignored) */
$fieldMap = [
    'registrationnumber' => ['student', 'reg'],
    'registrationno'     => ['student', 'reg'],
    'regno'              => ['student', 'reg'],
    'indexnumber'        => ['student', 'index'],
    'indexno'            => ['student', 'index'],
    'initialname'        => ['student', 'name'],
    'namewithinitials'   => ['student', 'name'],
    'gender'             => ['student', 'gender'],
    'sno'                => ['student', 'serial'],
    'serialnumber'       => ['student', 'serial'],
    'serialno'           => ['student', 'serial'],
    'useremail'          => ['student', 'email'],
    'email'              => ['student', 'email'],
    'weekno'             => ['week', 'no'],
    'weeknumber'         => ['week', 'no'],
    'week'               => ['week', 'no'],
    'weekdate'           => ['week', 'date'],
    'date'               => ['week', 'date'],
    'weekstatus'         => ['week', 'status'],
    'status'             => ['week', 'status'],
];

/* Header labels of the COURSE DETAILS file: Lecture_ID, CourseCode, Course_Name */
$courseHeaderMap = [
    'lectureid'        => 'lec',
    'lecturerid'       => 'lec',
    'lecturestaffid'   => 'lec',
    'lecturerstaffid'  => 'lec',
    'lecid'            => 'lec',
    'staffid'          => 'lec',
    'coursecode'       => 'code',
    'courseid'         => 'code',
    'modulecode'       => 'code',
    'coursename'       => 'name',
    'modulename'       => 'name',
];

function norm($s) {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '', (string)$s));
}

/*
 * "ICT 4203 - Intelligent Systems"  ->  ['code' => 'ICT 4203', 'name' => 'Intelligent Systems']
 * Also works for "ICT4203", "ICT 4203-Intelligent Systems", "Course: ICT 4203 : Intelligent Systems"
 */
function parseCourseTitle($v) {
    $v = trim($v);
    if (preg_match('/(?<![A-Za-z0-9])([A-Za-z]{2,5})\s*[-_]?\s*(\d{4})([A-Za-z]?)(?![A-Za-z0-9])\s*(.*)$/su', $v, $m)) {
        $name = trim($m[4]);
        $name = trim(preg_replace('/^[\s\-\x{2013}\x{2014}:|,.()]+/u', '', $name));
        $name = trim(preg_replace('/[\s\-\x{2013}\x{2014}:|,]+$/u', '', $name));
        return ['code' => strtoupper($m[1]) . ' ' . $m[2] . strtoupper($m[3]), 'name' => $name];
    }
    return null;
}

/*
 * Course code cell of the COURSE DETAILS file.
 * A code that ends with the letter P is a PRACTICAL course and keeps the P:
 *   "ICT 1305P", "ICT1305P", "ict 1305 p", "ICT 1305 (P)", "ICT 1305-P"  ->  code "ICT 1305P", practical
 *   "ICT 1305"                                                            ->  code "ICT 1305",  theory
 */
function parseCourseCodeCell($v) {
    $v = trim($v);
    if (preg_match('/^([A-Za-z]{2,5})\s*[-_]?\s*(\d{4})(?:\s*[-_(]?\s*([A-Za-z])\s*\)?)?(?![A-Za-z0-9])/', $v, $m)) {
        $suffix = isset($m[3]) ? strtoupper($m[3]) : '';
        return ['code' => strtoupper($m[1]) . ' ' . $m[2] . $suffix, 'practical' => ($suffix === 'P')];
    }
    return null;
}

/* Look for the course title in the TOP rows of a sheet */
function findCourseOnSheet($grid) {
    ksort($grid);
    $rowsSeen = 0;
    foreach ($grid as $r => $cells) {
        if (++$rowsSeen > 20) break;
        ksort($cells);
        foreach ($cells as $c => $v) {
            $p = parseCourseTitle($v);
            if ($p) {
                $p['raw'] = $v;   // the whole cell text (used to find the word "Practical")
                // code in one cell, name in the next cell
                if ($p['name'] === '') {
                    $next = trim($cells[$c + 1] ?? '');
                    if ($next !== '' && !parseCourseTitle($next) && !is_numeric($next)) {
                        $p['name'] = $next;
                        $p['raw'] .= ' ' . $next;
                    }
                }
                return $p;
            }
        }
    }
    return null;
}

/* Does this text say the course is a practical?  "Practical", "Pract", "(P)" or just "P" */
function isPracticalText($s) {
    $s = trim((string)$s);
    if ($s === '') return false;
    return (bool)preg_match('/\b(practicals?|pract|prac)\b|\(\s*p\s*\)/i', $s)
        || (bool)preg_match('/^\(?\s*p\s*\)?$/i', $s);
}

/*
 * Course of one sheet of the STUDENT file. The code comes from the title at the top of the sheet,
 * otherwise from the sheet tab name (the tabs at the bottom of Excel: "ICT 1305", "ICT 1305 Practical").
 * A practical sheet gets the letter P at the end of the code:
 *   tab "ICT 1305 Practical"  ->  ICT 1305P
 *   tab "ICT 1305"            ->  ICT 1305
 */
function courseOfSheet($grid, $sheetName) {
    $title = findCourseOnSheet($grid);
    $tab   = parseCourseTitle(str_replace('_', ' ', $sheetName));
    $base  = $title ?: $tab;
    if (!$base) return null;

    $code = $base['code'];
    $name = $base['name'];

    $practical = (bool)preg_match('/\d{4}P$/', $code);      // already written like ICT1305P
    if (!$practical) {
        $texts = [$sheetName];
        if ($tab)   $texts[] = $tab['name'];
        if ($title) { $texts[] = $title['name']; $texts[] = $title['raw'] ?? ''; }
        foreach ($texts as $t) {
            if (isPracticalText($t)) { $practical = true; break; }
        }
        if ($practical) $code .= 'P';
    }

    // a name that only says "Practical" is not a real course name
    if (isPracticalText($name) && preg_match('/^\(?\s*(practicals?|pract|prac|p)\s*\)?$/i', $name)) $name = '';

    return ['code' => $code, 'name' => $name];
}

function detectGender($name, $fallback = '') {
    $n = strtolower(ltrim($name));
    if (preg_match('/^(mrs|miss|ms)/', $n)) return 'Female';
    if (preg_match('/^mr/', $n))            return 'Male';
    return $fallback;
}

function looksLikeDate($v) {
    $v = trim((string)$v);
    if (is_numeric($v) && $v > 20000 && $v < 80000) return true;
    return (bool)preg_match('/^\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4}$/', $v);
}

function toDate($value) {
    $value = trim($value);
    if ($value === '') return null;
    if (is_numeric($value) && $value > 20000 && $value < 80000) {
        $d = new DateTime('1899-12-30');
        $d->modify('+' . (int)$value . ' days');
        return $d->format('Y-m-d');
    }
    $t = strtotime($value);
    return $t ? date('Y-m-d', $t) : null;
}

/* ---------- Read Excel (.xlsx): every sheet with its tab name and cells ---------- */
function colIndex($ref) {
    preg_match('/^[A-Z]+/', $ref, $m);
    $n = 0;
    foreach (str_split($m[0]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

function readXlsxSheets($path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new Exception('Cannot open the Excel file. Please upload a valid .xlsx file.');

    $shared = [];
    $ssXml  = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->xpath('//*[local-name()="si"]') as $si) {
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $t) $text .= (string)$t;
            $shared[] = $text;
        }
    }

    $rels = [];
    $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($relXml !== false) {
        $rx = simplexml_load_string($relXml);
        foreach ($rx->xpath('//*[local-name()="Relationship"]') as $rel) {
            $target = ltrim((string)$rel['Target'], '/');
            if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
            $rels[(string)$rel['Id']] = $target;
        }
    }

    $list = [];
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if ($wbXml !== false) {
        $wb  = simplexml_load_string($wbXml);
        $rNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        foreach ($wb->xpath('//*[local-name()="sheet"]') as $sh) {
            $rid  = (string)$sh->attributes($rNs)['id'];
            $file = $rels[$rid] ?? null;
            if ($file) $list[] = ['name' => (string)$sh['name'], 'file' => $file];
        }
    }
    if (!$list) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, 'xl/worksheets/') === 0 && substr($n, -4) === '.xml') $list[] = ['name' => '', 'file' => $n];
        }
    }

    $sheets = [];
    foreach ($list as $item) {
        $xml = $zip->getFromName($item['file']);
        if ($xml === false) continue;
        $sheet = simplexml_load_string($xml);
        $grid  = [];
        $auto  = 0;
        foreach ($sheet->xpath('//*[local-name()="row"]') as $row) {
            $auto++;
            $rn = (int)$row['r'] ?: $auto;
            foreach ($row->xpath('*[local-name()="c"]') as $c) {
                $idx  = colIndex((string)$c['r']);
                $type = (string)$c['t'];
                $val  = '';
                if ($type === 's') {
                    $v   = $c->xpath('*[local-name()="v"]');
                    $val = isset($v[0]) ? ($shared[(int)$v[0]] ?? '') : '';
                } elseif ($type === 'inlineStr') {
                    foreach ($c->xpath('.//*[local-name()="t"]') as $t) $val .= (string)$t;
                } else {
                    $v   = $c->xpath('*[local-name()="v"]');
                    $val = isset($v[0]) ? (string)$v[0] : '';
                }
                $val = trim($val);
                if ($val !== '') $grid[$rn][$idx] = $val;
            }
        }
        $sheets[] = ['name' => $item['name'], 'grid' => $grid];
    }
    $zip->close();
    return $sheets;
}

function readCsvSheet($path, $fileName) {
    $grid = [];
    $rn   = 0;
    if (($h = fopen($path, 'r')) !== false) {
        while (($line = fgetcsv($h)) !== false) {
            $rn++;
            foreach ($line as $i => $v) {
                $v = trim(preg_replace('/^\xEF\xBB\xBF/', '', $v));
                if ($v !== '') $grid[$rn][$i] = $v;
            }
        }
        fclose($h);
    }
    return [['name' => pathinfo($fileName, PATHINFO_FILENAME), 'grid' => $grid]];
}

/* Check the uploaded file (.xlsx / .csv) and return its sheets */
function loadUploadedSheets() {
    if (!isset($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) throw new Exception('Please choose an Excel file first.');
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload failed (error code ' . $_FILES['file']['error'] . '). Check upload_max_filesize in php.ini.');

    $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'csv'], true)) throw new Exception('Only .xlsx or .csv files are allowed. (Old .xls files: open in Excel and Save As .xlsx.)');
    if ($ext === 'xlsx' && !class_exists('ZipArchive')) throw new Exception('PHP zip extension is disabled. Enable extension=zip in php.ini and restart Apache.');

    return ($ext === 'xlsx')
        ? readXlsxSheets($_FILES['file']['tmp_name'])
        : readCsvSheet($_FILES['file']['tmp_name'], $_FILES['file']['name']);
}

/*
 * Students (table under headers) and weeks found in one sheet.
 * Every column header like "Week 1", "Week_2", "Week-3", "Wk 4" is one week.
 */
function extractRecords($grid, $fieldMap) {
    $out    = ['student' => [], 'week' => []];
    $cur    = ['week' => []];
    $weekRe = '/^(?:week|wk)[\s_\-\.]*0*(\d{1,3})$/i';

    $addWeek = function ($f, $val) use (&$out, &$cur) {
        if (isset($cur['week'][$f])) { $out['week'][] = $cur['week']; $cur['week'] = []; }
        $cur['week'][$f] = $val;
    };

    ksort($grid);
    foreach ($grid as $r => $rowCells) {
        ksort($rowCells);

        $labels = [];
        foreach ($rowCells as $c => $v) {
            $k = norm($v);
            if ($k !== '' && isset($fieldMap[$k])) $labels[$c] = $fieldMap[$k];
        }

        $headerCols = [];
        foreach ($rowCells as $c => $v) {
            if (isset($labels[$c])) {
                list($g, $f) = $labels[$c];
                $right = $rowCells[$c + 1] ?? '';
                if ($g === 'week' && $right !== '' && !isset($labels[$c + 1]) && !preg_match($weekRe, $right)) {
                    $addWeek($f, $right);
                } else {
                    $headerCols[$g][$c] = $f;
                }
                continue;
            }
            if (strpos($v, ':') !== false) {
                list($l, $val) = explode(':', $v, 2);
                $k = norm($l);
                if (isset($fieldMap[$k]) && $fieldMap[$k][0] === 'week' && trim($val) !== '') {
                    $addWeek($fieldMap[$k][1], trim($val));
                    continue;
                }
            }
            // Week column header: "Week 1", "Week_2", "Wk 3"
            if (preg_match($weekRe, $v, $m)) {
                $addWeek('no', $m[1]);
                // a date written just above or below the week header
                foreach ([$r - 1, $r + 1] as $rr) {
                    $dv = $grid[$rr][$c] ?? '';
                    if ($dv !== '' && looksLikeDate($dv)) { $cur['week']['date'] = $dv; break; }
                }
            }
        }

        foreach ($headerCols as $g => $cols) {
            $rr = $r + 1;
            while (isset($grid[$rr])) {
                $rec = []; $any = false; $isHdr = false;
                foreach ($cols as $c => $f) {
                    $val = $grid[$rr][$c] ?? '';
                    if ($val !== '') {
                        $any = true;
                        if (isset($fieldMap[norm($val)])) $isHdr = true;
                    }
                    $rec[$f] = $val;
                }
                if (!$any || $isHdr) break;
                $out[$g][] = $rec;
                $rr++;
            }
        }
    }
    if ($cur['week']) $out['week'][] = $cur['week'];
    return $out;
}

/* ---------- Handle upload ---------- */
$message  = ''; $type  = ''; $details  = [];   // student upload
$cMessage = ''; $cType = ''; $cDetails = [];   // course upload
$action   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = ($_POST['action'] ?? '') === 'courses' ? 'courses' : 'students';
    try {
        // Privileged access: admin / HOD only, with a valid security token
        if (!isset($_SESSION['email']) || !$isSuper) {
            throw new Exception('Access denied. Only the administrator or the HOD can upload data.');
        }
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new Exception('Security check failed. Please reload the page and try again.');
        }

        $sheets = loadUploadedSheets();

        $pdo = null;

        /* =====================================================================
         *  COURSE DETAILS  (Lecture_ID, CourseCode, Course_Name)
         *  -> course table (CourseCode, Course_Name, Lecturer_Staff_Id)
         * ===================================================================== */
        if ($action === 'courses') {
            $rows = [];
            foreach ($sheets as $sh) {
                $grid = $sh['grid'];
                ksort($grid);
                $cols = null;
                foreach ($grid as $r => $cells) {
                    if ($cols === null) {                       // find the header row
                        $found = [];
                        foreach ($cells as $c => $v) {
                            $k = norm($v);
                            if (isset($courseHeaderMap[$k])) $found[$courseHeaderMap[$k]] = $c;
                        }
                        if (isset($found['code'])) $cols = $found;
                        continue;
                    }
                    $raw = trim($cells[$cols['code']] ?? '');
                    if ($raw === '') {
                        if ($cells) $cDetails[] = "Row $r skipped: CourseCode is empty.";
                        continue;
                    }
                    $pc   = parseCourseCodeCell($raw);
                    $p    = parseCourseTitle($raw);
                    $code = $pc ? $pc['code'] : strtoupper($raw);        // "ICT4203" -> "ICT 4203", "ICT1305p" -> "ICT 1305P"
                    $practical = $pc ? $pc['practical'] : false;         // code ends with P = practical
                    $name = isset($cols['name']) ? trim($cells[$cols['name']] ?? '') : '';
                    if ($name === '' && $p && strlen($p['name']) > 1) $name = $p['name'];
                    $lec  = isset($cols['lec']) ? trim($cells[$cols['lec']] ?? '') : '';
                    if (preg_match('/^\d+\.0+$/', $lec)) $lec = (string)(int)$lec;   // 12.0 -> 12
                    $rows[] = ['code' => $code, 'name' => $name, 'lec' => $lec, 'practical' => $practical];
                }
                if ($cols === null) {
                    $cDetails[] = "Sheet '{$sh['name']}': header row with CourseCode not found.";
                }
            }
            if (!$rows) throw new Exception('No course rows found. The file needs the columns Lecture_ID, CourseCode and Course_Name.');

            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // If the course table has a type column (name contains "type", e.g. Course_Type),
            // it is filled with Practical (code ends with P) or Theory.
            $typeCol = null;
            foreach ($pdo->query('SHOW COLUMNS FROM course')->fetchAll(PDO::FETCH_COLUMN) as $cc) {
                if (preg_match('/type/i', $cc)) { $typeCol = $cc; break; }
            }

            $insCols = '`CourseCode`, `Course_Name`, `Lecturer_Staff_Id`';
            $insMarks = '?, ?, ?';
            // blank cells in the file keep the value already saved
            $updSet  = "`Course_Name` = COALESCE(NULLIF(?, ''), `Course_Name`), `Lecturer_Staff_Id` = COALESCE(NULLIF(?, ''), `Lecturer_Staff_Id`)";
            if ($typeCol !== null) {
                $insCols  .= ", `$typeCol`";
                $insMarks .= ', ?';
                $updSet   .= ", `$typeCol` = ?";
            }

            $stCheck  = $pdo->prepare('SELECT COUNT(*) FROM course WHERE `CourseCode` = ?');
            $stInsert = $pdo->prepare("INSERT INTO course ($insCols) VALUES ($insMarks)");
            $stUpdate = $pdo->prepare("UPDATE course SET $updSet WHERE `CourseCode` = ?");

            // academic_staff_subject table: staff_id, Subject
            $stSubCheck  = $pdo->prepare('SELECT COUNT(*) FROM academic_staff_subject WHERE `staff_id` = ? AND `Subject` = ?');
            $stSubInsert = $pdo->prepare('INSERT INTO academic_staff_subject (`staff_id`, `Subject`) VALUES (?, ?)');

            $new = 0; $upd = 0; $subNew = 0; $subOld = 0; $practicalCount = 0; $seen = [];
            $pdo->beginTransaction();
            foreach ($rows as $row) {
                if (isset($seen[$row['code']])) continue;
                $seen[$row['code']] = true;

                $typeVal = $row['practical'] ? 'Practical' : 'Theory';
                if ($row['practical']) $practicalCount++;

                $stCheck->execute([$row['code']]);
                if ($stCheck->fetchColumn() > 0) {
                    $params = [$row['name'], $row['lec']];
                    if ($typeCol !== null) $params[] = $typeVal;
                    $params[] = $row['code'];
                    $stUpdate->execute($params);
                    $upd++;
                } else {
                    $params = [$row['code'], $row['name'], $row['lec'] === '' ? null : $row['lec']];
                    if ($typeCol !== null) $params[] = $typeVal;
                    $stInsert->execute($params);
                    $new++;
                }

                // lecturer (staff_id) -> subject (course code)
                if ($row['lec'] === '') {
                    $cDetails[] = "Course {$row['code']}: no Lecture_ID, so it was not added to academic_staff_subject.";
                } else {
                    $stSubCheck->execute([$row['lec'], $row['code']]);
                    if ($stSubCheck->fetchColumn() > 0) {
                        $subOld++;
                    } else {
                        $stSubInsert->execute([$row['lec'], $row['code']]);
                        $subNew++;
                    }
                }
            }
            $pdo->commit();

            $cMessage = "Course upload complete. Courses: $new new, $upd updated ($practicalCount practical). "
                      . "Staff subjects: $subNew new, $subOld already existed.";
            $cType    = 'success';

        /* =====================================================================
         *  STUDENT DETAILS
         * ===================================================================== */
        } else {
            $students = []; $weeks = []; $courses = []; $links = [];

            foreach ($sheets as $sh) {
                $rec = extractRecords($sh['grid'], $fieldMap);

                // Course: title at the top of the sheet, else the sheet tab name; a practical sheet becomes ICT 1305P
                $course = courseOfSheet($sh['grid'], $sh['name']);

                if ($course) {
                    $code = $course['code'];
                    if (!isset($courses[$code]) || ($courses[$code] === '' && $course['name'] !== '')) {
                        $courses[$code] = $course['name'];
                    }
                    foreach ($rec['student'] as $stu) {
                        $r = trim($stu['reg'] ?? '');
                        if ($r !== '') $links[$r . '|' . $code] = [$r, $code];
                    }
                } else {
                    $first = '';
                    foreach ($sh['grid'] as $cells) { $first = implode(' | ', array_slice($cells, 0, 3)); break; }
                    $details[] = "Sheet '{$sh['name']}': no course code (like ICT 4203) found in the top rows. First row: $first";
                }

                $students = array_merge($students, $rec['student']);
                $weeks    = array_merge($weeks, $rec['week']);
            }

            if (!$students && !$courses && !$weeks) throw new Exception('No data was recognised in the file.');

            $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

            // Real column names of the course table (CourseCode, Course_Name ...)
            $courseCols = $pdo->query('SHOW COLUMNS FROM course')->fetchAll(PDO::FETCH_COLUMN);
            $codeCol = null; $nameCol = null;
            foreach ($courseCols as $cc) {
                if ($codeCol === null && preg_match('/code/i', $cc)) $codeCol = $cc;
                if ($nameCol === null && preg_match('/name/i', $cc)) $nameCol = $cc;
            }
            if ($codeCol === null) $codeCol = $courseCols[0];

            $stStuCheck  = $pdo->prepare('SELECT COUNT(*) FROM student WHERE Registration_Number = ?');
            $stStuInsert = $pdo->prepare('INSERT INTO student (Registration_Number, Index_Number, Initial_Name, Gender, Serial_Number, User_Email) VALUES (?, ?, ?, ?, ?, ?)');
            $stStuUpdate = $pdo->prepare('UPDATE student SET Index_Number = ?, Initial_Name = ?, Gender = ?, Serial_Number = ?, User_Email = ? WHERE Registration_Number = ?');

            $stCouCheck = $pdo->prepare("SELECT COUNT(*) FROM course WHERE `$codeCol` = ?");
            if ($nameCol !== null) $stCouInsert = $pdo->prepare("INSERT INTO course (`$codeCol`, `$nameCol`) VALUES (?, ?)");
            else                   $stCouInsert = $pdo->prepare("INSERT INTO course (`$codeCol`) VALUES (?)");

            $stWkCheck  = $pdo->prepare('SELECT COUNT(*) FROM week WHERE Week_No = ?');
            $stWkInsert = $pdo->prepare('INSERT INTO week (Week_No, Week_Date, Week_Status) VALUES (?, ?, ?)');
            // keep the old date/status when the Excel file does not give a new one
            $stWkUpdate = $pdo->prepare('UPDATE week SET Week_Date = COALESCE(?, Week_Date), Week_Status = COALESCE(?, Week_Status) WHERE Week_No = ?');

            // attends table: Registration_Number, CourseCode
            $stAttCheck  = $pdo->prepare('SELECT COUNT(*) FROM attends WHERE Registration_Number = ? AND CourseCode = ?');
            $stAttInsert = $pdo->prepare('INSERT INTO attends (Registration_Number, CourseCode) VALUES (?, ?)');

            $cnt = ['stu_new' => 0, 'stu_upd' => 0, 'cou_new' => 0, 'cou_old' => 0, 'wk_new' => 0, 'wk_upd' => 0, 'att_new' => 0, 'att_old' => 0, 'skipped' => 0];
            $pdo->beginTransaction();

            /* STUDENTS */
            $seen = [];
            foreach ($students as $s) {
                $reg    = trim($s['reg'] ?? '');
                $index  = trim($s['index'] ?? '');
                $name   = trim($s['name'] ?? '');
                $serial = trim($s['serial'] ?? '');
                $email  = trim($s['email'] ?? '');
                if (preg_match('/^\d+\.0+$/', $serial)) $serial = (string)(int)$serial;

                if ($reg === '') { $cnt['skipped']++; continue; }
                if (isset($seen[strtolower($reg)])) continue;
                $seen[strtolower($reg)] = true;

                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $cnt['skipped']++;
                    $details[] = "Student $reg skipped: invalid email ($email).";
                    continue;
                }
                $gender = detectGender($name, trim($s['gender'] ?? ''));

                $stStuCheck->execute([$reg]);
                if ($stStuCheck->fetchColumn() > 0) {
                    $stStuUpdate->execute([$index, $name, $gender, $serial, $email, $reg]);
                    $cnt['stu_upd']++;
                } else {
                    $stStuInsert->execute([$reg, $index, $name, $gender, $serial, $email]);
                    $cnt['stu_new']++;
                }
            }

            /* COURSES: only add a course that is not saved yet (details come from the course upload) */
            $courseText = [];
            foreach ($courses as $code => $cname) {
                $stCouCheck->execute([$code]);
                if ($stCouCheck->fetchColumn() > 0) {
                    $cnt['cou_old']++;
                } else {
                    if ($nameCol !== null) $stCouInsert->execute([$code, $cname]);
                    else                   $stCouInsert->execute([$code]);
                    $cnt['cou_new']++;
                }
                $courseText[] = $code;
            }

            /* ATTENDS (every student in a sheet -> that sheet's course code) */
            foreach ($links as $l) {
                $stAttCheck->execute([$l[0], $l[1]]);
                if ($stAttCheck->fetchColumn() > 0) {
                    $cnt['att_old']++;
                } else {
                    $stAttInsert->execute([$l[0], $l[1]]);
                    $cnt['att_new']++;
                }
            }

            /* WEEKS (each Week column header = one row in the week table) */
            $seen = [];
            $weekList = [];
            foreach ($weeks as $w) {
                $raw = trim($w['no'] ?? '');
                if ($raw === '' || !preg_match('/\d+/', $raw, $m)) continue;
                $no = (int)$m[0];
                if (isset($seen[$no])) continue;
                $seen[$no] = true;
                $weekList[] = $no;

                $date   = toDate($w['date'] ?? '');
                $status = trim($w['status'] ?? '');
                $status = ($status === '') ? null : $status;

                $stWkCheck->execute([$no]);
                if ($stWkCheck->fetchColumn() > 0) { $stWkUpdate->execute([$date, $status, $no]); $cnt['wk_upd']++; }
                else                               { $stWkInsert->execute([$no, $date, $status]); $cnt['wk_new']++; }
            }
            sort($weekList);

            $pdo->commit();

            $message = "Upload complete. Students: {$cnt['stu_new']} new, {$cnt['stu_upd']} updated. "
                     . "Courses: {$cnt['cou_new']} new, {$cnt['cou_old']} already existed [" . implode('; ', $courseText) . "]. "
                     . "Weeks: {$cnt['wk_new']} new, {$cnt['wk_upd']} updated" . ($weekList ? ' (' . implode(', ', $weekList) . ')' : '') . ". "
                     . "Attends: {$cnt['att_new']} new, {$cnt['att_old']} already existed. "
                     . "Skipped: {$cnt['skipped']}.";
            $type = 'success';
        }

    } catch (PDOException $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        if ($action === 'courses') { $cMessage = 'Database error: ' . $e->getMessage(); $cType = 'error'; }
        else                       { $message  = 'Database error: ' . $e->getMessage(); $type  = 'error'; }
    } catch (Exception $e) {
        if ($action === 'courses') { $cMessage = $e->getMessage(); $cType = 'error'; }
        else                       { $message  = $e->getMessage(); $type  = 'error'; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload File</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; height: 100vh; display: flex; background: #fff; }
        .left { width: 20%; height: 100vh; background: #1e5bb8; display: flex; flex-direction: column; padding: 25px 15px; }
        .username { color: #fff; font-size: 15px; font-weight: bold; text-align: center; padding-bottom: 20px; margin-bottom: 25px; border-bottom: 1px solid rgba(255,255,255,0.4); word-break: break-all; }
        .menu { display: flex; flex-direction: column; gap: 15px; }
        .btn { display: block; width: 100%; padding: 14px; background: #1e5bb8; color: #fff; border: none; border-radius: 6px; font-size: 16px; text-align: center; text-decoration: none; cursor: pointer; transition: background 0.2s; }
        .btn:hover, .btn.active { background: #164a96; }
        .logout { margin-top: auto; }
        .right { width: 80%; height: 100vh; background: #fff; display: flex; flex-direction: column; align-items: center; gap: 24px; overflow-y: auto; padding: 30px 20px; }
        .upload-box { width: 100%; max-width: 640px; padding: 30px 35px; border: 1px solid #d5d9e0; border-radius: 10px; text-align: center; }
        .upload-box h2 { color: #1e5bb8; margin-bottom: 8px; }
        .hint { color: #666; font-size: 13px; margin-bottom: 22px; line-height: 1.6; }
        input[type="file"] { display: block; width: 100%; padding: 12px; border: 2px dashed #1e5bb8; border-radius: 6px; background: #f5f8fd; font-size: 15px; margin-bottom: 20px; cursor: pointer; }
        input[type="file"]::file-selector-button { background: #1e5bb8; color: #fff; border: none; padding: 9px 18px; border-radius: 5px; margin-right: 12px; font-size: 15px; cursor: pointer; }
        .upload-btn { background: #1e5bb8; color: #fff; border: none; padding: 12px 40px; border-radius: 6px; font-size: 16px; cursor: pointer; }
        .upload-btn:hover { background: #164a96; }
        .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 18px; font-size: 14px; text-align: left; }
        .msg.success { background: #e6f4e6; color: #2a7a2a; }
        .msg.error   { background: #fdeaea; color: #b02a2a; }
        .details { text-align: left; font-size: 13px; color: #b02a2a; background: #fff7f7; border: 1px solid #f3c9c9; border-radius: 6px; padding: 10px 14px 10px 30px; margin-bottom: 18px; max-height: 150px; overflow-y: auto; }
        @media (max-width: 700px) {
            body { flex-direction: column; height: auto; }
            .left, .right { width: 100%; height: auto; }
            .logout { margin-top: 25px; }
        }
    </style>
</head>
<body>
    <div class="left">
        <div class="username"><?= htmlspecialchars($username) ?></div>
        <div class="menu">
            <a href="uploadfile.php" class="btn active">Upload File</a>
            <a href="markattendance.php" class="btn">Mark Attendance</a>
            <a href="viewattendance.php" class="btn">View Attendance</a>
            <a href="document.php" class="btn">Add Document</a>
        </div>
        <div class="logout">
            <a href="admin.php?logout=1" class="btn">Log Out</a>
        </div>
    </div>

    <div class="right">

        <!-- ===== 1. Student details ===== -->
        <div class="upload-box">
            <h2>Upload Student Details</h2>
            <p class="hint">
                Course: title at the top of each sheet or the sheet tab name, e.g. <b>ICT 4203</b>. A <b>Practical</b> sheet (e.g. tab "ICT 1305 Practical") is saved as <b>ICT 1305P</b><br>
                Students: Registration_Number, Index_Number, Initial_Name, Gender, S No, User_Email &nbsp;|&nbsp; Weeks: column headers Week 1, Week 2 ...
            </p>

            <?php if ($message !== ''): ?>
                <div class="msg <?= $type ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <?php if ($details): ?>
                <ul class="details">
                    <?php foreach (array_slice($details, 0, 50) as $d): ?>
                        <li><?= htmlspecialchars($d) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="uploadfile.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="students">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="file" name="file" accept=".xlsx,.csv" required>
                <button type="submit" class="upload-btn">Upload Students</button>
            </form>
        </div>

        <!-- ===== 2. Course details ===== -->
        <div class="upload-box">
            <h2>Upload Course Details</h2>
            <p class="hint">
                Excel columns: <b>Lecture_ID, CourseCode, Course_Name</b><br>
                Saved to the course table (CourseCode, Course_Name, Lecturer_Staff_Id). A course code ending with <b>P</b> (e.g. ICT 1305P) is a practical course. Administrator and HOD only.
            </p>

            <?php if ($cMessage !== ''): ?>
                <div class="msg <?= $cType ?>"><?= htmlspecialchars($cMessage) ?></div>
            <?php endif; ?>

            <?php if ($cDetails): ?>
                <ul class="details">
                    <?php foreach (array_slice($cDetails, 0, 50) as $d): ?>
                        <li><?= htmlspecialchars($d) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <form method="post" action="uploadfile.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="courses">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="file" name="file" accept=".xlsx,.csv" required>
                <button type="submit" class="upload-btn">Upload Courses</button>
            </form>
        </div>

    </div>
</body>
</html>