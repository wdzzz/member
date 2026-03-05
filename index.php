<?php
// 连接数据库
$db = new SQLite3('member.db');

// 获取即将到期的会员（30天内）
$warningMembers = [];
$today = date('Y-m-d');
$warningDate = date('Y-m-d', strtotime('+30 days'));

$stmt = $db->prepare('
    SELECT m.username, m.level, m.video_streams, r.end_date, 
    (julianday(r.end_date) - julianday(:today)) as remaining_days
    FROM members m
    JOIN recharge r ON m.id = r.member_id
    WHERE r.end_date BETWEEN :today AND :warning_date
    ORDER BY remaining_days ASC
');
$stmt->bindValue(':today', $today, SQLITE3_TEXT);
$stmt->bindValue(':warning_date', $warningDate, SQLITE3_TEXT);
$result = $stmt->execute();

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $warningMembers[] = $row;
}

// 定义日志记录函数（新增 $remainingDays 参数）
function logQuery($username, $ip, $status, $remainingDays = '', $message = '') {
    // 日志文件路径（根目录下的 query_log.txt）
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/query_log.txt';
    
    // 记录时间（精确到秒）
    $time = date('Y-m-d H:i:s');
    
    // 构造日志内容（添加剩余日期字段）
    $logContent = "[{$time}] IP: {$ip} | 查询用户名: {$username} | 剩余日期: {$remainingDays} | 状态: {$status} | 备注: {$message}\n";
    
    // 写入日志（追加模式，文件不存在则自动创建）
    // 使用 FILE_APPEND 追加内容，LOCK_EX 加锁防止并发写入冲突
    file_put_contents($logFile, $logContent, FILE_APPEND | LOCK_EX);
}

// 获取用户真实IP（兼容代理/反向代理场景）
function getRealIp() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        // 多个IP时取第一个
        $ip = explode(',', $ip)[0];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    // 过滤非法IP格式
    return filter_var(trim($ip), FILTER_VALIDATE_IP) ?: '未知IP';
}

// 处理查询请求
$memberData = null;
$error = '';
$queryUsername = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['query'])) {
    $queryUsername = trim($_POST['username']);
    $userIp = getRealIp(); // 获取用户IP
    
    if (empty($queryUsername)) {
        $error = '请输入用户名！';
        // 记录空查询日志（剩余日期为空）
        logQuery($queryUsername, $userIp, '失败', '', '输入的用户名为空');
    } else {
        $stmt = $db->prepare('SELECT * FROM members WHERE username = :username');
        $stmt->bindValue(':username', $queryUsername, SQLITE3_TEXT);
        $member = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        if (!$member) {
            $error = '未找到该会员信息！';
            // 记录查询失败日志（剩余日期为空）
            logQuery($queryUsername, $userIp, '失败', '', '未找到该会员信息');
        } else {
            $stmt = $db->prepare('
                SELECT * FROM recharge 
                WHERE member_id = :member_id 
                ORDER BY recharge_time DESC
            ');
            $stmt->bindValue(':member_id', $member['id'], SQLITE3_INTEGER);
            $recharges = $stmt->execute();
            
            $totalRecharge = 0;
            $totalGiftMonths = 0;
            $totalGiftDays = 0;
            $latestEndDate = '无记录';
            $remainingDaysText = '无记录';
            $isDiamond = ($member['level'] == '钻石会员');
            
            $memberRecharges = [];
            while ($recharge = $recharges->fetchArray(SQLITE3_ASSOC)) {
                $totalRecharge += $recharge['recharge_amount'];
                $totalGiftMonths += $recharge['gift_months'];
                $totalGiftDays += $recharge['gift_days'] ?? 0;
                $memberRecharges[] = $recharge;
                
                if ($latestEndDate === '无记录' || $recharge['end_date'] > $latestEndDate) {
                    $latestEndDate = $recharge['end_date'];
                }
            }
            
            if ($isDiamond) {
                $remainingDaysText = '永久有效';
                $latestEndDate = '永久有效';
            } else {
                if ($latestEndDate !== '无记录') {
                    $todayTs = strtotime($today);
                    $endDateTs = strtotime($latestEndDate);
                    $diffDays = floor(($endDateTs - $todayTs) / 86400);
                    
                    if ($diffDays > 0) {
                        $remainingDaysText = "剩余{$diffDays}天";
                    } elseif ($diffDays == 0) {
                        $remainingDaysText = "今日到期";
                    } else {
                        $remainingDaysText = "已到期（" . abs($diffDays) . "天）";
                    }
                }
            }
            
            $memberData = [
                'info' => $member,
                'recharges' => $memberRecharges,
                'total_recharge' => round($totalRecharge, 2),
                'total_gift_months' => $totalGiftMonths,
                'total_gift_days' => $totalGiftDays,
                'latest_end_date' => $latestEndDate,
                'remaining_days_text' => $remainingDaysText
            ];
            
            // 记录查询成功日志（传入剩余日期）
            logQuery($queryUsername, $userIp, '成功', $remainingDaysText, '查询到会员信息，等级：'.$member['level']);
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
    <title>影视会员</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="/favicon.ico" type="image/x-icon">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: Arial, sans-serif; }
        body { background: #f5f5f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; border-radius: 10px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; margin-bottom: 20px; }
        .warning { background: #fff3cd; border: 1px solid #ffeeba; color: #856404; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .warning ul { margin-left: 20px; margin-top: 10px; }
        .warning li { margin: 5px 0; }
        .query-form { margin: 20px 0; padding: 20px; border: 1px solid #eee; border-radius: 5px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 16px; }
        button { background: #007bff; color: white; border: none; padding: 10px 20px; border-radius: 5px; font-size: 16px; cursor: pointer; width: 100%; }
        button:hover { background: #0056b3; }
        .result { margin-top: 20px; padding: 20px; border: 1px solid #eee; border-radius: 5px; }
        .error { color: #dc3545; text-align: center; }
        .admin-link { text-align: center; margin-top: 20px; }
        .admin-link a { color: #007bff; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .total-data { margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 5px; }
        .forever { color: #009933; font-weight: bold; }
        .expired { color: #dc3545; font-weight: bold; }
        .expire-today { color: #ffc107; font-weight: bold; }
        .no-record { color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <h1>影视会员统计系统</h1>
        
        <?php if (!empty($warningMembers)): ?>
        <div class="warning">
            <strong>⚠️ 会员到期提醒</strong>
            <ul>
                <?php foreach ($warningMembers as $member): ?>
                <li>
                    会员：<?php echo $member['username']; ?> (<?php echo $member['level']; ?>) 
                    - 视频流数：<?php echo $member['video_streams']; ?>
                    - 剩余天数：<?php echo (int)$member['remaining_days']; ?>天
                    - 到期日：<?php echo $member['end_date']; ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <div class="query-form">
            <h2 style="text-align:center; margin-bottom:15px;">会员查询</h2>
            <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
            <?php endif; ?>
            <form method="post">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($queryUsername); ?>">
                </div>
                <button type="submit" name="query">查询</button>
            </form>
        </div>
        
        <?php if ($memberData): ?>
        <div class="result">
            <h2>会员信息</h2>
            <p><strong>用户名：</strong><?php echo $memberData['info']['username']; ?></p>
            <p><strong>会员等级：</strong><?php echo $memberData['info']['level']; ?></p>
            <p><strong>视频流数：</strong><?php echo $memberData['info']['video_streams']; ?></p>
            <p><strong>注册时间：</strong><?php echo $memberData['info']['create_time']; ?></p>
            <p><strong>最新到期日：</strong>
                <?php if ($memberData['info']['level'] == '钻石会员'): ?>
                    <span class="forever">永久有效</span>
                <?php else: ?>
                    <?php echo $memberData['latest_end_date']; ?>
                <?php endif; ?>
            </p>
            <p><strong>剩余日期：</strong>
                <?php if ($memberData['info']['level'] == '钻石会员'): ?>
                    <span class="forever">永久有效</span>
                <?php elseif (strpos($memberData['remaining_days_text'], '已到期') !== false): ?>
                    <span class="expired"><?php echo $memberData['remaining_days_text']; ?></span>
                <?php elseif (strpos($memberData['remaining_days_text'], '今日到期') !== false): ?>
                    <span class="expire-today"><?php echo $memberData['remaining_days_text']; ?></span>
                <?php elseif ($memberData['remaining_days_text'] === '无记录'): ?>
                    <span class="no-record">无记录</span>
                <?php else: ?>
                    <?php echo $memberData['remaining_days_text']; ?>
                <?php endif; ?>
            </p>
            
            <div class="total-data">
                <p><strong>累计充值金额：</strong>¥<?php echo $memberData['total_recharge']; ?></p>
                <p><strong>累计赠送月份：</strong><?php echo $memberData['total_gift_months']; ?>个月</p>
                <p><strong>累计赠送天数：</strong><?php echo $memberData['total_gift_days']; ?>天</p>
            </div>
            
            <h3 style="margin-top:15px;">充值/赠送记录</h3>
            <table>
                <tr>
                    <th>类型</th>
                    <th>金额/数量</th>
                    <th>视频流数</th>
                    <th>开始日期</th>
                    <th>结束日期</th>
                    <th>操作时间</th>
                </tr>
                <?php foreach ($memberData['recharges'] as $recharge): ?>
                <tr>
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
                    <td><?php echo $recharge['recharge_time']; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>
        
        <div class="admin-link">
            <a href="admin/login.php">管理员入口</a>
        </div>
    </div>
</body>
</html>