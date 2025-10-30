<?php
// index.php
// Smart Clinic Management System (single-file app)
// Requirements: PHP 7.4+, pdo_sqlite enabled

session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

// ---------- CONFIG ----------
define('DB_FILE', __DIR__ . '/clinic.sqlite');
define('SITE_TITLE', 'Smart Clinic Management System');

// ---------- DB helpers ----------
function getDb(){
    $init = !file_exists(DB_FILE);
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($init) initializeDb($pdo);
    return $pdo;
}

function initializeDb(PDO $pdo){
    $sqls = [
        // users: id, name, email, password, role (admin/doctor/patient), meta(json)
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL,
            meta TEXT,
            created_at INTEGER NOT NULL
        );",
        // doctors table (profile) (doctor is also a user with role=doctor)
        "CREATE TABLE doctors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            specialization TEXT,
            phone TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id)
        );",
        // patients table (profile) (patient is also a user with role=patient)
        "CREATE TABLE patients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            phone TEXT,
            dob TEXT,
            gender TEXT,
            notes TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id)
        );",
        // appointments: patient_id, doctor_id, datetime (timestamp), duration (minutes), status (booked,done,cancelled)
        "CREATE TABLE appointments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            doctor_id INTEGER NOT NULL,
            scheduled_at INTEGER NOT NULL,
            duration_min INTEGER NOT NULL DEFAULT 30,
            status TEXT NOT NULL DEFAULT 'booked',
            reason TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY(patient_id) REFERENCES patients(id),
            FOREIGN KEY(doctor_id) REFERENCES doctors(id)
        );",
        // medical records: patient_id, doctor_id, note, prescription, created_at
        "CREATE TABLE records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            patient_id INTEGER NOT NULL,
            doctor_id INTEGER,
            note TEXT,
            prescription TEXT,
            created_at INTEGER NOT NULL,
            FOREIGN KEY(patient_id) REFERENCES patients(id),
            FOREIGN KEY(doctor_id) REFERENCES doctors(id)
        );",
        // simple audit/log
        "CREATE TABLE logs (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, action TEXT, created_at INTEGER);"
    ];

    foreach($sqls as $s) $pdo->exec($s);

    // create default admin user
    $now = time();
    $pw = password_hash('Admin@123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name,email,password,role,meta,created_at) VALUES (?,?,?,?,?,?)");
    $stmt->execute(['Administrator','admin@example.com',$pw,'admin','{}',$now]);
}

// ---------- helpers ----------
function jsonErr($msg, $code=400){ http_response_code($code); echo json_encode(['success'=>false,'error'=>$msg]); exit; }
function ensurePost(){ if($_SERVER['REQUEST_METHOD'] !== 'POST') jsonErr('Invalid method',405); }
function currentUser(){ return $_SESSION['user'] ?? null; }
function requireLogin(){ if(!currentUser()) { header('Location: ?page=login'); exit; } }
function csrfToken(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function checkCsrf($t){ if(empty($t) || $t !== ($_SESSION['csrf'] ?? '')) jsonErr('Invalid CSRF token',403); }

// ---------- AJAX / API endpoints ----------
$action = $_GET['action'] ?? null;
if ($action) {
    header('Content-Type: application/json; charset=utf-8');
    $db = getDb();
    try {
        switch ($action) {
            // auth
            case 'login':
                ensurePost();
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                if (!$email || !$password) jsonErr('Email and password required');
                $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $u = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$u || !password_verify($password, $u['password'])) jsonErr('Invalid credentials',401);
                // build session
                $_SESSION['user'] = [
                    'id'=> $u['id'], 'name'=>$u['name'], 'email'=>$u['email'], 'role'=>$u['role']
                ];
                // if patient/doctor ensure profile exists
                if ($u['role'] === 'patient') {
                    $ps = $db->prepare("SELECT * FROM patients WHERE user_id=?"); $ps->execute([$u['id']]);
                    if (!$ps->fetch()) {
                        $db->prepare("INSERT INTO patients (user_id,phone,dob,gender,notes) VALUES (?,?,?,?,?)")
                           ->execute([$u['id'],'','','','']);
                    }
                } elseif ($u['role'] === 'doctor') {
                    $ps = $db->prepare("SELECT * FROM doctors WHERE user_id=?"); $ps->execute([$u['id']]);
                    if (!$ps->fetch()) {
                        $db->prepare("INSERT INTO doctors (user_id,specialization,phone) VALUES (?,?,?)")
                           ->execute([$u['id'],'','']);
                    }
                }
                echo json_encode(['success'=>true,'user'=>$_SESSION['user']]);
                break;

            case 'register':
                ensurePost();
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $phone = trim($_POST['phone'] ?? '');
                if (!$name || !$email || !$password) jsonErr('Missing registration fields');
                // check exists
                $s = $db->prepare("SELECT id FROM users WHERE email=?"); $s->execute([$email]);
                if ($s->fetch()) jsonErr('Email already registered');
                $pw = password_hash($password, PASSWORD_DEFAULT);
                $now = time();
                $db->prepare("INSERT INTO users (name,email,password,role,meta,created_at) VALUES (?,?,?,?,?,?)")
                   ->execute([$name,$email,$pw,'patient','{}',$now]);
                $uid = $db->lastInsertId();
                $db->prepare("INSERT INTO patients (user_id,phone,dob,gender,notes) VALUES (?,?,?,?,?)")
                   ->execute([$uid,$phone,'','','']);
                echo json_encode(['success'=>true]);
                break;

            // list favorites (doctors) - for simplicity show doctors list
            case 'doctors':
                $rows = $db->query("SELECT d.id, d.user_id, u.name, d.specialization FROM doctors d JOIN users u ON u.id=d.user_id")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success'=>true,'doctors'=>$rows]);
                break;

            // add doctor (admin)
            case 'doctor_add':
                ensurePost();
                $user = currentUser(); if (!$user || $user['role']!=='admin') jsonErr('Forbidden',403);
                $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $spec = trim($_POST['spec'] ?? '');
                if (!$name || !$email) jsonErr('Name and email required');
                // create user
                $pw = password_hash('Doctor@123', PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (name,email,password,role,meta,created_at) VALUES (?,?,?,?,?,?)");
                $stmt->execute([$name,$email,$pw,'doctor','{}',time()]);
                $uid = $db->lastInsertId();
                $db->prepare("INSERT INTO doctors (user_id,specialization,phone) VALUES (?,?,?)")->execute([$uid,$spec,'']);
                echo json_encode(['success'=>true,'default_password'=>'Doctor@123']);
                break;

            // schedule appointment (patient)
            case 'appointment_book':
                ensurePost();
                $user = currentUser(); if (!$user || $user['role']!=='patient') jsonErr('Only patients can book',403);
                $patientId = $db->prepare("SELECT id FROM patients WHERE user_id=?")->execute([$user['id']]) && $db->query("SELECT id FROM patients WHERE user_id=".$user['id'])->fetchColumn();
                // safer select
                $ps = $db->prepare("SELECT id FROM patients WHERE user_id=?"); $ps->execute([$user['id']]); $patientId = $ps->fetchColumn();
                $doctor_id = intval($_POST['doctor_id'] ?? 0);
                $datetime = trim($_POST['datetime'] ?? '');
                $duration = intval($_POST['duration'] ?? 30);
                $reason = trim($_POST['reason'] ?? '');
                if (!$doctor_id || !$datetime) jsonErr('Doctor and datetime required');
                $ts = strtotime($datetime);
                if (!$ts) jsonErr('Invalid datetime');
                // check conflicts: doctor has appointment overlapping
                $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND status='booked' AND ABS(scheduled_at - ?) < ?");
                $stmt->execute([$doctor_id, $ts, max(1,intval($duration*60))]);
                if ($stmt->fetchColumn() > 0) jsonErr('Doctor not available at that time');
                $db->prepare("INSERT INTO appointments (patient_id,doctor_id,scheduled_at,duration_min,reason,created_at) VALUES (?,?,?,?,?,?)")
                   ->execute([$patientId,$doctor_id,$ts,$duration,$reason,time()]);
                echo json_encode(['success'=>true]);
                break;

            // list appointments (role-aware)
            case 'appointments':
                $user = currentUser(); if (!$user) jsonErr('Login required',401);
                if ($user['role']==='patient') {
                    $ps = $db->prepare("SELECT p.id FROM patients p WHERE p.user_id=?"); $ps->execute([$user['id']]); $pid = $ps->fetchColumn();
                    $q = $db->prepare("SELECT a.*, u.name as doctor_name FROM appointments a JOIN doctors d ON d.id=a.doctor_id JOIN users u ON u.id=d.user_id WHERE a.patient_id=? ORDER BY scheduled_at DESC");
                    $q->execute([$pid]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success'=>true,'appointments'=>$rows]);
                } elseif ($user['role']==='doctor') {
                    // get doctor id
                    $ps = $db->prepare("SELECT id FROM doctors WHERE user_id=?"); $ps->execute([$user['id']]); $did = $ps->fetchColumn();
                    $q = $db->prepare("SELECT a.*, up.name as patient_name FROM appointments a JOIN patients p ON p.id=a.patient_id JOIN users up ON up.id=p.user_id WHERE a.doctor_id=? ORDER BY scheduled_at DESC");
                    $q->execute([$did]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success'=>true,'appointments'=>$rows]);
                } else {
                    // admin: all
                    $rows = $db->query("SELECT a.*, up.name as patient_name, ud.name as doctor_name FROM appointments a JOIN patients p ON p.id=a.patient_id JOIN users up ON up.id=p.user_id JOIN doctors d ON d.id=a.doctor_id JOIN users ud ON ud.id=d.user_id ORDER BY scheduled_at DESC")->fetchAll(PDO::FETCH_ASSOC);
                    echo json_encode(['success'=>true,'appointments'=>$rows]);
                }
                break;

            // doctor add record (notes/prescription)
            case 'record_add':
                ensurePost();
                $user = currentUser(); if (!$user || $user['role']!=='doctor') jsonErr('Only doctors can add records',403);
                $ps = $db->prepare("SELECT id FROM doctors WHERE user_id=?"); $ps->execute([$user['id']]); $did = $ps->fetchColumn();
                $patient_id = intval($_POST['patient_id'] ?? 0);
                $note = trim($_POST['note'] ?? '');
                $presc = trim($_POST['prescription'] ?? '');
                if (!$patient_id || (!$note && !$presc)) jsonErr('Provide note or prescription');
                $db->prepare("INSERT INTO records (patient_id,doctor_id,note,prescription,created_at) VALUES (?,?,?,?,?)")
                   ->execute([$patient_id,$did,$note,$presc,time()]);
                echo json_encode(['success'=>true]);
                break;

            // list patient records (patients or doctors)
            case 'records':
                $user = currentUser(); if (!$user) jsonErr('Login required',401);
                $patient_id = intval($_GET['patient_id'] ?? 0);
                if ($user['role']==='patient') {
                    $ps = $db->prepare("SELECT id FROM patients WHERE user_id=?"); $ps->execute([$user['id']]); $pid = $ps->fetchColumn();
                    $patient_id = $pid;
                } elseif ($user['role']==='doctor') {
                    // doctor can view any patient's records
                } else {
                    // admin can view
                }
                if (!$patient_id) jsonErr('patient_id required',400);
                $q = $db->prepare("SELECT r.*, u.name as doctor_name FROM records r LEFT JOIN doctors d ON d.id=r.doctor_id LEFT JOIN users u ON u.id=d.user_id WHERE r.patient_id=? ORDER BY created_at DESC");
                $q->execute([$patient_id]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success'=>true,'records'=>$rows]);
                break;

            // simple stats: counts
            case 'stats':
                $totalPatients = $db->query("SELECT COUNT(*) FROM patients")->fetchColumn();
                $totalDoctors = $db->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
                $upcoming = $db->query("SELECT COUNT(*) FROM appointments WHERE scheduled_at > ".time())->fetchColumn();
                echo json_encode(['success'=>true,'patients'=>$totalPatients,'doctors'=>$totalDoctors,'upcoming'=>$upcoming]);
                break;

            default:
                jsonErr('Unknown action',400);
        }
    } catch (Exception $e) {
        jsonErr($e->getMessage(), 500);
    }
    exit;
}

// ---------- Render pages (simple router by page param) ----------
$page = $_GET['page'] ?? 'home';
$user = $_SESSION['user'] ?? null;

// simple nav & header functions
function pageHeader($title = ''){
    echo "<!doctype html><html lang='en'><head><meta charset='utf-8'/><meta name='viewport' content='width=device-width,initial-scale=1'/>";
    echo "<title>" . htmlspecialchars(SITE_TITLE) . " - " . htmlspecialchars($title) . "</title>";
    echo "<style>
    /* minimal CSS for clarity - responsive */
    :root{--accent-from:#7c3aed;--accent-to:#4f46e5;--bg:#071225;--card:rgba(255,255,255,0.03);--text:#e6eef8;--muted:#9aa6b2}
    html,body{height:100%;margin:0;font-family:Inter,system-ui,-apple-system,'Segoe UI',Roboto,Arial;background:linear-gradient(180deg,var(--bg),#06121b);color:var(--text)}
    .wrap{max-width:1100px;margin:18px auto;padding:18px}
    header{display:flex;justify-content:space-between;align-items:center;gap:12px}
    .logo{display:flex;gap:10px;align-items:center}
    .brand{font-weight:700}
    nav a{color:var(--muted);margin-right:10px;text-decoration:none}
    .card{background:linear-gradient(180deg,var(--card),rgba(255,255,255,0.01));padding:14px;border-radius:12px;border:1px solid rgba(255,255,255,0.03)}
    form input,form select,textarea{width:100%;padding:8px;margin:6px 0;border-radius:8px;border:1px solid rgba(255,255,255,0.04);background:transparent;color:var(--text)}
    .btn{padding:8px 12px;border-radius:8px;border:0;background:linear-gradient(90deg,var(--accent-from),var(--accent-to));color:#012;cursor:pointer;font-weight:700}
    .btn.link{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--muted)}
    table{width:100%;border-collapse:collapse}
    table th,table td{padding:8px;border-bottom:1px solid rgba(255,255,255,0.02);text-align:left}
    .muted{color:var(--muted);font-size:0.95rem}
    @media(max-width:800px){ header{flex-direction:column;align-items:flex-start} }
    </style>";
    echo "</head><body><div class='wrap'><header><div class='logo'><div style='width:44px;height:44px;border-radius:8px;background:linear-gradient(135deg,var(--accent-from),var(--accent-to));display:flex;align-items:center;justify-content:center;font-weight:800;color:#021'>SC</div><div><div class='brand'>".htmlspecialchars(SITE_TITLE)."</div><div class='muted'>Smart Clinic Management</div></div></div>";
    echo "<nav>";
    if(isset($_SESSION['user'])) {
        echo "<a href='?page=dashboard'>Dashboard</a>";
        echo "<a href='?page=appointments'>Appointments</a>";
        if($_SESSION['user']['role'] === 'admin') echo "<a href='?page=doctors'>Doctors</a>";
        if($_SESSION['user']['role'] === 'doctor') echo "<a href='?page=patients'>Patients</a>";
        echo "<a href='?page=logout' class='muted'>Logout ({$_SESSION['user']['name']})</a>";
    } else {
        echo "<a href='?page=login'>Login</a> <a href='?page=register'>Register</a>";
    }
    echo "</nav></header><main style='margin-top:18px'><h2>".htmlspecialchars($title)."</h2>";
}

function pageFooter(){
    echo "</main><footer style='margin-top:18px' class='muted'>Built with PHP & SQLite • Demo app</footer></div></body></html>";
}

// ---------- Pages ----------

// LOGIN
if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim($_POST['email'] ?? ''); $password = $_POST['password'] ?? '';
        $db = getDb();
        $stmt = $db->prepare("SELECT * FROM users WHERE email=?"); $stmt->execute([$email]); $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u && password_verify($password, $u['password'])) {
            $_SESSION['user'] = ['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role']];
            header('Location: ?page=dashboard'); exit;
        } else {
            $error = "Invalid credentials";
        }
    }
    pageHeader('Login');
    echo "<div class='card' style='max-width:480px'><form method='post'><label>Email</label><input name='email' type='email' required><label>Password</label><input name='password' type='password' required><div style='margin-top:10px'><button class='btn'>Login</button> <a class='btn link' href='?page=register'>Register</a></div></form>";
    if (!empty($error)) echo "<p class='muted' style='color:#f88;margin-top:8px'>$error</p>";
    echo "</div>";
    pageFooter();
    exit;
}

// REGISTER (patient)
if ($page === 'register') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $password = $_POST['password'] ?? '';
        if (!$name || !$email || !$password) $regErr = "Please complete all fields";
        else {
            $db = getDb(); $s = $db->prepare("SELECT id FROM users WHERE email=?"); $s->execute([$email]);
            if ($s->fetch()) $regErr = "Email already registered";
            else {
                $pw = password_hash($password,PASSWORD_DEFAULT);
                $db->prepare("INSERT INTO users (name,email,password,role,meta,created_at) VALUES (?,?,?,?,?,?)")
                   ->execute([$name,$email,$pw,'patient','{}',time()]);
                $uid = $db->lastInsertId();
                $db->prepare("INSERT INTO patients (user_id,phone,dob,gender,notes) VALUES (?,?,?,?,?)")->execute([$uid,'','','','']);
                header('Location:?page=login'); exit;
            }
        }
    }
    pageHeader('Register');
    echo "<div class='card' style='max-width:540px'><form method='post'><label>Name</label><input name='name' required><label>Email</label><input name='email' type='email' required><label>Password</label><input name='password' type='password' required><div style='margin-top:10px'><button class='btn'>Create account</button> <a class='btn link' href='?page=login'>Back to login</a></div></form>";
    if (!empty($regErr)) echo "<p class='muted' style='color:#f88;margin-top:8px'>$regErr</p>";
    echo "</div>";
    pageFooter();
    exit;
}

// LOGOUT
if ($page === 'logout') { session_destroy(); header('Location:?page=login'); exit; }

// DASHBOARD
requireLogin();
$pageHeaderTitle = 'Dashboard';
pageHeader($pageHeaderTitle);

$db = getDb();
$user = $_SESSION['user'];
echo "<div class='card'><h3>Welcome, ".htmlspecialchars($user['name'])." — Role: ".htmlspecialchars($user['role'])."</h3>";
// show some stats for admin
if ($user['role'] === 'admin') {
    $totPatients = $db->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $totDoctors = $db->query("SELECT COUNT(*) FROM doctors")->fetchColumn();
    $upcoming = $db->query("SELECT COUNT(*) FROM appointments WHERE scheduled_at > ".time())->fetchColumn();
    echo "<div class='muted'>Total Patients: $totPatients • Doctors: $totDoctors • Upcoming appointments: $upcoming</div>";
} elseif ($user['role'] === 'doctor') {
    // show doctor's upcoming appointments
    $ps = $db->prepare("SELECT id FROM doctors WHERE user_id=?"); $ps->execute([$user['id']]); $did = $ps->fetchColumn();
    $q = $db->prepare("SELECT a.*, p.user_id as patient_user FROM appointments a JOIN patients p ON p.id=a.patient_id WHERE a.doctor_id=? AND a.scheduled_at > ? ORDER BY scheduled_at ASC LIMIT 5");
    $q->execute([$did,time()]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    echo "<h4>Upcoming appointments</h4>";
    if (!$rows) echo "<div class='muted'>No upcoming appointments</div>"; else {
        echo "<table><thead><tr><th>Date/time</th><th>Patient</th><th>Reason</th></tr></thead><tbody>";
        foreach($rows as $r){
            $pu = $db->query("SELECT name FROM users WHERE id=(SELECT user_id FROM patients WHERE id={$r['patient_id']})")->fetchColumn();
            echo "<tr><td>".date('Y-m-d H:i',$r['scheduled_at'])."</td><td>".htmlspecialchars($pu)."</td><td>".htmlspecialchars($r['reason'])."</td></tr>";
        }
        echo "</tbody></table>";
    }
} else {
    // patient: show upcoming and allow booking
    $ps = $db->prepare("SELECT id FROM patients WHERE user_id=?"); $ps->execute([$user['id']]); $pid = $ps->fetchColumn();
    $q = $db->prepare("SELECT a.*, (SELECT u.name FROM users u JOIN doctors d ON d.user_id=u.id WHERE d.id=a.doctor_id) as doctor_name FROM appointments a WHERE a.patient_id=? AND a.scheduled_at > ? ORDER BY scheduled_at ASC LIMIT 5");
    $q->execute([$pid,time()]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    echo "<h4>Your upcoming appointments</h4>";
    if (!$rows) echo "<div class='muted'>No upcoming appointments</div>"; else {
        echo "<table><thead><tr><th>Date/time</th><th>Doctor</th><th>Status</th></tr></thead><tbody>";
        foreach($rows as $r){
            echo "<tr><td>".date('Y-m-d H:i',$r['scheduled_at'])."</td><td>".htmlspecialchars($r['doctor_name'])."</td><td>".htmlspecialchars($r['status'])."</td></tr>";
        }
        echo "</tbody></table>";
    }
    echo "<div style='margin-top:12px'><a class='btn' href='?page=appointments'>Book an appointment</a></div>";
}
echo "</div>";

// link to other pages
echo "<div style='display:flex;gap:12px;margin-top:12px'><div class='card' style='flex:1'><h4>Quick actions</h4><div class='muted'><ul><li><a href='?page=appointments'>Manage Appointments</a></li><li><a href='?page=records'>Medical Records</a></li></ul></div></div></div>";

pageFooter();
exit;

// ---------- other pages (appointments, doctors, patients, records) ----------

if (!function_exists('requireLogin')) {
    function requireLogin() {
        if (!isset($_SESSION['user'])) {
            header('Location: login.php');
            exit;
        }
    }
}


// APPOINTMENTS PAGE
if ($page === 'appointments') {
    requireLogin();
    pageHeader('Appointments');
    echo "<div class='card'>";
    $user = $_SESSION['user']; $db = getDb();
    if ($user['role'] === 'patient') {
        // booking form
        echo "<h3>Book Appointment</h3>";
        // list doctors
        $docs = $db->query("SELECT d.id, u.name, d.specialization FROM doctors d JOIN users u ON u.id=d.user_id")->fetchAll(PDO::FETCH_ASSOC);
        echo "<form method='post' action='?page=appointments&do=book'>";
        echo "<label>Doctor</label><select name='doctor_id'>";
        foreach($docs as $d) echo "<option value='{$d['id']}'>".htmlspecialchars($d['name'])." ({$d['specialization']})</option>";
        echo "</select>";
        echo "<label>Date & Time</label><input type='datetime-local' name='datetime' required>";
        echo "<label>Reason</label><input name='reason'>";
        echo "<div style='margin-top:8px'><button class='btn'>Book</button></div></form>";
        // handle booking
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_GET['do'] === 'book') {
            $doctor_id = intval($_POST['doctor_id'] ?? 0);
            $datetime = $_POST['datetime'] ?? ''; $reason = trim($_POST['reason'] ?? '');
            $pid = $db->prepare("SELECT id FROM patients WHERE user_id=?"); $pid->execute([$_SESSION['user']['id']]); $patient_id = $pid->fetchColumn();
            $ts = strtotime($datetime);
            if ($doctor_id && $ts) {
                // check conflict
                $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND status='booked' AND ABS(scheduled_at - ?) < 3600");
                $stmt->execute([$doctor_id, $ts]);
                if ($stmt->fetchColumn() > 0) {
                    echo "<p class='muted' style='color:#f88'>Doctor not available at that time.</p>";
                } else {
                    $db->prepare("INSERT INTO appointments (patient_id,doctor_id,scheduled_at,duration_min,reason,created_at) VALUES (?,?,?,?,?,?)")
                       ->execute([$patient_id,$doctor_id,$ts,30,$reason,time()]);
                    echo "<p class='muted' style='color:#8f8'>Appointment booked.</p>";
                }
            } else echo "<p class='muted' style='color:#f88'>Invalid input.</p>";
        }
        // show patient's appointments
        echo "<h3 style='margin-top:12px'>Your Appointments</h3>";
        $pid = $db->prepare("SELECT id FROM patients WHERE user_id=?"); $pid->execute([$_SESSION['user']['id']]); $patient_id = $pid->fetchColumn();
        $q = $db->prepare("SELECT a.*, (SELECT u.name FROM users u JOIN doctors d ON d.user_id=u.id WHERE d.id=a.doctor_id) as doctor_name FROM appointments a WHERE a.patient_id=? ORDER BY scheduled_at DESC");
        $q->execute([$patient_id]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) echo "<div class='muted'>No appointments yet.</div>"; else {
            echo "<table><thead><tr><th>When</th><th>Doctor</th><th>Status</th></tr></thead><tbody>";
            foreach($rows as $r) echo "<tr><td>".date('Y-m-d H:i',$r['scheduled_at'])."</td><td>".htmlspecialchars($r['doctor_name'])."</td><td>".htmlspecialchars($r['status'])."</td></tr>";
            echo "</tbody></table>";
        }
    } elseif ($user['role'] === 'doctor') {
        // doctor view: list appointments
        $did = $db->prepare("SELECT id FROM doctors WHERE user_id=?"); $did->execute([$user['id']]); $doctor_id = $did->fetchColumn();
        echo "<h3>Your Appointments</h3>";
        $q = $db->prepare("SELECT a.*, (SELECT u.name FROM users u JOIN patients p ON p.user_id=u.id WHERE p.id=a.patient_id) as patient_name FROM appointments a WHERE a.doctor_id=? ORDER BY scheduled_at DESC");
        $q->execute([$doctor_id]); $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) echo "<div class='muted'>No appointments yet.</div>"; else {
            echo "<table><thead><tr><th>When</th><th>Patient</th><th>Reason</th><th>Actions</th></tr></thead><tbody>";
            foreach($rows as $r) {
                echo "<tr><td>".date('Y-m-d H:i',$r['scheduled_at'])."</td><td>".htmlspecialchars($r['patient_name'])."</td><td>".htmlspecialchars($r['reason'])."</td>";
                echo "<td><a class='btn link' href='?page=records&patient_id={$r['patient_id']}'>Records</a></td></tr>";
            }
            echo "</tbody></table>";
        }
    } else {
        // admin: manage all appointments
        echo "<h3>All Appointments</h3>";
        $rows = $db->query("SELECT a.*, (SELECT u.name FROM users u JOIN patients p ON p.user_id=u.id WHERE p.id=a.patient_id) as patient_name, (SELECT u.name FROM users u JOIN doctors d ON d.user_id=u.id WHERE d.id=a.doctor_id) as doctor_name FROM appointments a ORDER BY scheduled_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) echo "<div class='muted'>No appointments.</div>"; else {
            echo "<table><thead><tr><th>Date</th><th>Patient</th><th>Doctor</th><th>Status</th></tr></thead><tbody>";
            foreach($rows as $r) echo "<tr><td>".date('Y-m-d H:i',$r['scheduled_at'])."</td><td>".htmlspecialchars($r['patient_name'])."</td><td>".htmlspecialchars($r['doctor_name'])."</td><td>".htmlspecialchars($r['status'])."</td></tr>";
            echo "</tbody></table>";
        }
    }
    echo "</div>";
    pageFooter();
    exit;
}

// DOCTORS page (admin)
if ($page === 'doctors') {
    requireLogin();
    if ($_SESSION['user']['role'] !== 'admin') { header('Location:?page=dashboard'); exit; }
    pageHeader('Manage Doctors');
    $db = getDb();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'add') {
        $name = trim($_POST['name'] ?? ''); $email = trim($_POST['email'] ?? ''); $spec = trim($_POST['spec'] ?? '');
        if ($name && $email) {
            $pw = password_hash('Doctor@123', PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (name,email,password,role,meta,created_at) VALUES (?,?,?,?,?,?)")
               ->execute([$name,$email,$pw,'doctor','{}',time()]);
            $uid = $db->lastInsertId();
            $db->prepare("INSERT INTO doctors (user_id,specialization,phone) VALUES (?,?,?)")->execute([$uid,$spec,'']);
            echo "<div class='muted' style='color:#8f8'>Doctor added (default password: Doctor@123)</div>";
        }
    }
    echo "<div class='card'><h4>Add Doctor</h4><form method='post'><input name='name' placeholder='Name' required><input name='email' placeholder='Email' required><input name='spec' placeholder='Specialization'><input type='hidden' name='act' value='add'><div style='margin-top:8px'><button class='btn'>Add</button></div></form></div>";
    echo "<div class='card'><h4>All Doctors</h4>";
    $docs = $db->query("SELECT d.id,u.name,u.email,d.specialization FROM doctors d JOIN users u ON u.id=d.user_id")->fetchAll(PDO::FETCH_ASSOC);
    if (!$docs) echo "<div class='muted'>No doctors</div>"; else {
        echo "<table><thead><tr><th>Name</th><th>Email</th><th>Specialization</th></tr></thead><tbody>";
        foreach($docs as $d) echo "<tr><td>".htmlspecialchars($d['name'])."</td><td>".htmlspecialchars($d['email'])."</td><td>".htmlspecialchars($d['specialization'])."</td></tr>";
        echo "</tbody></table>";
    }
    echo "</div>";
    pageFooter();
    exit;
}

// RECORDS page
if ($page === 'records') {
    requireLogin();
    pageHeader('Medical Records');
    $db = getDb();
    $user = $_SESSION['user'];
    // patient_id may be provided (admin or doctor viewing)
    $patient_id = intval($_GET['patient_id'] ?? 0);
    if ($user['role'] === 'patient') {
        $ps = $db->prepare("SELECT id FROM patients WHERE user_id=?"); $ps->execute([$user['id']]); $patient_id = $ps->fetchColumn();
    }
    if (!$patient_id) echo "<div class='card muted'>Select a patient (admin/doctor) or login as a patient.</div>";
    else {
        // add record (doctor)
        if ($user['role']==='doctor' && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['act'] ?? '')==='add_record') {
            $note = trim($_POST['note'] ?? ''); $presc = trim($_POST['prescription'] ?? '');
            $ps = $db->prepare("SELECT id FROM doctors WHERE user_id=?"); $ps->execute([$user['id']]); $did = $ps->fetchColumn();
            $db->prepare("INSERT INTO records (patient_id,doctor_id,note,prescription,created_at) VALUES (?,?,?,?,?)")->execute([$patient_id,$did,$note,$presc,time()]);
            echo "<div class='muted' style='color:#8f8'>Record saved</div>";
        }
        // show patient basic
        $pu = $db->query("SELECT u.name,u.email FROM users u JOIN patients p ON p.user_id=u.id WHERE p.id={$patient_id}")->fetch(PDO::FETCH_ASSOC);
        echo "<div class='card'><h4>Patient: ".htmlspecialchars($pu['name'])."</h4><div class='muted'>Email: ".htmlspecialchars($pu['email'])."</div></div>";

        // if doctor, provide form to add record
        if ($user['role'] === 'doctor') {
            echo "<div class='card'><h4>Add Record / Prescription</h4><form method='post'><textarea name='note' placeholder='Clinical note' rows='4'></textarea><textarea name='prescription' placeholder='Prescription' rows='3'></textarea><input type='hidden' name='act' value='add_record'><div style='margin-top:8px'><button class='btn'>Save</button></div></form></div>";
        }

        // list records
        $rows = $db->prepare("SELECT r.*, u.name as doctor_name FROM records r LEFT JOIN doctors d ON d.id=r.doctor_id LEFT JOIN users u ON u.id=d.user_id WHERE r.patient_id=? ORDER BY created_at DESC");
        $rows->execute([$patient_id]); $rs = $rows->fetchAll(PDO::FETCH_ASSOC);
        echo "<div class='card'><h4>Records</h4>";
        if (!$rs) echo "<div class='muted'>No records yet.</div>"; else {
            echo "<table><thead><tr><th>Date</th><th>Doctor</th><th>Note</th><th>Prescription</th></tr></thead><tbody>";
            foreach($rs as $r) echo "<tr><td>".date('Y-m-d H:i',$r['created_at'])."</td><td>".htmlspecialchars($r['doctor_name'] ?? '—')."</td><td>".nl2br(htmlspecialchars($r['note']))."</td><td>".nl2br(htmlspecialchars($r['prescription']))."</td></tr>";
            echo "</tbody></table>";
        }
        echo "</div>";
    }
    pageFooter();
    exit;
}

// Default: redirect to dashboard
header('Location:?page=dashboard');
exit;
