<?php
require 'db.php';
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') { header('Location: index.php'); exit; }
include 'inc/header.php';

$err=''; $ok='';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $spec = trim($_POST['spec'] ?? '');
    if ($name && $email) {
        $pw = password_hash('Doctor@123', PASSWORD_DEFAULT);
        $stmt = $mysqli->prepare("INSERT INTO users (name,email,password,role) VALUES (?,?,?, 'doctor')");
        $stmt->bind_param('sss',$name,$email,$pw);
        if ($stmt->execute()) {
            $uid = $stmt->insert_id;
            $mysqli->prepare("INSERT INTO doctors (user_id,specialization) VALUES (?,?)")->bind_param('is',$uid,$spec)->execute();
            $ok = "Doctor created (default password: Doctor@123)";
        } else { $err = "Error creating doctor (email exists?)"; }
    } else $err = 'Name and email required';
}

$doctors = $mysqli->query("SELECT d.id,u.name,u.email,d.specialization FROM doctors d JOIN users u ON u.id=d.user_id")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card p-3">
  <h4>Manage Doctors</h4>
  <?php if($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>
  <form method="post" class="mb-3">
    <div class="row">
      <div class="col"><input name="name" class="form-control" placeholder="Name" required></div>
      <div class="col"><input name="email" class="form-control" placeholder="Email" required></div>
      <div class="col"><input name="spec" class="form-control" placeholder="Specialization"></div>
      <div class="col-auto"><button class="btn btn-primary" name="add">Add</button></div>
    </div>
  </form>
  <table class="table table-sm">
    <thead><tr><th>Name</th><th>Email</th><th>Spec</th></tr></thead>
    <tbody>
    <?php foreach($doctors as $d): ?>
      <tr><td><?php echo htmlspecialchars($d['name']); ?></td><td><?php echo htmlspecialchars($d['email']); ?></td><td><?php echo htmlspecialchars($d['specialization']); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include 'inc/footer.php'; ?>
