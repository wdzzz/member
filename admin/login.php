<?php
session_start();

// 已登录则跳转到管理后台
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
    header('Location: index.php');
    exit;
}

$error = '';
// 处理登录请求
if (isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if (empty($username) || empty($password)) {
        $error = '请输入账号和密码！';
    } else {
        $db = new SQLite3('../member.db');
        $stmt = $db->prepare('SELECT * FROM admin WHERE username = :username');
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $admin = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $db->close();
        
        if (!$admin || !password_verify($password, $admin['password'])) {
            $error = '账号或密码错误！';
        } else {
            // 登录成功
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; }
        body { background: #f5f5f5; padding: 20px; }
        .login-box { max-width: 400px; margin: 50px auto; background: white; border-radius: 10px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 5px; font-size: 16px; cursor: pointer; width: 100%; }
        button:hover { background: #0056b3; }
        .error { color: #dc3545; margin: 10px 0; text-align: center; }
        .back-link { text-align: center; margin-top: 15px; }
        .back-link a { color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>管理员登录</h1>
        <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label for="username">账号</label>
                <input type="text" id="username" name="username" required placeholder="请输入管理员账号">
            </div>
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required placeholder="请输入密码">
            </div>
            <button type="submit" name="login">登录</button>
        </form>
        <div class="back-link">
            <a href="../index.php">返回首页</a>
        </div>
    </div>
</body>
</html>