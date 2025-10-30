<?php
require 'db.php';
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
include 'inc/header.php';

$role = $_SESSION['user']['role'];
$err=''; $ok='';

if ($role === 'patient' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book'])) {
    // patient booking
    $doctor_id = intval($_POST['doctor_id'] ?? 0);
    $datetime = $_POST['datetime'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    if (!$doctor_id || !$datetime) $err = "Select doctor and datetime";
    else {
        $ts = strtotime($datetime);
        if (!$ts) $err = "Invalid datetime";
        else {
            // get patient id
            $stmt = $mysqli->prepare("SELECT id FROM patients WHERE user_id=?"); $stmt->bind_param('i', $_SESSION['user']['id']); $stmt->execute(); $patient_id = $stmt->get_result()->fetch_row()[0];
            $date = date('Y-m-d', $ts); $time = date('H:i:s', $ts);

            // check conflict
            $stmt = $mysqli->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND scheduled_date=? AND scheduled_time=? AND status='Scheduled'");
            $stmt->bind_param('iss', $doctor_id, $date, $time); $stmt->execute(); $cnt = $stmt->get_result()->fetch_row()[0];
            if ($cnt > 0) $err = "Doctor not available at that time";
            else {
                $ins = $mysqli->prepare("INSERT INTO appointments (patient_id,doctor_id,scheduled_date,scheduled_time,reason) VALUES (?,?,?,?,?)");
                $ins->bind_param('iisss', $patient_id, $doctor_id, $date, $time, $reason);
                if ($ins->execute()) $ok = "Appointment booked";
            }
        }
    }
}

$doctors = $mysqli->query("SELECT d.id,u.name FROM doctors d JOIN users u ON u.id=d.user_id")->fetch_all(MYSQLI_ASSOC);
?>
<div class="card p-3">
  <h4>Appointments</h4>
  <?php if($err): ?><div class="alert alert-danger"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if($ok): ?><div class="alert alert-success"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

  <?php if ($role === 'patient'): ?>
    <form method="post" class="mb-3">
      <div class="row g-2">
        <div class="col"><select name="doctor_id" class="form-control"><?php foreach($doctors as $d) echo "<option value='{$d['id']}'>".htmlspecialchars($d['name'])."</option>"; ?></select></div>
        <div class="col"><input type="datetime-local" name="datetime" class="form-control" required></div>
      </div>
      <div class="mb-2"><input name="reason" class="form-control" placeholder="Reason"></div>
      <button class="btn btn-primary" name="book">Book Appointment</button>
    </form>
  <?php endif; ?>

  <h5>All Appointments</h5>
  <table class="table table-sm">
    <thead><tr><th>Date</th><th>Time</th><th>Patient</th><th>Doctor</th><th>Status</th></tr></thead>
    <tbody>
    <?php
    if ($role === 'patient') {
        $stmt = $mysqli->prepare("SELECT a.scheduled_date,a.scheduled_time,a.status,(SELECT u.name FROM users u JOIN doctors d ON d.user_id=u.id WHERE d.id=a.doctor_id) as doctor_name FROM appointments a WHERE a.patient_id=(SELECT id FROM patients WHERE user_id=?) ORDER BY a.scheduled_date DESC");
        $stmt->bind_param('i', $_SESSION['user']['id']); $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            echo "<tr><td>{$r['scheduled_date']}</td><td>{$r['scheduled_time']}</td><td>".htmlspecialchars($_SESSION['user']['name'])."</td><td>".htmlspecialchars($r['doctor_name'])."</td><td>".htmlspecialchars($r['status'])."</td></tr>";
        }
    } elseif ($role === 'doctor') {
        $stmt = $mysqli->prepare("SELECT a.scheduled_date,a.scheduled_time,a.status,(SELECT u.name FROM users u JOIN patients p ON p.user_id=u.id WHERE p.id=a.patient_id) as patient_name FROM appointments a WHERE a.doctor_id=(SELECT id FROM doctors WHERE user_id=?) ORDER BY a.scheduled_date DESC");
        $stmt->bind_param('i', $_SESSION['user']['id']); $stmt->execute(); $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            echo "<tr><td>{$r['scheduled_date']}</td><td>{$r['scheduled_time']}</td><td>".htmlspecialchars($r['patient_name'])."</td><td>".htmlspecialchars($_SESSION['user']['name'])."</td><td>".htmlspecialchars($r['status'])."</td></tr>";
        }
    } else {
        $res = $mysqli->query("SELECT a.scheduled_date,a.scheduled_time,a.status, (SELECT u.name FROM users u JOIN patients p ON p.user_id=u.id WHERE p.id=a.patient_id) as patient_name, (SELECT u.name FROM users u JOIN doctors d ON d.user_id=u.id WHERE d.id=a.doctor_id) as doctor_name FROM appointments a ORDER BY a.scheduled_date DESC");
        while ($r = $res->fetch_assoc()) {
            echo "<tr><td>{$r['scheduled_date']}</td><td>{$r['scheduled_time']}</td><td>".htmlspecialchars($r['patient_name'])."</td><td>".htmlspecialchars($r['doctor_name'])."</td><td>".htmlspecialchars($r['status'])."</td></tr>";
        }
    }
    ?>
    </tbody>
  </table>
</div>
<?php include 'inc/footer.php'; ?>
