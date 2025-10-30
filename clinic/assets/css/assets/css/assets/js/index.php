<?php
require 'db.php';
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
include 'inc/header.php';

// counts
$totPatients = $mysqli->query("SELECT COUNT(*) AS c FROM patients")->fetch_assoc()['c'];
$totDoctors = $mysqli->query("SELECT COUNT(*) AS c FROM doctors")->fetch_assoc()['c'];
$upcoming = $mysqli->query("SELECT COUNT(*) AS c FROM appointments WHERE scheduled_date >= CURDATE()")->fetch_assoc()['c'];
?>
<div class="row g-3">
  <div class="col-12 col-md-8">
    <div class="card p-3 mb-3">
      <h4>Welcome, <?php echo htmlspecialchars($_SESSION['user']['name']); ?></h4>
      <p class="muted">Role: <?php echo htmlspecialchars($_SESSION['user']['role']); ?></p>
      <div class="d-flex d-flex-responsive gap-3">
        <div class="card p-3 flex-fill"><h5>Patients</h5><strong><?php echo $totPatients; ?></strong></div>
        <div class="card p-3 flex-fill"><h5>Doctors</h5><strong><?php echo $totDoctors; ?></strong></div>
        <div class="card p-3 flex-fill"><h5>Upcoming Appointments</h5><strong><?php echo $upcoming; ?></strong></div>
      </div>
    </div>

    <div class="card p-3">
      <h5>Recent Appointments</h5>
      <table class="table table-sm table-borderless">
        <thead><tr><th>When</th><th>Patient</th><th>Doctor</th><th>Status</th></tr></thead>
        <tbody>
<?php
$sql = "SELECT a.scheduled_date,a.scheduled_time, p.id AS pid, u_p.name AS patient_name, u_d.name AS doctor_name, a.status
        FROM appointments a
        JOIN patients p ON p.id=a.patient_id
        JOIN users u_p ON u_p.id=p.user_id
        JOIN doctors d ON d.id=a.doctor_id
        JOIN users u_d ON u_d.id=d.user_id
        ORDER BY a.scheduled_date DESC, a.scheduled_time DESC LIMIT 8";
$res = $mysqli->query($sql);
while ($row = $res->fetch_assoc()) {
    echo "<tr><td>" . htmlspecialchars($row['scheduled_date'] . ' ' . $row['scheduled_time']) . "</td><td>" . htmlspecialchars($row['patient_name']) . "</td><td>" . htmlspecialchars($row['doctor_name'])."</td><td>" . htmlspecialchars($row['status']) . "</td></tr>";
}
?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-12 col-md-4">
    <div class="card p-3 mb-3">
      <h5>Quick Actions</h5>
      <div class="d-grid gap-2">
        <a class="btn btn-primary" href="appointments.php">Manage Appointments</a>
        <?php if ($_SESSION['user']['role'] === 'admin'): ?>
          <a class="btn btn-primary" href="doctors.php">Manage Doctors</a>
        <?php endif; ?>
        <?php if ($_SESSION['user']['role'] === 'doctor'): ?>
          <a class="btn btn-primary" href="patients.php">My Patients</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="card p-3">
      <h5>Notifications</h5>
      <p class="muted">No new notifications</p>
    </div>
  </div>
</div>

<?php include 'inc/footer.php'; ?>
