<?php
session_start();

// ---- Database settings ----
$dbHost = 'localhost';
$dbName = '2026_secondsem';
$dbUser = 'root';
$dbPass = '';

$error   = '';
$email   = '';
$indexNo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email           = trim($_POST['email'] ?? '');
    $indexNo         = trim($_POST['index_no'] ?? '');
    $password        = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($email === '' || $indexNo === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
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

            // 1. Check the Index No against student.Registration_Number
            //    (capital or simple letters both work: ICT/2024/001 = ict/2024/001)
            $find = $pdo->prepare(
                'SELECT Registration_Number, User_Email
                   FROM student
                  WHERE UPPER(TRIM(Registration_Number)) = UPPER(?)
                  LIMIT 1'
            );
            $find->execute([$indexNo]);
            $student = $find->fetch(PDO::FETCH_ASSOC);

            if (!$student) {
                $error = 'Index No does not match any student record. Please enter it like ICT/2024/001 or ASB/2024/001.';
            } elseif (!empty($student['User_Email']) && strcasecmp($student['User_Email'], $email) !== 0) {
                $error = 'This Index No is already registered with another email.';
            } else {
                // 2. Check if the email already exists
                $check = $pdo->prepare('SELECT COUNT(*) FROM user WHERE User_Email = ?');
                $check->execute([$email]);

                if ($check->fetchColumn() > 0) {
                    $error = 'This email is already registered.';
                } else {
                    // 3. Save the login (email + hashed password) and the student's email
                    $hash = password_hash($password, PASSWORD_DEFAULT);

                    $pdo->beginTransaction();

                    $insert = $pdo->prepare('INSERT INTO user (User_Email, Password) VALUES (?, ?)');
                    $insert->execute([$email, $hash]);

                    // use the Registration_Number exactly as saved in the student table
                    $update = $pdo->prepare('UPDATE student SET User_Email = ? WHERE Registration_Number = ?');
                    $update->execute([$email, $student['Registration_Number']]);

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
    <title>Register</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="login-card">
        <h2>Register</h2>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="register.php" autocomplete="on">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="Email"
                   value="<?= htmlspecialchars($email) ?>" required>

            <label for="index_no">Index No</label>
            <input type="text" id="index_no" name="index_no" placeholder="e.g. ICT/2024/001"
                   value="<?= htmlspecialchars($indexNo) ?>" required>

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