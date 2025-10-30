<?php
if (session_status() === PHP_SESSION_NONE) session_start();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Smart Clinic Management</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/clinic/assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
  <div class="container">
    <a class="navbar-brand" href="/clinic/index.php">Smart Clinic</a>
    <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav ms-auto">
        <?php if (!empty($_SESSION['user'])): ?>
          <li class="nav-item"><a class="nav-link" href="/clinic/index.php">Dashboard</a></li>
          <li class="nav-item"><a class="nav-link" href="/clinic/appointments.php">Appointments</a></li>
          <?php if ($_SESSION['user']['role'] === 'admin'): ?>
            <li class="nav-item"><a class="nav-link" href="/clinic/doctors.php">Doctors</a></li>
          <?php endif; ?>
          <?php if ($_SESSION['user']['role'] === 'doctor'): ?>
            <li class="nav-item"><a class="nav-link" href="/clinic/patients.php">Patients</a></li>
          <?php endif; ?>
          <li class="nav-item"><a class="nav-link" href="/clinic/logout.php">Logout</a></li>
        <?php else: ?>
          <li class="nav-item"><a class="nav-link" href="/clinic/login.php">Login</a></li>
          <li class="nav-item"><a class="nav-link" href="/clinic/register.php">Register</a></li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<div class="container">
