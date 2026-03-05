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

// 处理删除充值/赠送记录
if (isset($_GET['delete_recharge']) && is_numeric($_GET['delete_recharge'])) {
    $deleteId = (int)$_GET['delete_recharge'];
    $memberId = (int)$_GET['id'];
    
    $db = new SQLite3('../member.db');
    // 获取要删除的记录信息
    $stmt = $db->prepare('SELECT * FROM recharge WHERE id = :delete_id');
    $stmt->bindValue(':delete_id', $deleteId, SQLITE3_INTEGER);
    $recharge = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    
    if ($recharge && $recharge['member_id'] == $memberId) {
        // 计算需要扣减的总月份（充值+赠送）
        $totalMonths = $recharge['recharge_months'] + $recharge['gift_months'];
        $totalDays = $recharge['gift_days'] ?? 0;
        $originalEndDate = $recharge['end_date'];
        
        // 先扣减月份
        $newEndDate = date('Y-m-d', strtotime("-$totalMonths months", strtotime($originalEndDate)));
        // 再扣减天数
        if ($totalDays > 0) {
            $newEndDate = date('Y-m-d', strtotime("-$totalDays days", strtotime($newEndDate)));
        }
        
        // 删除记录
        $db->exec("DELETE FROM recharge WHERE id = $deleteId");
        
        // 更新剩余记录的到期时间（如果有）
        $stmt = $db->prepare('SELECT * FROM recharge WHERE member_id = :id ORDER BY end_date DESC LIMIT 1');
        $stmt->bindValue(':id', $memberId, SQLITE3_INTEGER);
        $latestRecharge = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if ($latestRecharge) {
            $db->exec("UPDATE recharge SET end_date = '$newEndDate' WHERE id = " . $latestRecharge['id']);
        }
        
        $db->close();
        header('Location: recharge.php?id=' . $memberId . '&success=delete');
        exit;
    }
    $db->close();
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

// 获取所有充值/赠送记录
$recharges = [];
$stmt = $db->prepare('SELECT * FROM recharge WHERE member_id = :id ORDER BY recharge_time DESC');
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$result = $stmt->execute();
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $recharges[] = $row;
}

// 计算累计数据
$stmt = $db->prepare('SELECT IFNULL(SUM(recharge_amount), 0) FROM recharge WHERE member_id = :id AND recharge_amount > 0');
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$totalRechargeResult = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$totalRecharge = round($totalRechargeResult['IFNULL(SUM(recharge_amount), 0)'], 2);

$stmt = $db->prepare('SELECT IFNULL(SUM(gift_months), 0) FROM recharge WHERE member_id = :id');
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$totalGiftMonthsResult = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
$totalGiftMonths = $totalGiftMonthsResult['IFNULL(SUM(gift_months), 0)'];

$error = '';
$success = '';
if (isset($_GET['success']) && $_GET['success'] == 'delete') {
    $success = '记录删除成功，会员周期已同步扣减！';
}

// 处理充值/赠送提交
if (isset($_POST['save'])) {
    $amount = (float)$_POST['amount'];
    $months = (int)$_POST['months'];
    $gift_type = $_POST['gift_type'];
    $gift_num = (int)$_POST['gift_num'];
    $video_streams = (int)$_POST['video_streams'];
    $start_date = $_POST['start_date'];
    $remark = trim($_POST['remark']);
    
    // 验证：金额和月份不能同时为0，且赠送数量也为0
    if ($amount == 0 && $months == 0 && $gift_num == 0) {
        $error = '充值金额/月份和赠送数量不能同时为0！';
    } else {
        // 计算到期日期
        $end_date = $start_date;
        
        // 先加充值月份
        if ($months > 0) {
            $end_date = date('Y-m-d', strtotime("+$months months", strtotime($end_date)));
        }
        
        // 再加赠送（月份/天数）
        $gift_months = 0;
        $gift_days = 0;
        if ($gift_num > 0) {
            if ($gift_type == 'months') {
                $gift_months = $gift_num;
                $end_date = date('Y-m-d', strtotime("+$gift_num months", strtotime($end_date)));
            } else {
                $gift_days = $gift_num;
                $end_date = date('Y-m-d', strtotime("+$gift_num days", strtotime($end_date)));
            }
        }
        
        // 添加记录
        $stmt = $db->prepare('
            INSERT INTO recharge 
            (member_id, recharge_amount, recharge_months, gift_months, gift_days, video_streams, start_date, end_date, remark) 
            VALUES (:member_id, :amount, :months, :gift_months, :gift_days, :video, :start, :end, :remark)
        ');
        $stmt->bindValue(':member_id', $id, SQLITE3_INTEGER);
        $stmt->bindValue(':amount', $amount, SQLITE3_FLOAT);
        $stmt->bindValue(':months', $months, SQLITE3_INTEGER);
        $stmt->bindValue(':gift_months', $gift_months, SQLITE3_INTEGER);
        $stmt->bindValue(':gift_days', $gift_days, SQLITE3_INTEGER);
        $stmt->bindValue(':video', $video_streams, SQLITE3_INTEGER);
        $stmt->bindValue(':start', $start_date, SQLITE3_TEXT);
        $stmt->bindValue(':end', $end_date, SQLITE3_TEXT);
        $stmt->bindValue(':remark', $remark, SQLITE3_TEXT);
        
        $result = $stmt->execute();
        
        if ($result) {
            // 更新会员表的视频流数（取最新值）
            $db->exec("UPDATE members SET video_streams = $video_streams WHERE id = $id");
            $success = $amount > 0 ? '充值记录添加成功！' : '赠送记录添加成功！';
            header('Location: recharge.php?id=' . $id);
            exit;
        } else {
            $error = '添加失败，请重试！';
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
    <title>会员充值/赠送管理</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; }
        body { background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 10px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; margin-bottom: 20px; }
        .member-info { padding: 15px; background: #f8f9fa; border-radius: 5px; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        textarea { resize: vertical; min-height: 80px; }
        button { background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; font-size: 16px; cursor: pointer; width: 100%; }
        button:hover { background: #218838; }
        .error { color: #dc3545; margin: 10px 0; text-align: center; }
        .success { color: #28a745; margin: 10px 0; text-align: center; }
        .back-link { text-align: center; margin-top: 15px; }
        .back-link a { color: #007bff; text-decoration: none; }
        .form-card { margin-bottom: 30px; padding: 20px; border: 1px solid #eee; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .delete-btn { color: white; background: #dc3545; padding: 5px 10px; border-radius: 5px; text-decoration: none; }
        .delete-btn:hover { background: #c82333; }
        .total-data { font-weight: bold; color: #007bff; margin: 10px 0; }
        .gift-tip { color: #666; font-size: 14px; margin-top: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>会员充值/赠送管理</h1>
        
        <div class="member-info">
            <h3>会员信息</h3>
            <p><strong>用户名：</strong><?php echo $member['username']; ?></p>
            <p><strong>会员等级：</strong><?php echo $member['level']; ?></p>
            <p><strong>当前视频流数：</strong><?php echo $member['video_streams']; ?></p>
            <p class="total-data">累计充值金额：¥<?php echo $totalRecharge; ?></p>
            <p class="total-data">累计赠送月份：<?php echo $totalGiftMonths; ?>个月</p>
        </div>
        
        <!-- 充值/赠送表单 -->
        <div class="form-card">
            <h3>添加充值/赠送记录</h3>
            
            <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <form method="post">
                <div class="form-group">
                    <label for="amount">充值金额 (元)</label>
                    <input type="number" step="0.01" min="0" id="amount" name="amount" value="0" placeholder="0=纯赠送，>0=充值">
                </div>
                <div class="form-group">
                    <label for="months">充值月份</label>
                    <input type="number" min="0" id="months" name="months" value="0" placeholder="0=纯赠送，>0=充值月份">
                    <div class="gift-tip">提示：充值金额和充值月份可同时为0（纯赠送场景）</div>
                </div>
                <div class="form-group">
                    <label for="gift_type">赠送类型</label>
                    <select id="gift_type" name="gift_type" required>
                        <option value="months">月份</option>
                        <option value="days">天数</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="gift_num">赠送数量</label>
                    <input type="number" min="0" id="gift_num" name="gift_num" value="0" placeholder="0=无赠送，>0=赠送数量">
                </div>
                <div class="form-group">
                    <label for="video_streams">视频流数</label>
                    <input type="number" min="0" id="video_streams" name="video_streams" value="<?php echo $member['video_streams']; ?>" placeholder="请输入视频流数量">
                </div>
                <div class="form-group">
                    <label for="start_date">开始日期</label>
                    <input type="date" id="start_date" name="start_date" required value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="form-group">
                    <label for="remark">备注</label>
                    <textarea id="remark" name="remark" placeholder="可选：记录充值/赠送原因"><?php echo $_POST['remark'] ?? ''; ?></textarea>
                </div>
                <button type="submit" name="save">保存记录</button>
            </form>
        </div>
        
        <!-- 充值/赠送记录 -->
        <h3>充值/赠送记录</h3>
        <?php if (empty($recharges)): ?>
        <p style="text-align:center; color:#666; margin:20px 0;">暂无充值/赠送记录</p>
        <?php else: ?>
        <table>
            <tr>
                <th>ID</th>
                <th>类型</th>
                <th>金额/数量</th>
                <th>视频流数</th>
                <th>开始日期</th>
                <th>结束日期</th>
                <th>备注</th>
                <th>操作</th>
            </tr>
            <?php foreach ($recharges as $recharge): ?>
            <tr>
                <td><?php echo $recharge['id']; ?></td>
                <td><?php echo $recharge['recharge_amount'] > 0 ? '充值' : '赠送'; ?></td>
                <td>
                    <?php if ($recharge['recharge_amount'] > 0): ?>
                        ¥<?php echo $recharge['recharge_amount']; ?> (<?php echo $recharge['recharge_months']; ?>个月)
                    <?php else: ?>
                        <?php echo $recharge['gift_months'] > 0 ? $recharge['gift_months'].'个月' : $recharge['gift_days'].'天'; ?>
                    <?php endif; ?>
                </td>
                <td><?php echo $recharge['video_streams']; ?></td>
                <td><?php echo $recharge['start_date']; ?></td>
                <td><?php echo $recharge['end_date']; ?></td>
                <td><?php echo $recharge['remark'] ?? ''; ?></td>
                <td>
                    <a href="?id=<?php echo $id; ?>&delete_recharge=<?php echo $recharge['id']; ?>" class="delete-btn" onclick="return confirm('确定删除该记录？删除后会员周期将同步扣减！')">删除</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
        
        <div class="back-link">
            <a href="index.php">返回管理主页</a>
        </div>
    </div>
</body>
</html>
