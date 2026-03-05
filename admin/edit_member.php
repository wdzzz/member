<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header('Location: login.php');
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$id = (int)$_GET['id'];
$db = new SQLite3('../member.db');
$stmt = $db->prepare('SELECT * FROM members WHERE id = :id');
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$member = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$member) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if (isset($_POST['edit'])) {
    $username = trim($_POST['username']);
    $level = $_POST['level'];
    $video_streams = (int)$_POST['video_streams'];
    
    if (empty($username)) {
        $error = '用户名不能为空！';
    } else {
        $count = $db->querySingle("SELECT COUNT(*) FROM members WHERE username = '$username' AND id != $id");
        if ($count > 0) {
            $error = '用户名已存在！';
        } else {
            $stmt = $db->prepare('UPDATE members SET username = :username, level = :level, video_streams = :video, update_time = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':level', $level, SQLITE3_TEXT);
            $stmt->bindValue(':video', $video_streams, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $result = $stmt->execute();
            
            if ($result) {
                $success = '会员信息更新成功！';
                $member['username'] = $username;
                $member['level'] = $level;
                $member['video_streams'] = $video_streams;
            } else {
                $error = '更新失败，请重试！';
            }
        }
    }
}

$db->close();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>编辑会员</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; }
        body { background: #f5f5f5; padding: 20px; }
        .container { max-width: 500px; margin: 0 auto; background: white; border-radius: 10px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 5px; font-size: 16px; cursor: pointer; width: 100%; }
        button:hover { background: #0056b3; }
        .error { color: #dc3545; margin: 10px 0; text-align: center; }
        .success { color: #28a745; margin: 10px 0; text-align: center; }
        .back-link { text-align: center; margin-top: 15px; }
        .back-link a { color: #007bff; text-decoration: none; margin: 0 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>编辑会员</h1>
        
        <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required value="<?php echo $member['username']; ?>" placeholder="请输入会员用户名">
            </div>
            <div class="form-group">
                <label for="level">会员等级</label>
                <select id="level" name="level" required>
                    <option value="普通" <?php echo ($member['level'] == '普通') ? 'selected' : ''; ?>>普通</option>
                    <option value="金牌" <?php echo ($member['level'] == '金牌') ? 'selected' : ''; ?>>金牌</option>
                    <option value="钻石" <?php echo ($member['level'] == '钻石') ? 'selected' : ''; ?>>钻石</option>
                </select>
            </div>
            <div class="form-group">
                <label for="video_streams">视频流数</label>
                <input type="number" min="0" id="video_streams" name="video_streams" value="<?php echo $member['video_streams']; ?>" placeholder="请输入视频流数量">
            </div>
            <button type="submit" name="edit">保存修改</button>
        </form>
        
        <div class="back-link">
            <a href="index.php">返回管理主页</a>
            <a href="recharge.php?id=<?php echo $id; ?>">充值管理</a>
        </div>
    </div>
</body>
</html>