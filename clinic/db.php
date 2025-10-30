<?php
// db.php
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';        // set if you have a password
$DB_NAME = 'clinic_db';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    die("DB connect error: " . $mysqli->connect_error);
}
$mysqli->set_charset("utf8mb4");



