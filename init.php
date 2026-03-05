<?php
// 判断是否是表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['admin_user']) && !empty($_POST['admin_pwd'])) {
    // 获取表单提交的管理员账号和密码
    $adminUser = trim($_POST['admin_user']);
    $adminPwd = trim($_POST['admin_pwd']);
    
    // 简单的输入验证
    if (empty($adminUser) || empty($adminPwd)) {
        die("❌ 管理员账号和密码不能为空！<br><a href='{$_SERVER['PHP_SELF']}'>返回重新输入</a>");
    }

    // 全新初始化数据库
    $db = new SQLite3('member.db');
    if (!$db) {
        die("❌ 数据库连接失败！");
    }

    // 会员表
    $db->exec('CREATE TABLE IF NOT EXISTS members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        level TEXT DEFAULT "普通",
        video_streams INTEGER DEFAULT 0,
        create_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        update_time DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    // 充值/赠送记录表（整合版）
    $db->exec('CREATE TABLE IF NOT EXISTS recharge (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        member_id INTEGER NOT NULL,
        recharge_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        recharge_months INTEGER NOT NULL DEFAULT 0,
        gift_months INTEGER DEFAULT 0,
        gift_days INTEGER DEFAULT 0,
        video_streams INTEGER DEFAULT 0,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        remark TEXT DEFAULT "",
        recharge_time DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (member_id) REFERENCES members(id)
    )');

    // 管理员表
    $db->exec('CREATE TABLE IF NOT EXISTS admin (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password TEXT NOT NULL
    )');

    // 检查管理员账号是否已存在
    $stmt = $db->prepare('SELECT COUNT(*) FROM admin WHERE username = :username');
    $stmt->bindValue(':username', $adminUser, SQLITE3_TEXT);
    $result = $stmt->execute();
    $adminExists = $result->fetchArray()[0];

    if ($adminExists == 0) {
        // 加密密码并插入管理员账号
        $hashedPwd = password_hash($adminPwd, PASSWORD_DEFAULT);
        $insertStmt = $db->prepare('INSERT INTO admin (username, password) VALUES (:username, :password)');
        $insertStmt->bindValue(':username', $adminUser, SQLITE3_TEXT);
        $insertStmt->bindValue(':password', $hashedPwd, SQLITE3_TEXT);
        $insertResult = $insertStmt->execute();
        
        if ($insertResult) {
            echo "✅ 数据库全新初始化成功！<br>";
            echo " 管理员账号：{$adminUser}<br>";
            echo " 管理员密码：{$adminPwd}（已加密存储）<br><br>";
            echo "<a href='index.php'>返回首页</a> | <a href='admin/'>进入后台</a>";
        } else {
            echo "❌ 管理员账号创建失败！<br><a href='{$_SERVER['PHP_SELF']}'>重新尝试</a>";
        }
    } else {
        echo "❌ 该管理员账号已存在！<br><a href='{$_SERVER['PHP_SELF']}'>重新输入</a>";
    }

    $db->close();
} else {
    // 显示管理员账号密码输入表单
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>数据库初始化 - 设置管理员账号</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 400px; margin: 50px auto; padding: 0 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input { width: 100%; padding: 8px; box-sizing: border-box; }
        button { padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h2>数据库初始化 - 设置管理员账号</h2>
    <form method="POST" action="">
        <div class="form-group">
            <label for="admin_user">管理员账号：</label>
            <input type="text" id="admin_user" name="admin_user" required placeholder="请输入管理员账号">
        </div>
        <div class="form-group">
            <label for="admin_pwd">管理员密码：</label>
            <input type="password" id="admin_pwd" name="admin_pwd" required placeholder="请输入管理员密码">
        </div>
        <button type="submit">初始化数据库</button>
    </form>
</body>
</html>
<?php
}
?>