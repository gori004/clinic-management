<?php
require 'db.php';
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
include 'inc/header.php';

$role = $_SESSION['user']['role'];
// doctor sees their patients; admin could see all
if ($role === 'doctor') {
    $stmt = $mysqli->prepare("SELECT id FROM doctors WHERE user_id=?"); $stmt->bind_param('i', $_SESSION['user']['id']); $stmt->execute(); $did = $stmt->get_result()->fetch_row()[0];
    // simplistic: patients assigned via patients.doctor_id not implemented - we show all patients for demo
}
$patients = $mysqli->query("SELECT p.id,u.name,u.email,p.phone,p.dob,p.gender FROM patients p JOIN users u ON u.id=p.user_id")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card p-3">
  <h4>Patients</h4>
  <table class="table table-sm">
    <thead><tr><th>Name</th><th>Contact</th><th>DOB</th><th>Gender</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($patients as $p): ?>
      <tr>
        <td><?php echo htmlspecialchars($p['name']); ?></td>
        <td><?php echo htmlspecialchars($p['phone']) . ' / ' . htmlspecialchars($p['email']); ?></td>
        <td><?php echo htmlspecialchars($p['dob']); ?></td>
        <td><?php echo htmlspecialchars($p['gender']); ?></td>
        <td><a class="btn btn-sm btn-primary" href="records.php?patient_id=<?php echo $p['id']; ?>">Records</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include 'inc/footer.php'; ?>
