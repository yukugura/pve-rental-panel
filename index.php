<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
]);

const ROOT = __DIR__;
const CONFIG_FILE = ROOT . '/config/config.php';

function esc(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function flash(string $message, string $type = 'ok'): void { $_SESSION['flash'] = [$type, $message]; }
function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function verify_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('CSRF verification failed.'); } }
function config(): array { return require CONFIG_FILE; }
function db(array $c): PDO {
    static $pdo = null; if ($pdo instanceof PDO) return $pdo;
    $pdo = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $pdo;
}
function stmt(PDO $db, string $sql, array $params = []): PDOStatement { $s = $db->prepare($sql); $s->execute($params); return $s; }
function user(PDO $db): ?array { if (empty($_SESSION['uid'])) return null; return stmt($db, 'SELECT id, username, role FROM users WHERE id=?', [(int)$_SESSION['uid']])->fetch() ?: null; }
function require_login(PDO $db): array { $u = user($db); if (!$u) redirect('?page=login'); return $u; }
function admin(PDO $db): array { $u = require_login($db); if ($u['role'] !== 'admin') { http_response_code(403); exit('管理者権限が必要です。'); } return $u; }
function pve(array $c, string $method, string $path, array $data = []): mixed {
    $url = rtrim($c['pve_url'], '/') . '/api2/json' . $path;
    $ch = curl_init($url);
    $headers = ['Authorization: PVEAPIToken=' . $c['pve_token_id'] . '=' . $c['pve_token_secret']];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => (bool)$c['pve_verify_tls']]);
    if ($method !== 'GET' && $data) { curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data)); $headers[] = 'Content-Type: application/x-www-form-urlencoded'; curl_setopt($ch, CURLOPT_HTTPHEADER, $headers); }
    $raw = curl_exec($ch); $error = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    $json = json_decode((string)$raw, true);
    if ($raw === false || $code < 200 || $code >= 300 || !is_array($json)) {
        $details = is_array($json) ? json_encode($json['errors'] ?? $json) : substr((string)$raw, 0, 300);
        throw new RuntimeException('Proxmox API error (' . $code . '): ' . ($error ?: $details));
    }
    return $json['data'] ?? [];
}
function wait_task(array $c, string $node, string $upid): void {
    for ($i = 0; $i < 60; $i++) { $task = pve($c, 'GET', '/nodes/' . rawurlencode($node) . '/tasks/' . rawurlencode($upid) . '/status'); if (($task['status'] ?? '') === 'stopped') { if (($task['exitstatus'] ?? '') !== 'OK') throw new RuntimeException('Proxmox task failed: ' . ($task['exitstatus'] ?? 'unknown')); return; } sleep(1); }
    throw new RuntimeException('VMの作成が時間切れになりました。Proxmoxのタスク一覧で進行状況を確認してください。');
}
function reserve_node(PDO $db, string $node): void {
    $lock = stmt($db, 'SELECT GET_LOCK(?, 15) locked', ['rental-provision-' . $node])->fetch();
    if ((int)($lock['locked'] ?? 0) !== 1) throw new RuntimeException('同じノードで別の作成処理が進行中です。少し待ってから再試行してください。');
}
function release_node(PDO $db, string $node): void { stmt($db, 'SELECT RELEASE_LOCK(?)', ['rental-provision-' . $node]); }
function assert_capacity(PDO $db, array $c, array $plan): void {
    // 予約済み容量で過剰販売を防ぎ、同時にProxmoxの実測負荷も見る。
    $nodeStatus = pve($c, 'GET', '/nodes/' . rawurlencode($plan['node']) . '/status');
    $reserved = stmt($db, 'SELECT COALESCE(SUM(p.cores),0) AS cores, COALESCE(SUM(p.memory_mb),0) AS memory_mb FROM servers s JOIN plans p ON p.id=s.plan_id WHERE s.node=? AND s.status != "deleted"', [$plan['node']])->fetch();
    $reservedDisk = stmt($db, 'SELECT COALESCE(SUM(p.disk_gb),0) AS disk_gb FROM servers s JOIN plans p ON p.id=s.plan_id WHERE s.node=? AND p.storage=? AND s.status != "deleted"', [$plan['node'], $plan['storage']])->fetch();
    $storage = pve($c, 'GET', '/nodes/' . rawurlencode($plan['node']) . '/storage/' . rawurlencode($plan['storage']) . '/status');
    $hostMemMb = (int)(($nodeStatus['maxmem'] ?? 0) / 1024 / 1024);
    $hostCores = (int)($nodeStatus['maxcpu'] ?? 0);
    $usedMem = (int)($nodeStatus['mem'] ?? 0);
    $cpuUsage = (float)($nodeStatus['cpu'] ?? 0);
    if ($hostMemMb < 1 || $hostCores < 1) throw new RuntimeException('Proxmoxからノード容量を取得できません。');
    // ホストOS・ディスクキャッシュ・突発負荷のため、販売上限はメモリ85%、CPU80%。
    $memLimit = (int)floor($hostMemMb * 0.85);
    $cpuLimit = (int)floor($hostCores * 0.80);
    if ((int)$reserved['memory_mb'] + (int)$plan['memory_mb'] > $memLimit) throw new RuntimeException("メモリの予約上限に達したため作成できません（上限 {$memLimit}MB）。");
    if ((int)$reserved['cores'] + (int)$plan['cores'] > $cpuLimit) throw new RuntimeException("CPUの予約上限に達したため作成できません（上限 {$cpuLimit}コア）。");
    if ($usedMem > (int)(($nodeStatus['maxmem'] ?? 0) * 0.92)) throw new RuntimeException('現在のホストメモリ使用率が92%を超えているため、保護のため作成を受け付けません。');
    if ($cpuUsage > 0.92) throw new RuntimeException('現在のホストCPU使用率が92%を超えているため、保護のため作成を受け付けません。');
    $storageTotalGb = (float)($storage['total'] ?? 0) / 1024 / 1024 / 1024;
    $storageAvailGb = (float)($storage['avail'] ?? 0) / 1024 / 1024 / 1024;
    if ($storageTotalGb < 1) throw new RuntimeException('Proxmoxからストレージ容量を取得できません。');
    if ((float)$reservedDisk['disk_gb'] + (int)$plan['disk_gb'] > $storageTotalGb * 0.85) throw new RuntimeException('ディスクの予約上限に達したため作成できません。');
    if ($storageAvailGb < (int)$plan['disk_gb'] || $storageAvailGb < $storageTotalGb * 0.08) throw new RuntimeException('ストレージの実空き容量が不足しているため作成できません。');
}
function layout(string $title, ?array $u, string $body): never {
    $notice = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    echo '<!doctype html><html lang="ja"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc($title) . '</title><link rel="stylesheet" href="style.css"><body><header><a class="brand" href="?">Proxmox Rental</a>';
    if ($u) echo '<nav><a href="?">サーバー</a>' . ($u['role']==='admin' ? '<a href="?page=admin">管理</a>' : '') . '<a href="?page=logout">ログアウト</a></nav>';
    echo '</header><main>' . ($notice ? '<div class="notice ' . esc($notice[0]) . '">' . esc($notice[1]) . '</div>' : '') . $body . '</main><footer>自宅サーバー向け管理パネル</footer></body></html>'; exit;
}

if (!file_exists(CONFIG_FILE)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $required = ['db_host','db_name','db_user','pve_url','pve_token_id','pve_token_secret','admin_user','admin_pass']; foreach ($required as $k) if (trim($_POST[$k] ?? '') === '') $error = 'すべての必須項目を入力してください。';
        if (!isset($error)) try {
            $c = ['db_host'=>trim($_POST['db_host']),'db_name'=>trim($_POST['db_name']),'db_user'=>trim($_POST['db_user']),'db_pass'=>(string)($_POST['db_pass'] ?? ''),'pve_url'=>rtrim(trim($_POST['pve_url']), '/'),'pve_token_id'=>trim($_POST['pve_token_id']),'pve_token_secret'=>trim($_POST['pve_token_secret']),'pve_verify_tls'=>isset($_POST['pve_verify_tls'])];
            $pdo = db($c); $sql = file_get_contents(ROOT . '/schema.sql'); $pdo->exec($sql);
            stmt($pdo, 'INSERT INTO users (username,password_hash,role) VALUES (?,?,"admin")', [trim($_POST['admin_user']), password_hash($_POST['admin_pass'], PASSWORD_DEFAULT)]);
            if (!is_dir(ROOT . '/config') || !is_writable(ROOT . '/config')) throw new RuntimeException('config ディレクトリを書き込み可能にしてください。');
            file_put_contents(CONFIG_FILE, "<?php\nreturn " . var_export($c, true) . ";\n"); chmod(CONFIG_FILE, 0600);
            flash('セットアップが完了しました。ログインしてください。'); redirect('?page=login');
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
    $e = isset($error) ? '<div class="notice error">' . esc($error) . '</div>' : '';
    layout('初期セットアップ', null, '<section class="card narrow"><h1>初期セットアップ</h1>' . $e . '<p>MariaDBの空データベースを作成してから入力してください。ProxmoxはAPIトークンを使います。</p><form method="post"><label>DBホスト<input name="db_host" value="localhost" required></label><label>DB名<input name="db_name" required></label><label>DBユーザー<input name="db_user" required></label><label>DBパスワード<input type="password" name="db_pass"></label><label>Proxmox URL<input name="pve_url" placeholder="https://192.168.1.10:8006" required></label><label>API Token ID<input name="pve_token_id" placeholder="panel@pve!rental" required></label><label>API Token Secret<input type="password" name="pve_token_secret" required></label><label class="check"><input type="checkbox" name="pve_verify_tls" checked> ProxmoxのTLS証明書を検証する</label><hr><label>管理者ユーザー名<input name="admin_user" required></label><label>管理者パスワード<input type="password" name="admin_pass" minlength="12" required></label><button>セットアップ</button></form></section>');
}

$c = config(); $db = db($c); $page = $_GET['page'] ?? 'servers';
if ($page === 'logout') { session_destroy(); redirect('?page=login'); }
if ($page === 'login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { verify_csrf(); $row = stmt($db, 'SELECT * FROM users WHERE username=?', [trim($_POST['username'] ?? '')])->fetch(); if ($row && password_verify($_POST['password'] ?? '', $row['password_hash'])) { session_regenerate_id(true); $_SESSION['uid']=$row['id']; redirect('?'); } flash('ユーザー名またはパスワードが違います。', 'error'); redirect('?page=login'); }
    layout('ログイン', null, '<section class="card narrow"><h1>ログイン</h1><form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><label>ユーザー名<input name="username" required autofocus></label><label>パスワード<input type="password" name="password" required></label><button>ログイン</button></form></section>');
}
$u = require_login($db);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf(); $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $plan = stmt($db, 'SELECT * FROM plans WHERE id=? AND enabled=1', [(int)$_POST['plan_id']])->fetch(); if (!$plan) throw new RuntimeException('プランが見つかりません。');
            $count = (int)stmt($db, 'SELECT COUNT(*) n FROM servers WHERE user_id=? AND status != "deleted"', [$u['id']])->fetch()['n']; if ($count >= 5) throw new RuntimeException('作成可能なサーバー数（5台）に達しています。');
            $name = preg_replace('/[^a-zA-Z0-9-]/', '-', trim($_POST['name'] ?? '')); if (!preg_match('/^[a-zA-Z][a-zA-Z0-9-]{2,30}$/', $name)) throw new RuntimeException('名前は英数字とハイフンで3〜31文字にしてください。');
            $node = $plan['node']; reserve_node($db, $node);
            try {
                assert_capacity($db, $c, $plan);
                $vmid = (int)pve($c, 'GET', '/cluster/nextid');
                $upid = pve($c, 'POST', '/nodes/'.rawurlencode($node).'/qemu/'.(int)$plan['template_vmid'].'/clone', ['newid'=>$vmid,'name'=>$name,'full'=>1,'storage'=>$plan['storage'] ?: null]);
                wait_task($c, $node, $upid);
                $vmConfig = pve($c, 'GET', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/config');
                foreach (['scsi0', 'virtio0', 'sata0', 'ide0'] as $diskKey) {
                    if (!empty($vmConfig[$diskKey]) && preg_match('/size=([0-9.]+)([KMGT])/i', (string)$vmConfig[$diskKey], $m)) {
                        $unit = strtoupper($m[2]);
                        $currentGb = (float)$m[1] * match ($unit) { 'T' => 1024, 'M' => 1 / 1024, 'K' => 1 / 1048576, default => 1 };
                        $extraGb = (int)$plan['disk_gb'] - $currentGb;
                        if ($extraGb > 0) pve($c, 'PUT', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/resize', ['disk'=>$diskKey, 'size'=>'+' . (int)ceil($extraGb) . 'G']);
                        break;
                    }
                }
                pve($c, 'PUT', '/nodes/'.rawurlencode($node).'/qemu/'.$vmid.'/config', ['cores'=>(int)$plan['cores'],'memory'=>(int)$plan['memory_mb'],'balloon'=>(int)$plan['memory_mb'],'onboot'=>1]);
                stmt($db, 'INSERT INTO servers (user_id,plan_id,node,vmid,name,status) VALUES (?,?,?,?,?,"stopped")', [$u['id'],$plan['id'],$node,$vmid,$name]);
            } finally { release_node($db, $node); }
            flash("{$name} を作成しました。起動してSSHで接続できます。"); redirect('?');
        }
        if (in_array($action, ['start','stop','shutdown','delete'], true)) {
            $server = stmt($db, 'SELECT * FROM servers WHERE id=? AND user_id=?', [(int)$_POST['server_id'],$u['id']])->fetch(); if (!$server) throw new RuntimeException('対象サーバーが見つかりません。');
            $node=rawurlencode($server['node']); $vmid=(int)$server['vmid'];
            if ($action === 'delete') { $current=pve($c,'GET',"/nodes/$node/qemu/$vmid/status/current"); if (($current['status'] ?? '') !== 'stopped') throw new RuntimeException('削除前に通常停止してください。停止反映後に削除できます。'); pve($c,'DELETE',"/nodes/$node/qemu/$vmid"); stmt($db, 'UPDATE servers SET status="deleted" WHERE id=?', [$server['id']]); flash('削除を要求しました。'); }
            else { pve($c,'POST',"/nodes/$node/qemu/$vmid/status/$action"); flash('操作を要求しました。反映まで少し待つことがあります。'); }
            redirect('?');
        }
        if ($action === 'admin_user') { admin($db); $role = $_POST['role']==='admin' ? 'admin':'user'; stmt($db,'INSERT INTO users (username,password_hash,role) VALUES (?,?,?)',[trim($_POST['username']),password_hash($_POST['password'],PASSWORD_DEFAULT),$role]); flash('利用者を追加しました。'); redirect('?page=admin'); }
        if ($action === 'admin_plan') { admin($db); stmt($db,'INSERT INTO plans (name,node,template_vmid,storage,cores,memory_mb,disk_gb,enabled) VALUES (?,?,?,?,?,?,?,1)',[trim($_POST['name']),trim($_POST['node']),(int)$_POST['template_vmid'],trim($_POST['storage']),(int)$_POST['cores'],(int)$_POST['memory_mb'],(int)$_POST['disk_gb']]); flash('プランを追加しました。'); redirect('?page=admin'); }
    }
} catch (Throwable $e) { flash($e->getMessage(), 'error'); redirect($page === 'admin' ? '?page=admin' : '?'); }

if ($page === 'admin') {
    admin($db); $users=stmt($db,'SELECT id,username,role,created_at FROM users ORDER BY id')->fetchAll(); $plans=stmt($db,'SELECT * FROM plans ORDER BY id DESC')->fetchAll();
    try { $pveNodes=pve($c, 'GET', '/nodes'); $pveCheck='<section class="card"><h2>Proxmox 接続診断</h2><p class="status">接続成功 — '.count($pveNodes).' ノードを取得しました。</p></section>'; }
    catch (Throwable $e) { $pveCheck='<section class="card"><h2>Proxmox 接続診断</h2><p class="notice error">接続失敗: '.esc($e->getMessage()).'</p><p class="muted">Proxmox URL、API Token ID／Secret、TLS証明書設定、ネットワークを確認してください。</p></section>'; }
    $usersHtml=''; foreach($users as $x) $usersHtml.='<tr><td>'.esc($x['username']).'</td><td>'.esc($x['role']).'</td><td>'.esc($x['created_at']).'</td></tr>';
    $plansHtml=''; foreach($plans as $x) $plansHtml.='<tr><td>'.esc($x['name']).'</td><td>'.esc($x['node']).'</td><td>'.$x['cores'].'c / '.$x['memory_mb'].'MB / '.$x['disk_gb'].'GB</td><td>template '.$x['template_vmid'].'</td></tr>';
    layout('管理', $u, '<h1>管理</h1>'.$pveCheck.'<div class="grid"><section class="card"><h2>利用者を追加</h2><form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="admin_user"><label>ユーザー名<input name="username" required></label><label>初期パスワード<input type="password" name="password" minlength="12" required></label><label>権限<select name="role"><option value="user">利用者</option><option value="admin">管理者</option></select></label><button>追加</button></form></section><section class="card"><h2>プランを追加</h2><form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="admin_plan"><label>プラン名<input name="name" placeholder="Standard 2GB" required></label><label>Proxmoxノード<input name="node" placeholder="pve1" required></label><label>テンプレートVMID<input type="number" name="template_vmid" required></label><label>保存先ストレージ<input name="storage" placeholder="local-lvm" required></label><div class="row"><label>CPUコア<input type="number" name="cores" min="1" value="1" required></label><label>メモリMB<input type="number" name="memory_mb" min="512" value="2048" required></label><label>ディスクGB<input type="number" name="disk_gb" min="1" value="20" required></label></div><button>プラン追加</button></form></section></div><section class="card"><h2>登録済みプラン</h2><table><tr><th>名前</th><th>ノード</th><th>リソース</th><th>元VM</th></tr>'.$plansHtml.'</table></section><section class="card"><h2>利用者</h2><table><tr><th>ユーザー</th><th>権限</th><th>作成日</th></tr>'.$usersHtml.'</table></section>');
}
$servers=stmt($db, 'SELECT s.*,p.name plan_name FROM servers s JOIN plans p ON p.id=s.plan_id WHERE s.user_id=? AND s.status != "deleted" ORDER BY s.id DESC', [$u['id']])->fetchAll(); $plans=stmt($db,'SELECT * FROM plans WHERE enabled=1 ORDER BY id')->fetchAll();
$cards=''; foreach($servers as $s) { $status='不明'; try { $info=pve($c,'GET','/nodes/'.rawurlencode($s['node']).'/qemu/'.(int)$s['vmid'].'/status/current'); $status=($info['status']??'unknown').($info['uptime']??false ? ' / 稼働中':''); } catch(Throwable $e){$status='Proxmoxへ接続できません';} $cards.='<section class="card server"><h2>'.esc($s['name']).'</h2><p>'.esc($s['plan_name']).' · VMID '.$s['vmid'].' · '.esc($s['node']).'</p><p class="status">'.esc($status).'</p><form method="post" class="actions"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="server_id" value="'.$s['id'].'"><button name="action" value="start">起動</button><button name="action" value="shutdown" class="secondary">通常停止</button><button name="action" value="stop" class="danger">強制停止</button><button name="action" value="delete" class="danger" onclick="return confirm(\'本当に削除しますか？ 元に戻せません。\')">削除</button></form></section>'; }
$options=''; foreach($plans as $p) $options.='<option value="'.$p['id'].'">'.esc($p['name']).' — '.$p['cores'].'c / '.$p['memory_mb'].'MB / '.$p['disk_gb'].'GB</option>';
layout('サーバー', $u, '<div class="heading"><h1>マイサーバー</h1><button onclick="document.getElementById(\'create\').showModal()">＋ サーバー作成</button></div><div class="grid">'.($cards ?: '<section class="card"><p>まだサーバーはありません。</p></section>').'</div><dialog id="create"><form method="post"><input type="hidden" name="csrf" value="'.csrf().'"><input type="hidden" name="action" value="create"><h2>サーバーを作成</h2><label>サーバー名<input name="name" placeholder="my-server" pattern="[A-Za-z][A-Za-z0-9-]{2,30}" required></label><label>プラン<select name="plan_id">'.$options.'</select></label><p class="muted">作成後、OSテンプレートに登録済みのSSH鍵または初期設定で接続してください。</p><div class="actions"><button>作成する</button><button type="button" class="secondary" onclick="this.closest(\'dialog\').close()">キャンセル</button></div></form></dialog>');
