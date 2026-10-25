<?php
session_start();
date_default_timezone_set('Asia/Colombo');   // same date on the local and the online server

if (!isset($_SESSION['email'])) { header('Location: index.php'); exit; }
$userEmail = $_SESSION['email'];

// Security token for the form
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

// ---- Database settings ----
$dbHost = 'localhost';
$dbName = '2026_secondsem';
$dbUser = 'root';
$dbPass = '';

// Document type shown in the list => letter used at the end of the Document ID
$documentTypes = ['Medical' => 'M', 'Excuse Letter' => 'E'];

// These positions can upload documents for ANY course
$hodPositions = ['HOD', 'HOD CLERK'];

$error   = '';
$success = '';
$course  = '';
$week    = '';
$docType = '';
$regNo   = '';

$staff   = null;
$courses = [];   // [['code' => 'ICT 1305', 'name' => '...'], ...]
$weeks   = [];

// Message kept after the redirect (so a refresh does not upload again)
if (!empty($_SESSION['flash'])) {
    $success = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Who is logged in? (staff_member.User_Email -> staff_Id, Position)
    $st = $pdo->prepare('SELECT staff_Id, Name, Position FROM staff_member WHERE User_Email = ? LIMIT 1');
    $st->execute([$userEmail]);
    $staff = $st->fetch(PDO::FETCH_ASSOC);

    if (!$staff) {
        $error = 'Only staff members can upload documents.';
    } else {
        $isHod = in_array(strtoupper(trim($staff['Position'])), $hodPositions, true);

        if ($isHod) {
            // HOD / HOD Clerk: every course
            $rows = $pdo->query('SELECT CourseCode AS code, Course_Name AS name FROM course ORDER BY CourseCode')
                        ->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Other lecturers: only the courses saved for their staff_id
            $q = $pdo->prepare(
                'SELECT DISTINCT s.Subject AS code, c.Course_Name AS name
                   FROM academic_staff_subject s
                   LEFT JOIN course c ON c.CourseCode = s.Subject
                  WHERE s.staff_id = ?
                  ORDER BY s.Subject'
            );
            $q->execute([$staff['staff_Id']]);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        }
        $courses = $rows;

        $weeks = $pdo->query('SELECT Week_No FROM week ORDER BY Week_No')->fetchAll(PDO::FETCH_COLUMN);

        if (!$courses) {
            $error = 'No courses are assigned to your Staff ID.';
        } elseif (!$weeks) {
            $error = 'No weeks found. Please upload the student details first.';
        }
    }

    // ---------- Upload ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $staff && $courses && $weeks) {
        $course  = trim($_POST['course'] ?? '');
        $week    = trim($_POST['week'] ?? '');
        $docType = trim($_POST['document_type'] ?? '');
        $regNo   = trim($_POST['registration_number'] ?? '');

        $allowedCodes = array_column($courses, 'code');

        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            $error = 'Security check failed. Please reload the page and try again.';
        } elseif ($course === '' || $week === '' || $docType === '' || $regNo === '') {
            $error = 'Please fill in all fields.';
        } elseif (!in_array($course, $allowedCodes, true)) {
            $error = 'You are not allowed to upload documents for this course.';
        } elseif (!in_array((int)$week, array_map('intval', $weeks), true)) {
            $error = 'Please select a valid week.';
        } elseif (!isset($documentTypes[$docType])) {
            $error = 'Please select a valid document type.';
        } else {
            // The student must exist (capital or simple letters both work)
            $find = $pdo->prepare('SELECT Registration_Number FROM student WHERE UPPER(TRIM(Registration_Number)) = UPPER(?) LIMIT 1');
            $find->execute([$regNo]);
            $realReg = $find->fetchColumn();

            if ($realReg === false) {
                $error = 'Registration number does not match any student record. Please enter it like ICT/2024/035.';
            } else {
                $realReg = trim($realReg);

                // Document ID = course number + last 3 digits of registration number + week number + type letter
                //   ICT 1305 + ICT/2024/035 + Week 2 + Excuse Letter  ->  13050352E
                preg_match('/\d+/', $course, $cm);
                preg_match('/(\d+)\D*$/', $realReg, $rm);
                if (!$cm || !$rm) {
                    $error = 'Could not create the Document ID from the course code and registration number.';
                } else {
                    $courseNo = $cm[0];
                    $last3    = substr(str_pad($rm[1], 3, '0', STR_PAD_LEFT), -3);
                    $documentId = $courseNo . $last3 . (int)$week . $documentTypes[$docType];

                    $dup = $pdo->prepare('SELECT COUNT(*) FROM document WHERE Document_Id = ?');
                    $dup->execute([$documentId]);

                    if ($dup->fetchColumn() > 0) {
                        $error = "This document is already uploaded (Document ID $documentId).";
                    } else {
                        $submissionDate = date('Y-m-d');   // server date

                        $pdo->beginTransaction();

                        // document table
                        $ins = $pdo->prepare('INSERT INTO document (Document_Id, Document_Type, Submission_Date, Registration_Number) VALUES (?, ?, ?, ?)');
                        $ins->execute([$documentId, $docType, $submissionDate, $realReg]);

                        // document_approval table
                        $app = $pdo->prepare('INSERT INTO document_approval (Document_Id, Staff_Id, Approve_Status) VALUES (?, ?, ?)');
                        $app->execute([$documentId, $staff['staff_Id'], 'Approval']);

                        $pdo->commit();

                        $_SESSION['flash'] = "Document uploaded successfully. Document ID: $documentId";
                        header('Location: document.php');
                        exit;
                    }
                }
            }
        }
    }
} catch (PDOException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $error = 'Database error. Please try again.';
}

// after a successful upload the form is hidden until the "New +" button is clicked
$showForm = $staff && $courses && $weeks && $success === '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Document Details</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* drop down lists look like the other input boxes */
        .login-card select {
            width: 100%;
            padding: 14px 16px;
            margin-bottom: 18px;
            border: 1px solid #999;
            border-radius: 14px;
            font-size: 17px;
            background: #fff;
            color: #222;
        }
        .login-card .success {
            background: #e6f4e6;
            color: #2a7a2a;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <a href="admin.php" title="Cancel" aria-label="Cancel"
           style="float:right; font-size:30px; line-height:1; color:#555; text-decoration:none; font-weight:bold;">&times;</a>

        <h2 style="color:#000;">Upload Document Details</h2>

        <?php if ($success !== ''): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
            <div class="actions" style="display:flex; justify-content:center; margin-top:18px;">
                <a href="document.php"
                   style="display:inline-block; padding:12px 34px; background:#1e5bb8; color:#fff; text-decoration:none; border-radius:10px; font-size:16px; font-weight:bold; letter-spacing:1px;">New +</a>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($showForm): ?>
        <form method="post" action="document.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">

            <label for="course">Select Course</label>
            <select id="course" name="course" required>
                <option value="">Select Course</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= htmlspecialchars($c['code']) ?>" <?= $course === $c['code'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['code'] . (($c['name'] ?? '') !== '' ? ' - ' . $c['name'] : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="week">Select Week</label>
            <select id="week" name="week" required>
                <option value="">Select Week</option>
                <?php foreach ($weeks as $w): ?>
                    <option value="<?= (int)$w ?>" <?= $week !== '' && (int)$week === (int)$w ? 'selected' : '' ?>>
                        Week <?= (int)$w ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="document_type">Document Type</label>
            <select id="document_type" name="document_type" required>
                <option value="">Select Document Type</option>
                <?php foreach (array_keys($documentTypes) as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>" <?= $docType === $t ? 'selected' : '' ?>>
                        <?= htmlspecialchars($t) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="registration_number">Registration Number</label>
            <input type="text" id="registration_number" name="registration_number" placeholder="e.g. ICT/2024/035"
                   value="<?= htmlspecialchars($regNo) ?>" required>

            <div class="actions" style="display:flex; justify-content:center;">
                <button type="submit" class="btn-login">Upload</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</body>
</html>