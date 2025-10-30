<?php
require 'db.php';
session_start();
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($email && $password) {
        $stmt = $mysqli->prepare("SELECT id,name,email,password,role FROM users WHERE email=? LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        if ($res && password_verify($password, $res['password'])) {
            $_SESSION['user'] = ['id'=>$res['id'],'name'=>$res['name'],'email'=>$res['email'],'role'=>$res['role']];
            header('Location: index.php'); exit;
        } else $err = 'Invalid credentials';
    } else $err = 'Enter email and password';
}
include 'inc/header.php';
?>
<div class="card p-4">
  <h3>Login</h3>
  <?php if($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <form method="post">
    <div class="mb-3"><label>Email</label><input class="form-control" name="email" required></div>
    <div class="mb-3"><label>Password</label><input type="password" class="form-control" name="password" required></div>
    <button class="btn btn-primary">Login</button>
  </form>
</div>
<?php include 'inc/footer.php'; ?>
