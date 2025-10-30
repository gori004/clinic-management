<?php
require 'db.php';
session_start();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($name && $email && $password) {
        // check exists
        $s = $mysqli->prepare("SELECT id FROM users WHERE email=?");
        $s->bind_param('s',$email); $s->execute();
        if ($s->get_result()->fetch_assoc()) $err = 'Email already registered';
        else {
            $pw = password_hash($password, PASSWORD_DEFAULT);
            $ins = $mysqli->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?, 'patient')");
            $ins->bind_param('sss',$name,$email,$pw); $ins->execute();
            $uid = $ins->insert_id;
            $mysqli->prepare("INSERT INTO patients (user_id,phone) VALUES (?,?)")->bind_param('is',$uid,$phone = '')->execute();
            header('Location: login.php'); exit;
        }
    } else $err = 'Complete all fields';
}
include 'inc/header.php';
?>
<div class="card p-4">
  <h3>Register (Patient)</h3>
  <?php if($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <form method="post">
    <div class="mb-3"><label>Name</label><input class="form-control" name="name" required></div>
    <div class="mb-3"><label>Email</label><input class="form-control" name="email" type="email" required></div>
    <div class="mb-3"><label>Password</label><input class="form-control" name="password" type="password" required></div>
    <button class="btn btn-primary">Create Account</button>
  </form>
</div>
<?php include 'inc/footer.php'; ?>
