<?php
/**************** إعداد الاتصال ****************/
$host = "localhost";
$db   = "fyo_platform";
$user = "root";
$pass = "";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("فشل الاتصال بقاعدة البيانات");
}
$conn->set_charset("utf8mb4");

/**************** إنشاء الجداول إن لم توجد ****************/
$conn->query("CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100),
    username VARCHAR(50) UNIQUE,
    password VARCHAR(255),
    start_date DATE,
    end_date DATE,
    active TINYINT(1) DEFAULT 1,
    streak INT DEFAULT 0,
    last_login DATE NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    filename VARCHAR(255),
    uploaded_at DATETIME,
    admin_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS file_student (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT,
    student_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255),
    description TEXT,
    created_at DATETIME,
    admin_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT,
    type ENUM('image','text'),
    question_text TEXT NULL,
    question_image VARCHAR(255) NULL,
    choice_a VARCHAR(255),
    choice_b VARCHAR(255),
    choice_c VARCHAR(255),
    choice_d VARCHAR(255),
    correct_choice CHAR(1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS exam_student (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT,
    student_id INT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS exam_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT,
    student_id INT,
    score INT,
    submitted_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/********* إنشاء حساب أدمن admin/admin إذا لم يوجد *********/
$resAdmin = $conn->query("SELECT COUNT(*) c FROM admins");
$rowAdmin = $resAdmin->fetch_assoc();
if ($rowAdmin['c'] == 0) {
    $conn->query("INSERT INTO admins (username,password) VALUES ('admin','admin')");
}

/**************** إدارة الجلسة ****************/
session_start();

/**************** تسجيل الخروج ****************/
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: fyo.php");
    exit;
}

/**************** معالجة تسجيل الدخول ****************/
$error = "";

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // أدمن
    $stmt = $conn->prepare("SELECT * FROM admins WHERE username=? AND password=?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    if ($admin) {
        $_SESSION['admin_id'] = $admin['id'];
        header("Location: fyo.php");
        exit;
    }

    // طالب
    $stmt = $conn->prepare("SELECT * FROM students WHERE username=? AND password=? AND active=1");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();

    if ($student) {
        $today = date('Y-m-d');
        if ($today > $student['end_date']) {
            $conn->query("UPDATE students SET active=0 WHERE id=".$student['id']);
            $error = "انتهى اشتراكك، تواصل مع المسؤول للتجديد.";
        } else {
            // تحديث الستريك
            $last = $student['last_login'];
            if ($last == null) {
                $streak = 1;
            } else {
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                if ($last == $yesterday) {
                    $streak = $student['streak'] + 1;
                } else {
                    $streak = 1;
                }
            }
            $stmt2 = $conn->prepare("UPDATE students SET streak=?, last_login=? WHERE id=?");
            $stmt2->bind_param("isi", $streak, $today, $student['id']);
            $stmt2->execute();

            $_SESSION['student_id'] = $student['id'];
            header("Location: fyo.php");
            exit;
        }
    } else if ($error == "") {
        $error = "بيانات الدخول غير صحيحة.";
    }
}

/**************** معالجة الأكشنات للأدمن ****************/
$isAdmin = isset($_SESSION['admin_id']);
$isStudent = isset($_SESSION['student_id']);

if ($isAdmin) {
    $admin_id = $_SESSION['admin_id'];

    // إضافة طالب
    if (isset($_POST['add_student'])) {
        $name = $_POST['name'];
        $user = $_POST['s_username'];
        $pass = $_POST['s_password'];
        $days = (int)$_POST['days'];

        $start = date('Y-m-d');
        $end   = date('Y-m-d', strtotime("+$days days"));

        $stmt = $conn->prepare("INSERT INTO students (name, username, password, start_date, end_date, active) VALUES (?,?,?,?,?,1)");
        $stmt->bind_param("sssss", $name, $user, $pass, $start, $end);
        $stmt->execute();
    }

    // تجديد طالب
    if (isset($_GET['renew'])) {
        $id = (int)$_GET['renew'];
        $days = 30;
        $start  = date('Y-m-d');
        $end    = date('Y-m-d', strtotime("+$days days"));
        $stmt = $conn->prepare("UPDATE students SET start_date=?, end_date=?, active=1 WHERE id=?");
        $stmt->bind_param("ssi", $start, $end, $id);
        $stmt->execute();
    }

    // حذف طالب
    if (isset($_GET['delete_student'])) {
        $id = (int)$_GET['delete_student'];
        $conn->query("DELETE FROM students WHERE id=$id");
    }

    // رفع ملف وربطه بالطلاب
    if (isset($_POST['upload_file'])) {
        $title = $_POST['file_title'];
        $selectedStudents = isset($_POST['students']) ? $_POST['students'] : [];

        if (!empty($_FILES['file_upload']['name'])) {
            $uploadDir = __DIR__ . "/uploads";
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $filename = time() . "_" . basename($_FILES['file_upload']['name']);
            $target   = $uploadDir . "/" . $filename;
            if (move_uploaded_file($_FILES['file_upload']['tmp_name'], $target)) {
                $stmt = $conn->prepare("INSERT INTO files (title, filename, uploaded_at, admin_id) VALUES (?,?,NOW(),?)");
                $stmt->bind_param("ssi", $title, $filename, $admin_id);
                $stmt->execute();
                $file_id = $stmt->insert_id;

                foreach ($selectedStudents as $sid) {
                    $sid = (int)$sid;
                    $conn->query("INSERT INTO file_student (file_id, student_id) VALUES ($file_id,$sid)");
                }
            }
        }
    }

    // حذف ملف
    if (isset($_GET['delete_file'])) {
        $fid = (int)$_GET['delete_file'];
        $conn->query("DELETE FROM file_student WHERE file_id=$fid");
        $conn->query("DELETE FROM files WHERE id=$fid");
    }

    // إنشاء اختبار
    if (isset($_POST['create_exam'])) {
        $title = $_POST['exam_title'];
        $desc  = $_POST['exam_desc'];
        $selectedStudents = isset($_POST['students_exam']) ? $_POST['students_exam'] : [];

        $stmt = $conn->prepare("INSERT INTO exams (title, description, created_at, admin_id) VALUES (?,?,NOW(),?)");
        $stmt->bind_param("ssi", $title, $desc, $admin_id);
        $stmt->execute();
        $exam_id = $stmt->insert_id;

        foreach ($selectedStudents as $sid) {
            $sid = (int)$sid;
            $conn->query("INSERT INTO exam_student (exam_id, student_id) VALUES ($exam_id,$sid)");
        }
    }

    // إضافة سؤال لاختبار
    if (isset($_POST['add_question'])) {
        $exam_id = (int)$_POST['exam_id'];
        $type    = $_POST['q_type'];
        $q_text  = null;
        $q_image = null;

        if ($type == 'text') {
            $q_text = $_POST['q_text'];
        } else {
            if (!empty($_FILES['q_image']['name'])) {
                $uploadDir = __DIR__ . "/uploads/questions";
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $filename = time()."_".basename($_FILES['q_image']['name']);
                $target   = $uploadDir."/".$filename;
                if (move_uploaded_file($_FILES['q_image']['tmp_name'], $target)) {
                    $q_image = $filename;
                }
            }
        }

        $a = $_POST['choice_a'];
        $b = $_POST['choice_b'];
        $c = $_POST['choice_c'];
        $d = $_POST['choice_d'];
        $correct = $_POST['correct_choice'];

        $stmt = $conn->prepare("INSERT INTO questions (exam_id,type,question_text,question_image,choice_a,choice_b,choice_c,choice_d,correct_choice) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("issssssss", $exam_id, $type, $q_text, $q_image, $a, $b, $c, $d, $correct);
        $stmt->execute();
    }

    // حذف اختبار
    if (isset($_GET['delete_exam'])) {
        $eid = (int)$_GET['delete_exam'];
        $conn->query("DELETE FROM exam_results WHERE exam_id=$eid");
        $conn->query("DELETE FROM exam_student WHERE exam_id=$eid");
        $conn->query("DELETE FROM questions WHERE exam_id=$eid");
        $conn->query("DELETE FROM exams WHERE id=$eid");
    }
}

/**************** واجهة الطالب - حل اختبار ****************/
if ($isStudent && isset($_POST['submit_exam'])) {
    $exam_id = (int)$_POST['exam_id'];
    $student_id = $_SESSION['student_id'];

    $qRes = $conn->query("SELECT * FROM questions WHERE exam_id=$exam_id");
    $score = 0;
    $total = $qRes->num_rows;

    while ($q = $qRes->fetch_assoc()) {
        $qid = $q['id'];
        $correct = $q['correct_choice'];
        $answer = isset($_POST['q_'.$qid]) ? $_POST['q_'.$qid] : '';
        if ($answer == $correct) {
            $score++;
        }
    }

    $stmt = $conn->prepare("INSERT INTO exam_results (exam_id, student_id, score, submitted_at) VALUES (?,?,?,NOW())");
    $stmt->bind_param("iii", $exam_id, $student_id, $score);
    $stmt->execute();

    $msg_done = "تم تسليم الاختبار. درجتك: $score / $total.";
}

/**************** HTML ****************/
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<title>منصة FYO التعليمية</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;font-family:Tahoma,Arial,sans-serif;}
body,html{height:100%;direction:rtl;}
body{background:radial-gradient(circle at top,#3c3b6e,#000);}
a{color:#f5d26a;text-decoration:none;}
a:hover{text-decoration:underline;}
.header{padding:15px 25px;color:#fff;display:flex;justify-content:space-between;align-items:center;background:rgba(0,0,0,0.4);}
.logo{font-size:22px;color:#f5d26a;font-weight:bold;}
.main{padding:20px;color:#fff;}
.glass-card{background:rgba(255,255,255,0.08);border-radius:20px;padding:30px 25px;max-width:380px;margin:60px auto;box-shadow:0 20px 60px rgba(0,0,0,0.8);backdrop-filter:blur(14px);border:1px solid rgba(255,255,255,0.25);text-align:center;}
.glass-card h1{font-size:24px;margin-bottom:10px;color:#f5d26a;}
.glass-card p.subtitle{font-size:13px;color:#ddd;margin-bottom:20px;}
.glass-card form{text-align:right;}
.glass-card label{display:block;font-size:13px;margin:8px 0 4px;}
.glass-card input[type="text"],.glass-card input[type="password"]{width:100%;padding:9px 11px;border-radius:10px;border:1px solid rgba(255,255,255,0.3);background:rgba(0,0,0,0.35);color:#fff;outline:none;}
.glass-card input:focus{border-color:#f5d26a;box-shadow:0 0 0 1px rgba(245,210,106,0.5);}
.glass-card button{margin-top:15px;width:100%;padding:10px;border-radius:999px;border:none;background:linear-gradient(135deg,#f5d26a,#f1b64a);color:#000;font-weight:bold;cursor:pointer;transition:transform 0.15s,box-shadow 0.15s;}
.glass-card button:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(0,0,0,0.5);}
.help{font-size:11px;margin-top:10px;color:#ccc;}
.error{background:rgba(255,0,0,0.15);border:1px solid rgba(255,0,0,0.5);color:#ffb3b3;padding:7px 9px;border-radius:8px;margin-bottom:8px;font-size:12px;text-align:right;}
.msg{background:rgba(0,255,0,0.15);border:1px solid rgba(0,255,0,0.5);color:#b9ffb9;padding:7px 9px;border-radius:8px;margin-bottom:8px;font-size:12px;text-align:right;}
.card{background:#141428;border-radius:14px;padding:15px;margin-bottom:15px;}
.card h3{margin-bottom:10px;color:#f5d26a;font-size:17px;}
.card label{font-size:13px;}
.card input, .card textarea, .card select{background:#0b0b16;border:1px solid #333;color:#fff;border-radius:7px;padding:5px 7px;margin:3px 0;width:100%;font-size:13px;}
.card button{background:#f5d26a;border:none;border-radius:8px;padding:7px 10px;margin-top:8px;cursor:pointer;font-size:13px;color:#000;}
.table{width:100%;border-collapse:collapse;margin-top:10px;font-size:12px;}
.table th,.table td{border:1px solid #333;padding:5px;text-align:center;}
.badge{display:inline-block;padding:3px 7px;border-radius:10px;font-size:11px;}
.badge-active{background:#1f9d55;}
.badge-expired{background:#c53030;}
.streak-badge{background:linear-gradient(135deg,#f5d26a,#ff7b00);color:#000;font-weight:bold;}
.flex{display:flex;gap:15px;flex-wrap:wrap;}
.col{flex:1;min-width:260px;}
.exam-block{margin-top:10px;}
.question{border-bottom:1px solid #333;padding:8px 0;}
.question img{max-width:100%;max-height:250px;display:block;margin-bottom:5px;}
.radio-wrap{margin:3px 0;}
</style>
</head>
<body>

<?php if(!$isAdmin && !$isStudent): ?>
  <!-- شاشة تسجيل الدخول -->
  <div class="glass-card">
    <h1>منصة FYO التعليمية</h1>
    <p class="subtitle">بوابتك للتميز في اختبار القدرات</p>
    <?php if($error): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>
    <form method="post">
      <label>اسم المستخدم</label>
      <input type="text" name="username" required>
      <label>كلمة المرور</label>
      <input type="password" name="password" required>
      <button type="submit" name="login">تسجيل الدخول</button>
      <p class="help">الحسابات يتم إنشاؤها من قبل المسؤول فقط، تواصل معه للحصول على حساب.</p>
    </form>
  </div>

<?php elseif($isAdmin): 
    $students = $conn->query("SELECT * FROM students ORDER BY id DESC");
    $files = $conn->query("SELECT * FROM files ORDER BY id DESC");
    $exams = $conn->query("SELECT * FROM exams ORDER BY id DESC");
?>
  <div class="header">
    <div class="logo">منصة FYO التعليمية - مسؤول</div>
    <div><a href="?logout=1">تسجيل الخروج</a></div>
  </div>
  <div class="main">
    <div class="flex">
      <div class="col">
        <div class="card">
          <h3>إضافة طالب جديد</h3>
          <form method="post">
            <label>اسم الطالب</label>
            <input type="text" name="name" required>
            <label>اسم المستخدم</label>
            <input type="text" name="s_username" required>
            <label>كلمة المرور</label>
            <input type="text" name="s_password" required>
            <label>مدة الاشتراك (أيام)</label>
            <input type="number" name="days" value="30" min="1" required>
            <button type="submit" name="add_student">حفظ الطالب</button>
          </form>
        </div>

        <div class="card">
          <h3>رفع ملف وتحديد طلابه</h3>
          <form method="post" enctype="multipart/form-data">
            <label>عنوان الملف</label>
            <input type="text" name="file_title" required>
            <label>الملف</label>
            <input type="file" name="file_upload" required>
            <label>اختر الطلاب (يمكن اختيار أكثر من طالب)</label>
            <select name="students[]" multiple size="5">
              <?php
              $stAll = $conn->query("SELECT id,name FROM students ORDER BY name");
              while($st = $stAll->fetch_assoc()): ?>
                <option value="<?php echo $st['id']; ?>"><?php echo $st['name']; ?></option>
              <?php endwhile; ?>
            </select>
            <button type="submit" name="upload_file">رفع الملف</button>
          </form>
        </div>

        <div class="card">
          <h3>إنشاء اختبار جديد</h3>
          <form method="post">
            <label>عنوان الاختبار</label>
            <input type="text" name="exam_title" required>
            <label>وصف مختصر</label>
            <textarea name="exam_desc" rows="3"></textarea>
            <label>اختر الطلاب لهذا الاختبار</label>
            <select name="students_exam[]" multiple size="5">
              <?php
              $stAll2 = $conn->query("SELECT id,name FROM students ORDER BY name");
              while($st = $stAll2->fetch_assoc()): ?>
                <option value="<?php echo $st['id']; ?>"><?php echo $st['name']; ?></option>
              <?php endwhile; ?>
            </select>
            <button type="submit" name="create_exam">حفظ الاختبار</button>
          </form>
        </div>
      </div>

      <div class="col">
        <div class="card">
          <h3>قائمة الطلاب</h3>
          <table class="table">
            <tr>
              <th>#</th>
              <th>الاسم</th>
              <th>المعرف</th>
              <th>بداية</th>
              <th>نهاية</th>
              <th>الحالة</th>
              <th>ستريك</th>
              <th>إجراءات</th>
            </tr>
            <?php while($s = $students->fetch_assoc()): 
              $badgeClass = $s['active'] ? 'badge-active' : 'badge-expired';
              $badgeText  = $s['active'] ? 'نشط' : 'منتهي';
            ?>
              <tr>
                <td><?php echo $s['id']; ?></td>
                <td><?php echo htmlspecialchars($s['name']); ?></td>
                <td><?php echo htmlspecialchars($s['username']); ?></td>
                <td><?php echo $s['start_date']; ?></td>
                <td><?php echo $s['end_date']; ?></td>
                <td><span class="badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span></td>
                <td><span class="badge streak-badge"><?php echo $s['streak']; ?>🔥</span></td>
                <td>
                  <a href="?renew=<?php echo $s['id']; ?>">تجديد</a> |
                  <a href="?delete_student=<?php echo $s['id']; ?>" onclick="return confirm('حذف الطالب نهائياً؟');">حذف</a>
                </td>
              </tr>
            <?php endwhile; ?>
          </table>
        </div>

        <div class="card">
          <h3>الملفات المرفوعة</h3>
          <table class="table">
            <tr>
              <th>#</th><th>العنوان</th><th>الملف</th><th>تاريخ الرفع</th><th>إجراء</th>
            </tr>
            <?php while($f = $files->fetch_assoc()): ?>
              <tr>
                <td><?php echo $f['id']; ?></td>
                <td><?php echo htmlspecialchars($f['title']); ?></td>
                <td><a href="<?php echo 'uploads/'.$f['filename']; ?>" target="_blank">عرض/تحميل</a></td>
                <td><?php echo $f['uploaded_at']; ?></td>
                <td><a href="?delete_file=<?php echo $f['id']; ?>" onclick="return confirm('حذف الملف؟');">حذف</a></td>
              </tr>
            <?php endwhile; ?>
          </table>
        </div>

        <div class="card">
          <h3>الاختبارات</h3>
          <?php while($e = $exams->fetch_assoc()): ?>
            <div class="exam-block">
              <strong><?php echo htmlspecialchars($e['title']); ?></strong>
              <div style="font-size:12px;color:#ccc;"><?php echo nl2br(htmlspecialchars($e['description'])); ?></div>
              <a href="?delete_exam=<?php echo $e['id']; ?>" onclick="return confirm('حذف الاختبار وكل أسئلته ونتائجه؟');">حذف الاختبار</a>
              <details style="margin-top:5px;">
                <summary style="cursor:pointer;">إضافة سؤال لهذا الاختبار</summary>
                <form method="post" enctype="multipart/form-data">
                  <input type="hidden" name="exam_id" value="<?php echo $e['id']; ?>">
                  <label>نوع السؤال</label>
                  <select name="q_type" onchange="this.form.q_text_block.style.display = this.value=='text'?'block':'none'; this.form.q_image_block.style.display = this.value=='image'?'block':'none';">
                    <option value="text">نصي</option>
                    <option value="image">صورة</option>
                  </select>
                  <div id="q_text_block">
                    <label>نص السؤال</label>
                    <textarea name="q_text" rows="3"></textarea>
                  </div>
                  <div id="q_image_block" style="display:none;">
                    <label>صورة السؤال</label>
                    <input type="file" name="q_image">
                  </div>
                  <label>الاختيار أ</label><input type="text" name="choice_a" required>
                  <label>الاختيار ب</label><input type="text" name="choice_b" required>
                  <label>الاختيار ج</label><input type="text" name="choice_c" required>
                  <label>الاختيار د</label><input type="text" name="choice_d" required>
                  <label>الإجابة الصحيحة</label>
                  <select name="correct_choice">
                    <option value="A">أ</option>
                    <option value="B">ب</option>
                    <option value="C">ج</option>
                    <option value="D">د</option>
                  </select>
                  <button type="submit" name="add_question">حفظ السؤال</button>
                </form>
              </details>
            </div>
          <?php endwhile; ?>
        </div>
      </div>
    </div>
  </div>

<?php elseif($isStudent): 
    $id = $_SESSION['student_id'];
    $stmt = $conn->prepare("SELECT * FROM students WHERE id=?");
    $stmt->bind_param("i",$id);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();

    $files = $conn->query("SELECT f.* FROM files f JOIN file_student fs ON fs.file_id=f.id WHERE fs.student_id=$id ORDER BY f.id DESC");
    $exams = $conn->query("SELECT e.* FROM exams e JOIN exam_student es ON es.exam_id=e.id WHERE es.student_id=$id ORDER BY e.id DESC");
?>
  <div class="header">
    <div class="logo">منصة FYO التعليمية - طالب</div>
    <div><a href="?logout=1">تسجيل الخروج</a></div>
  </div>
  <div class="main">
    <div class="card">
      <h3>مرحباً يا <?php echo htmlspecialchars($student['name']); ?> 👋</h3>
      <p>سعيدون بوجودك في منصة FYO التعليمية.</p>
      <p>ستريك حضورك المتتالي: <span class="badge streak-badge"><?php echo $student['streak']; ?> يوم 🔥</span></p>
      <?php if(isset($msg_done)): ?><div class="msg"><?php echo $msg_done; ?></div><?php endif; ?>
    </div>

    <div class="flex">
      <div class="col">
        <div class="card">
          <h3>ملفاتك التعليمية</h3>
          <?php if($files->num_rows == 0): ?>
            <p style="font-size:13px;color:#ccc;">لا توجد ملفات متاحة حالياً.</p>
          <?php else: ?>
            <ul style="list-style:none;font-size:13px;">
              <?php while($f = $files->fetch_assoc()): ?>
                <li style="margin-bottom:5px;">
                  🔹 <?php echo htmlspecialchars($f['title']); ?> -
                  <a href="<?php echo 'uploads/'.$f['filename']; ?>" target="_blank">عرض/تحميل</a>
                </li>
              <?php endwhile; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>

      <div class="col">
        <div class="card">
          <h3>اختباراتك</h3>
          <?php if($exams->num_rows == 0): ?>
            <p style="font-size:13px;color:#ccc;">لا توجد اختبارات مضافة لك حالياً.</p>
          <?php else: ?>
            <?php while($e = $exams->fetch_assoc()): ?>
              <div class="exam-block">
                <strong><?php echo htmlspecialchars($e['title']); ?></strong>
                <div style="font-size:12px;color:#ccc;"><?php echo nl2br(htmlspecialchars($e['description'])); ?></div>
                <?php
                $already = $conn->query("SELECT * FROM exam_results WHERE exam_id=".$e['id']." AND student_id=$id")->num_rows;
                if ($already): ?>
                  <p style="font-size:12px;color:#8bc34a;">تم حل هذا الاختبار مسبقاً.</p>
                <?php else: 
                  $qs = $conn->query("SELECT * FROM questions WHERE exam_id=".$e['id']);
                  if ($qs->num_rows == 0): ?>
                    <p style="font-size:12px;color:#ccc;">الاختبار لا يحتوي أسئلة حتى الآن.</p>
                  <?php else: ?>
                    <form method="post">
                      <input type="hidden" name="exam_id" value="<?php echo $e['id']; ?>">
                      <?php while($q = $qs->fetch_assoc()): ?>
                        <div class="question">
                          <?php if($q['type']=='text'): ?>
                            <div style="margin-bottom:5px;"><?php echo nl2br(htmlspecialchars($q['question_text'])); ?></div>
                          <?php else: ?>
                            <img src="<?php echo 'uploads/questions/'.$q['question_image']; ?>" alt="سؤال">
                          <?php endif; ?>
                          <div class="radio-wrap">
                            <label><input type="radio" name="q_<?php echo $q['id']; ?>" value="A"> أ) <?php echo htmlspecialchars($q['choice_a']); ?></label>
                          </div>
                          <div class="radio-wrap">
                            <label><input type="radio" name="q_<?php echo $q['id']; ?>" value="B"> ب) <?php echo htmlspecialchars($q['choice_b']); ?></label>
                          </div>
                          <div class="radio-wrap">
                            <label><input type="radio" name="q_<?php echo $q['id']; ?>" value="C"> ج) <?php echo htmlspecialchars($q['choice_c']); ?></label>
                          </div>
                          <div class="radio-wrap">
                            <label><input type="radio" name="q_<?php echo $q['id']; ?>" value="D"> د) <?php echo htmlspecialchars($q['choice_d']); ?></label>
                          </div>
                        </div>
                      <?php endwhile; ?>
                      <button type="submit" name="submit_exam">إرسال الاختبار</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            <?php endwhile; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

</body>
</html>
