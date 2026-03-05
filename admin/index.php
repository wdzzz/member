<?php
session_start();

// 未登录则跳转到登录页
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header('Location: login.php');
    exit;
}

// ===== 密码修改处理逻辑 =====
$alertScript = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_pwd') {
    $oldPwd   = trim($_POST['old_pwd']);
    $newPwd   = trim($_POST['new_pwd']);
    $confirmPwd = trim($_POST['confirm_pwd']);

    if (empty($oldPwd) || empty($newPwd) || empty($confirmPwd)) {
        $alertScript = "alert('❌ 所有密码字段不能为空！');";
    } elseif ($newPwd !== $confirmPwd) {
        $alertScript = "alert('❌ 新密码与确认密码不一致！');";
    } elseif (strlen($newPwd) < 6) {
        $alertScript = "alert('❌ 新密码至少6位！');";
    } else {
        $db = new SQLite3('../member.db');
        $stmt = $db->prepare('SELECT password FROM admin WHERE username = "admin" LIMIT 1');
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$result) {
            $alertScript = "alert('❌ 管理员账号不存在！');";
        } else {
            if (password_verify($oldPwd, $result['password'])) {
                $newPwdHash = password_hash($newPwd, PASSWORD_DEFAULT);
                $updateStmt = $db->prepare('UPDATE admin SET password = :password WHERE username = "admin"');
                $updateStmt->bindValue(':password', $newPwdHash, SQLITE3_TEXT);
                $updateResult = $updateStmt->execute();

                if ($updateResult) {
                    $alertScript = "alert('✅ 密码修改成功！');";
                } else {
                    $alertScript = "alert('❌ 修改失败，请重试！');";
                }
            } else {
                $alertScript = "alert('❌ 原密码错误！');";
            }
        }
        $db->close();
    }
}

$db = new SQLite3('../member.db');

// 统计数据
$totalMembers = $db->querySingle('SELECT COUNT(*) FROM members');
$today = date('Y-m-d');
$validMembers = $db->querySingle("
    SELECT COUNT(DISTINCT m.id) 
    FROM members m
    JOIN recharge r ON m.id = r.member_id
    WHERE r.end_date >= '$today'
");
$totalIncome = round($db->querySingle('SELECT SUM(recharge_amount) FROM recharge WHERE recharge_amount > 0') ?: 0, 2);

// ===== 排序 =====
$allowedSort = ['username', 'total_recharge', 'end_date', 'remaining_days'];
$defaultSort = 'remaining_days';
$defaultOrder = 'ASC';

$sortBy = isset($_GET['sort']) && in_array($_GET['sort'], $allowedSort) ? $_GET['sort'] : $defaultSort;
$sortOrder = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : $defaultOrder;

$members = [];
$result = $db->query('SELECT * FROM members');
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $stmt = $db->prepare('SELECT end_date FROM recharge WHERE member_id = :id ORDER BY end_date DESC LIMIT 1');
    $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $endDate = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    $stmt = $db->prepare('SELECT IFNULL(SUM(recharge_amount), 0) FROM recharge WHERE member_id = :id AND recharge_amount > 0');
    $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $totalRechargeResult = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $totalRecharge = round($totalRechargeResult['IFNULL(SUM(recharge_amount), 0)'], 2);

    $stmt = $db->prepare('SELECT IFNULL(SUM(gift_months), 0) FROM recharge WHERE member_id = :id');
    $stmt->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $totalGiftMonthsResult = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    $totalGiftMonths = $totalGiftMonthsResult['IFNULL(SUM(gift_months), 0)'];

    $isDiamond = ($row['level'] == '钻石会员');

    $row['end_date'] = $endDate ? $endDate['end_date'] : '无记录';
    $row['remaining_days'] = 0;
    $row['remaining_days_text'] = '无记录';

    if ($isDiamond) {
        $row['remaining_days_text'] = '永久有效';
        $row['end_date'] = '永久有效';
    } else {
        if ($row['end_date'] !== '无记录') {
            $todayTs = strtotime($today);
            $endDateTs = strtotime($row['end_date']);
            $diffDays = floor(($endDateTs - $todayTs) / 86400);

            $row['remaining_days'] = $diffDays;
            if ($diffDays > 0) {
                $row['remaining_days_text'] = "剩余{$diffDays}天";
            } elseif ($diffDays == 0) {
                $row['remaining_days_text'] = "今日到期";
            } else {
                $row['remaining_days_text'] = "已到期（" . abs($diffDays) . "天）";
            }
        }
    }

    $row['total_recharge'] = $totalRecharge;
    $row['total_gift_months'] = $totalGiftMonths;
    $members[] = $row;
}

usort($members, function($a, $b) use ($sortBy, $sortOrder) {
    switch ($sortBy) {
        case 'username':
            $cmp = strcmp($a['username'], $b['username']);
            break;
        case 'total_recharge':
            $cmp = $a['total_recharge'] - $b['total_recharge'];
            break;
        case 'end_date':
        case 'remaining_days':
            $aIsForever = ($a['level'] == '钻石会员');
            $bIsForever = ($b['level'] == '钻石会员');
            if ($aIsForever && !$bIsForever) return -1;
            if (!$aIsForever && $bIsForever) return 1;

            if ($a['end_date'] === '无记录' && $b['end_date'] !== '无记录') return 1;
            if ($a['end_date'] !== '无记录' && $b['end_date'] === '无记录') return -1;

            if ($sortBy === 'remaining_days') {
                $cmp = $a['remaining_days'] - $b['remaining_days'];
            } else {
                $cmp = strtotime($a['end_date']) - strtotime($b['end_date']);
            }
            break;
        default:
            $cmp = $a['id'] - $b['id'];
    }
    return $sortOrder === 'ASC' ? $cmp : -$cmp;
});

$db->close();

function getSortUrl($field) {
    global $sortBy, $sortOrder;
    $newOrder = ($sortBy === $field && $sortOrder === 'DESC') ? 'ASC' : 'DESC';
    return "?sort=$field&order=$newOrder";
}
function getSortIcon($field) {
    global $sortBy, $sortOrder;
    if ($sortBy !== $field) return '';
    return $sortOrder === 'DESC' ? ' ▼' : ' ▲';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>会员管理后台</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; }
        body { background: #f5f5f5; padding: 20px; }
        .container { max-width: 1100px; margin: 0 auto; background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; margin-bottom: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px; }
        .logout, .change-pwd-btn {
            text-decoration: none; padding: 5px 10px; border-radius: 5px;
        }
        .change-pwd-btn {
            color: #28a745; border: 1px solid #28a745; margin-right: 10px;
        }
        .change-pwd-btn:hover { background: #28a745; color: #fff; }
        .logout { color: #dc3545; border: 1px solid #dc3545; }
        .logout:hover { background: #dc3545; color: #fff; }
        .btn { display: inline-block; padding: 8px 15px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; margin: 0 5px; }
        .btn-success { background: #28a745; }
        .btn-warning { background: #ffc107; color: #333; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; cursor: pointer; }
        .actions { display: flex; gap: 5px; }
        .add-member { margin-bottom: 20px; text-align: right; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 15px; margin-bottom: 25px; }
        .stat-card { background: #f8f9fa; padding: 20px; border-radius: 8px; text-align: center; }
        .stat-card h3 { color: #666; font-size: 16px; margin-bottom: 10px; }
        .stat-card .num { font-size: 24px; font-weight: bold; color: #007bff; }
        .sortable { color: #007bff; font-weight: bold; }
        .forever { color: #090; font-weight: bold; }
        .expired { color: #dc3545; font-weight: bold; }
        .expire-today { color: #ffc107; font-weight: bold; }
        .no-record { color: #6c757d; }

        .pwd-box {
            margin: 20px 0; padding: 20px; background: #f8f9fa; border-radius: 8px;
            display: none;
        }
        .pwd-box.active { display: block; }
        .pwd-item { margin-bottom: 15px; }
        .pwd-item label { display: block; margin-bottom: 5px; font-weight: bold; }
        .pwd-item input { width: 300px; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .pwd-sub { padding: 8px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; }
        @media (max-width:768px) {
            .pwd-item input { width: 100%; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>会员管理后台</h1>
        <div>
            <a href="javascript:" class="change-pwd-btn" onclick="togglePwd()">修改密码</a>
            <a href="logout.php" class="logout">退出登录</a>
        </div>
    </div>

    <!-- 修改密码面板 -->
    <div class="pwd-box" id="pwdBox">
        <h3>修改管理员密码</h3>
        <form method="post" action="">
            <input type="hidden" name="action" value="change_pwd">
            <div class="pwd-item">
                <label>原密码</label>
                <input type="password" name="old_pwd" required>
            </div>
            <div class="pwd-item">
                <label>新密码</label>
                <input type="password" name="new_pwd" required>
            </div>
            <div class="pwd-item">
                <label>确认新密码</label>
                <input type="password" name="confirm_pwd" required>
            </div>
            <button class="pwd-sub" type="submit">确认修改</button>
        </form>
    </div>

    <div class="stats">
        <div class="stat-card">
            <h3>总会员数</h3>
            <div class="num"><?= $totalMembers ?:0 ?></div>
        </div>
        <div class="stat-card">
            <h3>当前有效会员</h3>
            <div class="num"><?= $validMembers ?:0 ?></div>
        </div>
        <div class="stat-card">
            <h3>充值总收入</h3>
            <div class="num">¥<?= $totalIncome ?></div>
        </div>
    </div>

    <div class="add-member">
        <a href="add_member.php" class="btn btn-success">添加会员</a>
    </div>

    <table>
        <tr>
            <th>ID</th>
            <th class="sortable" onclick="location='<?=getSortUrl('username')?>'">用户名<?=getSortIcon('username')?></th>
            <th>会员等级</th>
            <th>视频流数</th>
            <th class="sortable" onclick="location='<?=getSortUrl('total_recharge')?>'">累计充值<?=getSortIcon('total_recharge')?></th>
            <th>累计赠送月份</th>
            <th class="sortable" onclick="location='<?=getSortUrl('remaining_days')?>'">剩余日期<?=getSortIcon('remaining_days')?></th>
            <th class="sortable" onclick="location='<?=getSortUrl('end_date')?>'">最新到期日<?=getSortIcon('end_date')?></th>
            <th>操作</th>
        </tr>
        <?php foreach ($members as $m): ?>
        <tr>
            <td><?=$m['id']?></td>
            <td><?=$m['username']?></td>
            <td><?=$m['level']?></td>
            <td><?=$m['video_streams']?></td>
            <td>¥<?=$m['total_recharge']?></td>
            <td><?=$m['total_gift_months']?></td>
            <td>
                <?php if($m['level']=='钻石会员'): ?>
                    <span class="forever">永久有效</span>
                <?php elseif(str_contains($m['remaining_days_text'],'已到期')): ?>
                    <span class="expired"><?=$m['remaining_days_text']?></span>
                <?php elseif(str_contains($m['remaining_days_text'],'今日到期')): ?>
                    <span class="expire-today"><?=$m['remaining_days_text']?></span>
                <?php elseif($m['remaining_days_text']=='无记录'): ?>
                    <span class="no-record">无记录</span>
                <?php else: ?>
                    <?=$m['remaining_days_text']?>
                <?php endif; ?>
            </td>
            <td>
                <?php if($m['level']=='钻石会员'): ?>
                    <span class="forever">永久有效</span>
                <?php else: ?>
                    <?=$m['end_date']?>
                <?php endif; ?>
            </td>
            <td class="actions">
                <a href="edit_member.php?id=<?=$m['id']?>" class="btn btn-warning">编辑</a>
                <a href="recharge.php?id=<?=$m['id']?>" class="btn">充值/赠送</a>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>

<script>
    // 控制密码框显示隐藏
    function togglePwd(){
        const box = document.getElementById('pwdBox');
        box.classList.toggle('active');
    }

    // 执行提示弹窗
    <?php if(!empty($alertScript)): ?>
        <?=$alertScript?>
        // 成功后自动隐藏密码面板
        document.getElementById('pwdBox').classList.remove('active');
    <?php endif; ?>
</script>
</body>
</html>