<?php
session_start();

// Super users: these emails always go to admin.php
$superUsers = ['admin@as.rjt.ac.lk', 'hod@as.rjt.ac.lk'];

function isSuperUser(string $email, array $superUsers): bool
{
    foreach ($superUsers as $s) {
        if (strcasecmp($email, $s) === 0) return true;
    }
    return false;
}

// Where each role goes after login
function redirectByRole(string $email, string $role, array $superUsers): void
{
    if ($role === 'admin' || isSuperUser($email, $superUsers)) {
        header('Location: admin.php');       // super user (admin / HOD)
    } elseif ($role === 'staff') {
        header('Location: dashboard.php');
    } else {
        header('Location: student.php');
    }
    exit;
}

// If already logged in, redirect by email / role
if (isset($_SESSION['email'])) {
    redirectByRole($_SESSION['email'], $_SESSION['role'] ?? 'student', $superUsers);
}

// ---- Database settings ----
$dbHost = 'localhost';
$dbName = '2026_secondsem';
$dbUser = 'root';
$dbPass = '';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        try {
            $pdo = new PDO(
                "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // 1) Check email + password in the "user" table
            $stmt = $pdo->prepare('SELECT User_Email, Password FROM user WHERE User_Email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            // Works for hashed passwords AND plain-text passwords
            $passMatch = $user && (
                password_verify($password, $user['Password'])
                || $user['Password'] === $password
            );

            if (!$passMatch) {
                $error = 'Email or password is incorrect.';
            } else {
                $userEmail = $user['User_Email'];
                $role = null;

                // 2) Is this user a staff member? Check the Position column.
                $stmt = $pdo->prepare('SELECT Position FROM staff_member WHERE user_email = ? LIMIT 1');
                $stmt->execute([$userEmail]);
                $staffRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($staffRow) {
                    // HOD is a super user with the same features as the admin
                    $role = (strtoupper(trim($staffRow['Position'] ?? '')) === 'HOD') ? 'admin' : 'staff';
                } else {
                    // 3) Is this user a student?
                    $stmt = $pdo->prepare('SELECT 1 FROM student WHERE user_email = ? LIMIT 1');
                    $stmt->execute([$userEmail]);
                    if ($stmt->fetchColumn()) {
                        $role = 'student';
                    }
                }

                // Super user emails (admin and HOD) always get admin rights
                if (isSuperUser($userEmail, $superUsers)) $role = 'admin';

                if ($role === null) {
                    $error = 'Your account is not registered as a student or staff member.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['email'] = $userEmail;
                    $_SESSION['role']  = $role;          // 'admin' (admin email or HOD), 'staff' or 'student'
                    redirectByRole($userEmail, $_SESSION['role'], $superUsers);
                }
            }
        } catch (PDOException $e) {
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
    <title>Sign In</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="login-card">
        <h2>SignIn to your account</h2>

        <?php if ($error !== ''): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="index.php" autocomplete="on">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="Email"
                   value="<?= htmlspecialchars($email) ?>" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Password" required>

            <div class="actions" style="margin-top:14px; display:flex; flex-direction:column; gap:8px; text-align:center;">
                <button type="submit" class="btn-login">Login</button><br>
                <a href="register.php" class="register-link">Register as Student</a>
                <a href="registerstaff.php" class="register-link" style="color:red;">Register as Staff</a>
            </div>
        </form>
    </div>
</body>
</html>