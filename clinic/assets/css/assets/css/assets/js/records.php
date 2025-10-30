<?php
require 'db.php';
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
include 'inc/header.php';

$role = $_SESSION['user']['role'];
$patient_id = intval($_GET['patient_id'] ?? 0);

if ($role === 'patient') {
    // patient views own records
    $stmt = $mysqli->prepare("SELECT id FROM patients WHERE user_id=?"); $stmt->bind_param('i', $_SESSION['user']['id']); $stmt->execute(); $patient_id = $stmt->get_result()->fetch_row()[0];
}

if (!$patient_id) {
    echo "<div class='card p-3'>No patient selected.</div>";
    include 'inc/footer.php'; exit;
}

// add record (doctors only)
if ($role === 'doctor' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $note = trim($_POST['note'] ?? ''); $presc = trim($_POST['prescription'] ?? '');
    $stmt = $mysqli->prepare("SELECT id FROM doctors WHERE user_id=?"); $stmt->bind_param('i', $_SESSION['user']['id']); $stmt->execute(); $doctor_id = $stmt->get_result()->fetch_row()[0];
    $ins = $mysqli->prepare("INSERT INTO records (patient_id,doctor_id,note,prescription) VALUES (?,?,?,?)");
    $ins->bind_param('iiss', $patient_id, $doctor_id, $note, $presc);
    $ins->execute();
    echo "<div class='alert alert-success'>Record saved</div>";
}

$patient = $mysqli->query("SELECT u.name,u.email,p.* FROM patients p JOIN users u ON u.id=p.user_id WHERE p.id=".$patient_id)->fetch_assoc();
$records = $mysqli->query("SELECT r.*, u.name as doctor_name FROM records r LEFT JOIN doctors d ON d.id=r.doctor_id LEFT JOIN users u ON u.id=d.user_id WHERE r.patient_id={$patient_id} ORDER BY r.created_at DESC")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card p-3">
  <h4>Records for <?php echo htmlspecialchars($patient['name']); ?></h4>
  <p class="muted">Email: <?php echo htmlspecialchars($patient['email']); ?></p>
  <?php if ($role === 'doctor'): ?>
    <form method="post" class="mb-3">
      <div class="mb-2"><textarea name="note" class="form-control" placeholder="Clinical note"></textarea></div>
      <div class="mb-2"><textarea name="prescription" class="form-control" placeholder="Prescription"></textarea></div>
      <button class="btn btn-primary" name="add">Save Record</button>
    </form>
  <?php endif; ?>

  <h5>History</h5>
  <?php if (!$records) echo "<div class='muted'>No records</div>"; else { ?>
    <table class="table table-sm">
      <thead><tr><th>Date</th><th>Doctor</th><th>Note</th><th>Prescription</th></tr></thead>
      <tbody>
      <?php foreach($records as $r): ?>
        <tr>
          <td><?php echo date('Y-m-d H:i', strtotime($r['created_at'])); ?></td>
          <td><?php echo htmlspecialchars($r['doctor_name'] ?? '-'); ?></td>
          <td><?php echo nl2br(htmlspecialchars($r['note'])); ?></td>
          <td><?php echo nl2br(htmlspecialchars($r['prescription'])); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php } ?>
</div>
<?php include 'inc/footer.php'; ?>

