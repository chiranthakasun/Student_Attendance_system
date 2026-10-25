<?php
session_start();

// ---- Database settings ----
$dbHost = 'localhost';
$dbName = '2026_secondsem';
$dbUser = 'root';
$dbPass = '';

// Values of the Position drop down list
$positions = ['Dean', 'HOD', 'Visiting Lecturer', 'Internal Lecturer', 'Demonstrator'];

$error    = '';
$email    = '';
$staffId  = '';
$name     = '';
$position = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email           = trim($_POST['email'] ?? '');
    $staffId         = trim($_POST['staff_id'] ?? '');
    $name            = trim($_POST['name'] ?? '');
    $position        = trim($_POST['position'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($email === '' || $staffId === '' || $name === '' || $position === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($position, $positions, true)) {
        $error = 'Please select a valid position.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // 1. Staff ID (like LEC033) must exist in course.Lecturer_Staff_Id (capital or simple letters)
            $find = $pdo->prepare(
                'SELECT Lecturer_Staff_Id
                   FROM course
                  WHERE UPPER(TRIM(Lecturer_Staff_Id)) = UPPER(?)
                  LIMIT 1'
            );
            $find->execute([$staffId]);
            $realStaffId = $find->fetchColumn();

            if ($realStaffId === false) {
                $error = 'Staff ID does not match any lecturer in the course records. Please enter it like LEC033.';
            } else {
                $realStaffId = trim($realStaffId);

                // 2. Staff ID must not be registered already
                $checkStaff = $pdo->prepare('SELECT COUNT(*) FROM staff_member WHERE UPPER(TRIM(staff_Id)) = UPPER(?)');
                $checkStaff->execute([$realStaffId]);

                // 3. Email must not be registered already
                $checkEmail = $pdo->prepare('SELECT COUNT(*) FROM user WHERE User_Email = ?');
                $checkEmail->execute([$email]);

                if ($checkStaff->fetchColumn() > 0) {
                    $error = 'This Staff ID is already registered.';
                } elseif ($checkEmail->fetchColumn() > 0) {
                    $error = 'This email is already registered.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $pdo->beginTransaction();

                    // user table: User_Email, Password
                    $insUser = $pdo->prepare('INSERT INTO user (User_Email, Password) VALUES (?, ?)');
                    $insUser->execute([$email, $hash]);

                    // staff_member table: staff_Id, Name, Position, User_Email
                    $insMember = $pdo->prepare('INSERT INTO staff_member (staff_Id, Name, Position, User_Email) VALUES (?, ?, ?, ?)');
                    $insMember->execute([$realStaffId, $name, $position, $email]);

                    // academic_staff table: Staff_Id (only if not there already)
                    $checkAcademic = $pdo->prepare('SELECT COUNT(*) FROM academic_staff WHERE Staff_Id = ?');
                    $checkAcademic->execute([$realStaffId]);
                    if ($checkAcademic->fetchColumn() == 0) {
                        $insAcademic = $pdo->prepare('INSERT INTO academic_staff (Staff_Id) VALUES (?)');
                        $insAcademic->execute([$realStaffId]);
                    }

                    $pdo->commit();

                    // Redirect to the login page after successful registration
                    header('Location: index.php?registered=1');
                    exit;
                }
            }
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            $error = 'Database connection error.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Staff</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        /* drop down list looks like the other input boxes */
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
    </style>
</head>
<body>
    <div class="login-card">
        <h2>Register Staff</h2>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="registerstaff.php" autocomplete="on">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="Email"
                   value="<?= htmlspecialchars($email) ?>" required>

            <label for="staff_id">Staff ID</label>
            <input type="text" id="staff_id" name="staff_id" placeholder="e.g. LEC033"
                   value="<?= htmlspecialchars($staffId) ?>" required>

            <label for="name">Name</label>
            <input type="text" id="name" name="name" placeholder="Name"
                   value="<?= htmlspecialchars($name) ?>" required>

            <label for="position">Position</label>
            <select id="position" name="position" required>
                <option value="">Select Position</option>
                <?php foreach ($positions as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $position === $p ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Password" required>

            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password"
                   placeholder="Confirm Password" required>

            <div class="actions" style="display:flex; justify-content:center;">
                <button type="submit" class="btn-login">Register</button>
            </div>
        </form>
    </div>
</body>
</html>