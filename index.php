<?php
/* AI-Blog — https://github.com/7133017/ai-blog — MIT */

session_start();
header('Content-Type: text/html; charset=utf-8');

define('DATA_DIR',   __DIR__ . '/data');
define('CFG_FILE',   DATA_DIR . '/config.php');
define('RL_DIR',     DATA_DIR . '/ratelimit');
define('CSRF_KEY',   'csrf_token');
define('PER_PAGE',   10);

// ── Bootstrap dirs & htaccess ────────────────────────────────────────────────
foreach ([DATA_DIR, RL_DIR] as $d) is_dir($d) || mkdir($d, 0755, true);
file_exists(DATA_DIR . '/.htaccess') || file_put_contents(DATA_DIR . '/.htaccess', "Require all denied\n");

// ── Config ───────────────────────────────────────────────────────────────────
$cfg = [];
if (file_exists(CFG_FILE)) { include CFG_FILE; }

// ── DB ───────────────────────────────────────────────────────────────────────
class DB {
    private static ?PDO $pdo = null;

    static function dsn(array $c): array {
        $t = $c['db_type'];
        if ($t === 'sqlite') return ['sqlite:' . (DATA_DIR . '/blog.sqlite'), null, null];
        $h = $c['db_host']; $n = $c['db_name']; $u = $c['db_user']; $p = $c['db_pass'];
        if ($t === 'mysql') return ["mysql:host=$h;port=" . ($c['db_port']?:3306) . ";dbname=$n;charset=utf8mb4", $u, $p];
        if ($t === 'pgsql') return ["pgsql:host=$h;port=" . ($c['db_port']?:5432) . ";dbname=$n", $u, $p];
        die('未知数据库类型');
    }

    static function connect(array $c): void {
        if (self::$pdo) return;
        [$dsn, $u, $p] = self::dsn($c);
        try {
            self::$pdo = new PDO($dsn, $u, $p, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        } catch (PDOException $e) { die('数据库连接失败：' . htmlspecialchars($e->getMessage())); }
        if ($c['db_type'] === 'sqlite') {
            self::$pdo->exec("PRAGMA journal_mode=WAL");
        } elseif ($c['db_type'] === 'mysql') {
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        if ($c['db_type'] === 'mysql') {
            $ai = 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY';
            self::$pdo->exec("CREATE TABLE IF NOT EXISTS posts (id $ai, slug VARCHAR(64) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL, content TEXT NOT NULL, tags VARCHAR(512) NOT NULL DEFAULT '', post_time BIGINT NOT NULL, public TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } else {
            $ai = $c['db_type'] === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
            self::$pdo->exec("CREATE TABLE IF NOT EXISTS posts (id $ai, slug VARCHAR(64) NOT NULL UNIQUE, title VARCHAR(255) NOT NULL, content TEXT NOT NULL, tags VARCHAR(512) NOT NULL DEFAULT '', post_time BIGINT NOT NULL, public TINYINT NOT NULL DEFAULT 1)");
        }
    }

    static function q(string $sql, array $p = []): PDOStatement {
        $st = self::$pdo->prepare($sql); $st->execute($p); return $st;
    }
}

// ── Install ──────────────────────────────────────────────────────────────────
$act = $_GET['a'] ?? '';
if (!file_exists(CFG_FILE)) {
    PHP_VERSION_ID < 70200 && die('需要 PHP 7.2+');
    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['site_name'])) {
        $f = fn($k,$d='') => trim($_POST[$k] ?? $d);
        $db = $f('db_type','sqlite');
        if ($f('site_name')==='' || $f('admin_user')==='' || ($_POST['admin_pass']??'')==='') {
            $msg = '站点名称、用户名、密码均不能为空';
        } elseif (!in_array($db, ['sqlite','mysql','pgsql'], true)) {
            $msg = '请选择有效的数据库类型';
        } else {
            $lp = preg_replace('/[^a-zA-Z0-9_-]/','', $f('login_path')) ?: bin2hex(random_bytes(8));
            $conf = ['site_name'=>$f('site_name'), 'admin_user'=>$f('admin_user'),
                     'admin_pass_hash'=>password_hash($_POST['admin_pass'], PASSWORD_DEFAULT),
                     'login_path'=>$lp, 'db_type'=>$db,
                     'db_host'=>$f('db_host','127.0.0.1'), 'db_port'=>$f('db_port'),
                     'db_name'=>$f('db_name'), 'db_user'=>$f('db_user'), 'db_pass'=>($_POST['db_pass']??'')];
            try { [$dsn,$u,$p] = DB::dsn($conf); new PDO($dsn,$u,$p); }
            catch (PDOException $e) { $msg = '数据库连接测试失败：' . htmlspecialchars($e->getMessage()); }
            if ($msg === '') {
                file_put_contents(CFG_FILE, "<?php\n\$cfg = " . var_export($conf, true) . ";");
                header('Location: ?a=' . urlencode($lp)); exit;
            }
        }
    }
    $sv = fn($k,$d='') => htmlspecialchars($_POST[$k] ?? $d);
    $so = fn($v,$t) => $v===$t ? ' selected' : '';
    ?><!DOCTYPE html><html><head><meta charset="utf-8"><title>初始化博客</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{background:#0d1117;color:#c9d1d9;max-width:440px;margin:4rem auto;padding:1rem;font:14px/1.6 'SFMono-Regular',Consolas,monospace}
label{display:block;margin-top:.8rem;font-size:12px;color:#8b949e}
input,select{width:100%;padding:.5em;margin-top:.2rem;font:inherit;background:#161b22;border:1px solid #30363d;color:#fff;border-radius:6px;box-sizing:border-box;outline:none}
input:focus,select:focus{border-color:#58a6ff}
button{margin-top:1.5rem;width:100%;padding:.6em;font:inherit;font-weight:bold;background:#238636;color:#fff;border:none;border-radius:6px;cursor:pointer}
button:hover{background:#2ea44f}
#db-remote{display:none;margin-top:.4rem}
</style></head><body>
<h2>[#] 初始化博客</h2>
<form method="post">
  <label>站点名称</label><input name="site_name" value="<?=$sv('site_name')?>" required>
  <label>管理员用户名</label><input name="admin_user" value="<?=$sv('admin_user')?>" required>
  <label>管理员密码</label><input name="admin_pass" type="password" required>
  <label>登录入口路径</label><input name="login_path" value="<?=$sv('login_path')?>" placeholder="留空自动生成" maxlength="32">
  <label>数据库类型</label>
  <select name="db_type" id="db_type" onchange="document.getElementById('db-remote').style.display=this.value==='sqlite'?'none':'block'">
    <option value="sqlite"<?=$so($sv('db_type','sqlite'),'sqlite')?>>SQLite（推荐）</option>
    <option value="mysql"<?=$so($sv('db_type'),'mysql')?>>MySQL / MariaDB</option>
    <option value="pgsql"<?=$so($sv('db_type'),'pgsql')?>>PostgreSQL</option>
  </select>
  <div id="db-remote">
    <label>主机</label><input name="db_host" value="<?=$sv('db_host','127.0.0.1')?>">
    <label>端口（留空默认）</label><input name="db_port" value="<?=$sv('db_port')?>">
    <label>数据库名</label><input name="db_name" value="<?=$sv('db_name')?>">
    <label>用户名</label><input name="db_user" value="<?=$sv('db_user')?>">
    <label>密码</label><input name="db_pass" type="password">
  </div>
  <button type="submit">EXEC --save-config</button>
  <?php if($msg): ?><p style="color:#f85149;margin-top:1rem"><?=htmlspecialchars($msg)?></p><?php endif; ?>
</form>
<script>
var t=document.getElementById('db_type');
document.getElementById('db-remote').style.display=t.value==='sqlite'?'none':'block';
</script>
</body></html><?php exit;
}

// ── Boot ─────────────────────────────────────────────────────────────────────
DB::connect($cfg);

// ── Helpers ──────────────────────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s); }

function csrf_token(): string {
    if (empty($_SESSION[CSRF_KEY]) || ($_SESSION[CSRF_KEY.'_exp']??0) < time()) {
        $_SESSION[CSRF_KEY]       = bin2hex(random_bytes(32));
        $_SESSION[CSRF_KEY.'_exp'] = time() + 3600;
    }
    return $_SESSION[CSRF_KEY];
}

function check_csrf(): bool {
    $t = $_POST[CSRF_KEY] ?? '';
    return is_admin() && $t !== '' && hash_equals($_SESSION[CSRF_KEY] ?? '', $t);
}

function is_admin(): bool { return !empty($_SESSION['admin']); }

function rate_ok(string $ip): string|true {
    $file = RL_DIR . '/' . md5($ip) . '.json';
    $now  = time();
    try { $d = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }
    catch (\Throwable) { $d = ['n'=>0,'first'=>$now,'lock'=>0]; }
    if ($d['lock'] > $now) return '登录尝试过多，请 ' . ceil(($d['lock']-$now)/60) . ' 分钟后再试';
    if ($now - $d['first'] >= 1800) $d = ['n'=>0,'first'=>$now,'lock'=>0];
    if (++$d['n'] > 5) $d['lock'] = $now + 1800;
    file_put_contents($file, json_encode($d), LOCK_EX);
    return $d['lock'] > $now ? '登录尝试过多，请 30 分钟后再试' : true;
}

function post_tags(array $row): array {
    return $row['tags'] ? explode(',', $row['tags']) : [];
}

// ── Routing ──────────────────────────────────────────────────────────────────
$login_path = $cfg['login_path'] ?? '';
$is_login_route = ($act === $login_path);
if ($is_login_route) $act = 'login';
if (isset($_GET['a']) && $_GET['a'] === 'login' && $login_path !== 'login') { header('Location: ?'); exit; }

$slug = $_GET['id']  ?? '';
$tag  = $_GET['tag'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$msg  = '';

// ── Auth ─────────────────────────────────────────────────────────────────────
if ($act === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_login_route) { header('Location: ?'); exit; }
    $r = rate_ok($_SERVER['REMOTE_ADDR']);
    if ($r !== true) {
        $msg = $r;
    } elseif ($_POST['user'] === ($cfg['admin_user']??'') && password_verify($_POST['pass'], $cfg['admin_pass_hash']??'')) {
        file_put_contents(RL_DIR . '/' . md5($_SERVER['REMOTE_ADDR']) . '.json', json_encode(['n'=>0,'first'=>time(),'lock'=>0]), LOCK_EX);
        session_regenerate_id(true);
        $_SESSION['admin'] = 1;
        csrf_token();
        header('Location: ?'); exit;
    } else {
        $msg = '用户名或密码错误';
    }
}

if ($act === 'logout') { session_destroy(); header('Location: ?'); exit; }

// ── Save (POST) ───────────────────────────────────────────────────────────────
if ($act === 'save' && is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf() || die('CSRF 校验失败');
    $title   = trim($_POST['title']   ?? '');
    $content = trim($_POST['content'] ?? '');
    $tags    = implode(',', array_slice(array_filter(array_map('trim', explode(',', $_POST['tags'] ?? ''))), 0, 10));
    $public  = isset($_POST['public']) ? 1 : 0;
    $es      = trim($_POST['slug'] ?? '');
    ($title === '' || $content === '') && die('标题和内容不能为空');
    if ($es === '') {
        $es   = date('YmdHis') . bin2hex(random_bytes(3));
        $time = ($_POST['time'] ?? '') !== '' ? (int)strtotime($_POST['time']) : time();
        DB::q('INSERT INTO posts (slug,title,content,tags,post_time,public) VALUES (?,?,?,?,?,?)', [$es,$title,$content,$tags,$time,$public]);
    } else {
        $orig = DB::q('SELECT post_time FROM posts WHERE slug=?', [$es])->fetch();
        $time = ($_POST['time'] ?? '') !== '' ? (int)strtotime($_POST['time']) : (int)($orig['post_time'] ?? time());
        DB::q('UPDATE posts SET title=?,content=?,tags=?,post_time=?,public=? WHERE slug=?', [$title,$content,$tags,$time,$public,$es]);
    }
    header('Location: ?id=' . urlencode($es)); exit;
}

// ── Delete (POST) ─────────────────────────────────────────────────────────────
if ($act === 'delete' && is_admin() && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf() || die('CSRF 校验失败');
    $s = trim($_POST['slug'] ?? '');
    if ($s !== '') DB::q('DELETE FROM posts WHERE slug=?', [$s]);
    header('Location: ?'); exit;
}

// ── View data ────────────────────────────────────────────────────────────────
$show = $slug !== '' ? (function() use ($slug) {
    $r = DB::q('SELECT * FROM posts WHERE slug=?', [$slug])->fetch();
    if ($r) $r['tags'] = post_tags($r);
    return $r ?: null;
})() : null;

$where   = is_admin() ? '' : 'WHERE public=1';
$tfilter = '';
$params  = [];
if ($tag !== '') {
    $et = str_replace(['%','_'], ['\\%','\\_'], $tag);
    $tfilter = is_admin() ? 'WHERE ' : 'AND ';
    $tfilter .= "(tags=? OR tags LIKE ? OR tags LIKE ? OR tags LIKE ?)";
    $params  = [$tag, "$et,%", "%,$et,%", "%,$et"];
}
$all_posts = DB::q("SELECT slug,title,tags,post_time,public FROM posts $where $tfilter ORDER BY post_time DESC", $params)->fetchAll();
foreach ($all_posts as &$r) $r['tags'] = post_tags($r);
unset($r);

$total  = count($all_posts);
$pages  = max(1, (int)ceil($total / PER_PAGE));
$page   = min($page, $pages);
$pslice = array_slice($all_posts, ($page-1)*PER_PAGE, PER_PAGE);

$site_name = h($cfg['site_name'] ?? '');
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title><?= $show ? h($show['title']).' - '.$site_name : $site_name ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php if ($show): ?><meta name="description" content="<?= h(mb_substr(strip_tags($show['content']),0,120)) ?>"><?php endif; ?>
<style>
:root {
  --term-bg: #0d1117;       /* 核心背景黑 */
  --term-txt: #c9d1d9;      /* 文本主色白 */
  --term-ac: #58a6ff;       /* 链接高亮蓝 */
  --term-dim: #8b949e;      /* 注释/元数据灰 */
  --term-success: #7ee787;  /* 提示符/公开绿 */
  --term-warn: #f0883e;     /* 警告/草稿橙 */
  --term-line: #21262d;     /* 极细网格线 */
  --font-mono: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, Courier, monospace;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--term-bg);
  color: var(--term-txt);
  font-family: var(--font-mono);
  font-size: 14px;
  line-height: 1.6;
  padding: max(2.5rem, env(safe-area-inset-top)) 1.5rem max(2.5rem, env(safe-area-inset-bottom));
  -webkit-font-smoothing: antialiased;
}
a { color: var(--term-ac); text-decoration: none; }
a:hover { text-decoration: underline; background: rgba(88, 166, 255, 0.1); }

.terminal { max-width: 800px; margin: 0 auto; }

/* ── Shell Header ── */
.term-header { padding-bottom: 1rem; border-bottom: 1px dashed var(--term-line); margin-bottom: 2.5rem; }
.term-header .prompt { color: var(--term-success); font-weight: bold; }
.term-header .cmd { color: #fff; font-weight: bold; }
.term-header .comment { color: var(--term-dim); display: block; font-size: 12px; margin-top: 0.2rem; }

/* ── Section & Rows ── */
.term-section { margin-bottom: 2.5rem; }
.term-year { color: var(--term-warn); font-weight: bold; margin-bottom: 1rem; font-size: 15px; }
.term-row { display: flex; align-items: baseline; padding: 0.65rem 0; border-bottom: 1px solid var(--term-line); gap: 1.5rem; overflow: auto; }
.term-row:last-child { border-bottom: none; }
.term-meta { color: var(--term-dim); white-space: nowrap; flex-shrink: 0; font-variant-numeric: tabular-nums; }
.term-title { font-weight: bold; white-space: nowrap; }
.term-desc { color: var(--term-dim); font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }

/* ── Tags / Draft Status ── */
.term-status { color: var(--term-warn); font-size: 11px; font-weight: bold; border: 1px solid var(--term-warn); padding: 0 4px; border-radius: 3px; text-transform: uppercase; margin-right: 4px; }
.term-row.draft .term-title { color: var(--term-dim); text-decoration: line-through; }
.term-tag-link { color: var(--term-success) !important; background: rgba(126,231,135,0.08); padding: 1px 6px; border-radius: 4px; font-size: 12px; }

/* ── Article View ── */
.art-date { color: var(--term-dim); font-size: 13px; margin-bottom: 0.5rem; }
.art-title { font-size: 26px; color: #fff; font-weight: bold; margin-bottom: 1.5rem; }
.art-meta-bar { padding-bottom: 1rem; border-bottom: 1px dashed var(--term-line); margin-bottom: 2rem; display: flex; gap: 0.8rem; flex-wrap: wrap; align-items: center; }
article { font-size: 15px; line-height: 1.8; color: #e6edf3; white-space: pre-wrap; word-break: break-word; }

/* ── Hardcore Cards (Login & Edit) ── */
.term-field { margin-bottom: 1.2rem; }
.term-field:last-child { margin-bottom: 0; }
.term-field label { display: block; color: var(--term-dim); font-size: 12px; margin-bottom: 0.4rem; }
.term-card input[type=text], .term-card input[type=password], .term-card input[type=datetime-local], .term-card textarea {
  width: 100%; border: 1px solid var(--term-line); background: var(--term-bg); color: #fff; font-family: var(--font-mono); font-size: 14px; padding: 0.6rem; border-radius: 6px; outline: none; box-sizing: border-box;
}
.term-card input:focus, .term-card textarea:focus { border-color: var(--term-ac); }
.term-card textarea { min-height: 280px; resize: vertical; line-height: 1.6; }

/* ── Buttons ── */
.btn-term { background: #21262d; color: var(--term-ac); border: 1px solid var(--term-line); padding: 0.5rem 1.2rem; font-family: var(--font-mono); font-size: 13px; font-weight: bold; border-radius: 6px; cursor: pointer; }
.btn-term:hover { background: var(--term-line); color: #fff; }
.btn-submit { background: #238636; color: #fff; border: 1px solid #2ea44f; }
.btn-submit:hover { background: #2ea44f; }
.btn-danger { color: #f85149; }
.btn-danger:hover { background: rgba(248,81,73,0.1); color: #f85149; }

/* ── Console Footer ── */
.term-footer { margin-top: 4rem; padding-top: 1.5rem; border-top: 1px dashed var(--term-line); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; color: var(--term-dim); font-size: 13px; }
.term-nav { display: flex; align-items: center; gap: 0.8rem; }
.term-nav a { color: var(--term-ac); }
.term-nav a.active { color: #fff; font-weight: bold; pointer-events: none; }

@media (max-width: 580px) {
  .term-meta, .term-desc { display: none; }
  .term-row { justify-content: space-between; }
}
</style>
</head>
<body>
<?php
function fmt_date(int $ts): string { return date('m-d H:i', $ts); }
function fmt_year(int $ts): string { return date('Y', $ts); }
?>

<div class="terminal">
<?php if ($act === 'tags'):
    $tag_counts = [];
    $all = DB::q(is_admin() ? 'SELECT tags FROM posts' : 'SELECT tags FROM posts WHERE public=1')->fetchAll();
    foreach ($all as $r) foreach (post_tags($r) as $t) if ($t !== '') $tag_counts[$t] = ($tag_counts[$t] ?? 0) + 1;
    arsort($tag_counts);
    $tag_total = count($tag_counts);
?>
  <header class="term-header">
    <span class="prompt">guest@ai-blog:~ $</span> <span class="cmd">view --all-tags</span>
    <span class="comment">Found <?= $tag_total ?> dynamic indexing keys.</span>
  </header>

  <section class="term-section">
    <div class="term-year">[#] Index / Tags</div>
    <?php if ($tag_counts): foreach ($tag_counts as $t => $n): ?>
    <div class="term-row">
      <span class="term-meta">COUNT: <?= sprintf('%02d', $n) ?></span>
      <a href="?tag=<?= urlencode($t) ?>" class="term-title term-tag-link">#<?= h($t) ?></a>
      <span class="term-desc">// 查看含有此标签的归档序列</span>
    </div>
    <?php endforeach; else: ?>
    <p style="color:var(--term-dim)">// STDOUT: 暂无任何标签记录</p>
    <?php endif; ?>
  </section>

  <footer class="term-footer">
    <div class="term-info">Command executed successfully.</div>
    <nav class="term-nav"><a href="?">&lt;-- 返回主页</a></nav>
  </footer>

<?php elseif ($act === 'login'): ?>
  <header class="term-header">
    <span class="prompt">auth@system:~ $</span> <span class="cmd">sudo login --admin</span>
    <span class="comment">RESTRICTED AREA: Requires valid hash authentication credentials.</span>
  </header>

  <div class="term-card" style="max-width: 400px; margin: 0 auto;">
    <form method="post">
      <div class="term-field">
        <label>LOGIN_USER</label>
        <input name="user" required autocomplete="username" placeholder="输入管理员账户">
      </div>
      <div class="term-field">
        <label>PASSWORD_HASH</label>
        <input name="pass" type="password" required autocomplete="current-password" placeholder="输入通行密码">
      </div>
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <button class="btn-term btn-submit" style="width:100%; margin-top:0.5rem;">EXEC --auth</button>
    </form>
  </div>
  <?php if ($msg): ?><p style="color:var(--term-warn); text-align:center; margin-top:1.5rem; font-weight:bold;">[ERROR] <?= h($msg) ?></p><?php endif; ?>

  <footer class="term-footer">
    <div class="term-info">TTY: /dev/pts/1</div>
    <nav class="term-nav"><a href="?">放弃并返回</a></nav>
  </footer>

<?php elseif ($act === 'edit' && is_admin()):
    $e = $slug ? DB::q('SELECT * FROM posts WHERE slug=?', [$slug])->fetch() : null;
    if ($slug && !$e) { echo '<p style="color:var(--term-warn)">[ERROR] 指定文章序列不存在。<a href="?">返回首页</a></p>'; goto done; }
    $e = $e ?: ['slug'=>'','title'=>'','content'=>'','tags'=>'','post_time'=>time(),'public'=>1];
    $e['tags_str'] = is_array($e['tags']) ? implode(',', $e['tags']) : $e['tags'];
    $is_new = ($e['slug'] === '');
?>
  <header class="term-header">
    <span class="prompt">admin@ai-blog:~ $</span> <span class="cmd"><?= $is_new ? 'vim --new-post' : 'vim ./posts/'.h($e['slug']).'.md' ?></span>
    <span class="comment">Editing data layout buffer inside client-side core.</span>
  </header>

  <div class="term-card">
    <form method="post" action="?a=save">
      <div class="term-field">
        <label>POST_TITLE (标题)</label>
        <input name="title" value="<?= h($e['title']) ?>" placeholder="输入文章标题..." required>
      </div>
      <div class="term-field">
        <label>POST_CONTENT (正文 Markdown / Plaintext)</label>
        <textarea name="content" placeholder="在此输入正文内容..." required><?= h($e['content']) ?></textarea>
      </div>
      <div class="term-field">
        <label>TAGS (标签 - 逗号分隔，最多 10 个)</label>
        <input name="tags" value="<?= h($e['tags_str']) ?>" placeholder="code, life, notes">
      </div>
      <div class="term-field" style="display: flex; gap: 1.5rem; align-items: center; flex-wrap: wrap;">
        <div>
          <label>POST_TIME (发布时间)</label>
          <input type="datetime-local" name="time" value="<?= date('Y-m-d\TH:i', $e['post_time']) ?>">
        </div>
        <div style="margin-top: 1rem;">
          <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
            <input type="checkbox" name="public" value="1" <?= $e['public'] ? 'checked' : '' ?> style="accent-color:var(--term-success)">
            SET_PUBLIC (公开发布)
          </label>
        </div>
      </div>
      <input type="hidden" name="slug" value="<?= h($e['slug']) ?>">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div style="margin-top: 1.5rem; display: flex; gap: 1rem;">
        <button class="btn-term btn-submit">:wq (保存数据)</button>
        <a href="<?= $is_new ? '?' : '?id='.urlencode($e['slug']) ?>" class="btn-term" style="line-height:2.3;">:q! (取消变更)</a>
      </div>
    </form>
  </div>

<?php elseif ($show): ?>
  <header class="term-header">
    <span class="prompt">guest@ai-blog:~ $</span> <span class="cmd">cat ./posts/<?= h($show['slug']) ?>.md</span>
    <span class="comment">Reading row payload from database architecture.</span>
  </header>

  <div class="art-date">TIMESTAMP: <?= date('Y-m-d H:i:s UTC', $show['post_time']) ?></div>
  <h1 class="art-title"><?= h($show['title']) ?></h1>

  <div class="art-meta-bar">
    <?php if (!$show['public']): ?><span class="term-status">DRAFT</span><?php endif; ?>
    <?php foreach ($show['tags'] as $t): ?>
      <a href="?tag=<?= urlencode($t) ?>" class="term-tag-link">#<?= h($t) ?></a>
    <?php endforeach; ?>
  </div>

  <article><?= h($show['content']) ?></article>

  <footer class="term-footer">
    <div class="term-nav">
      <a href="<?= $tag ? '?tag='.urlencode($tag) : '?' ?>">&lt;-- cd .. (返回列表)</a>
    </div>
    <?php if (is_admin()): ?>
    <div class="term-nav" style="gap:1.5rem;">
      <a href="?a=edit&id=<?= urlencode($show['slug']) ?>">[Edit / 编辑]</a>
      <form method="post" action="?a=delete" onsubmit="return confirm('确定执行 RM -RF 彻底删除此项？')" style="display:contents">
        <input type="hidden" name="slug" value="<?= h($show['slug']) ?>">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <button class="btn-term btn-danger" style="padding:0.2rem 0.6rem; font-size:12px;">rm ./post</button>
      </form>
    </div>
    <?php endif; ?>
  </footer>

<?php else: ?>
  <header class="term-header">
    <span class="prompt">guest@<?= $site_name ?: 'ai-blog' ?>:~ $</span> 
    <span class="cmd"><?= $tag ? 'ls -la ./posts/tags/'.h($tag) : 'ls -la --sort=time ./posts' ?></span>
    <span class="comment">Total <?= $total ?> documents synchronized. Current sync: <?= date('Y-m-d H:i:s') ?> UTC</span>
  </header>

  <?php if ($tag): ?>
  <div style="margin-bottom: 2rem; color:var(--term-warn); font-weight:bold;">
    # ACTIVE_FILTER: Focused on tag [ #<?= h($tag) ?> ] <a href="?" style="color:var(--term-dim); margin-left:1rem;">[× Clear Filter]</a>
  </div>
  <?php endif; ?>

  <?php
  $cur_year = '';
  foreach ($pslice as $p):
      $y = fmt_year($p['post_time']);
      if (!$tag && $y !== $cur_year): $cur_year = $y; ?>
      <?php if($cur_year !== date('Y')): ?></div></section><?php endif; ?> <section class="term-section">
        <div class="term-year">[#] Year: <?= $y ?></div>
  <?php endif; ?>
    
    <div class="term-row <?= (is_admin() && !$p['public']) ? 'draft' : '' ?>">
      <span class="term-meta"><?= fmt_date($p['post_time']) ?></span>
      
      <?php if (is_admin() && !$p['public']): ?><span class="term-status">draft</span><?php endif; ?>
      
      <a class="term-title" href="?id=<?= urlencode($p['slug']) ?><?= $tag ? '&tag='.urlencode($tag) : '' ?>">
        ./<?= h($p['title']) ?>.md
      </a>
      
      <span class="term-desc">
        <?php if($p['tags']): ?>// tags: <?= h(implode(', ', $p['tags'])) ?><?php else: ?>// no metadata description<?php endif; ?>
      </span>
    </div>
  <?php endforeach; ?>
  <?php if (!empty($pslice)): ?></section><?php endif; ?>
  
  <?php if (empty($pslice)): ?><p style="color:var(--term-dim); padding:1rem 0">// STDOUT: Empty dataset. 暂无任何公开日志</p><?php endif; ?>

  <footer class="term-footer">
    <div class="term-info">STDOUT: Page <?= $page ?>/<?= $pages ?> (<?= count($pslice) ?>/<?= $total ?> rows)</div>
    <nav class="term-nav">
      <?php if ($pages > 1): ?>
        <span style="color:var(--term-dim)">Goto:</span>
        <?php for ($i = 1; $i <= $pages; $i++): $qs = $tag ? 'tag='.urlencode($tag).'&page='.$i : 'page='.$i; ?>
          <a href="?<?= $qs ?>" class="<?= $i === $page ? 'active' : '' ?>">[<?= $i ?>]</a>
        <?php endfor; ?>
      <?php endif; ?>
      
      <a href="?a=tags" style="margin-left: 1rem; color:var(--term-success)">[All_Tags]</a>
      <?php if (is_admin()): ?>
        <a href="?a=edit" style="color:var(--term-warn); font-weight:bold; margin-left:1rem;">[✏️ Write]</a>
        <a href="?a=logout" class="btn-danger" onclick="return confirm('确定注销终端管理会话？')" style="margin-left:1rem;">[Logout]</a>
      <?php endif; ?>
    </nav>
  </footer>
<?php endif; ?>

<?php done: ?>
</div>
</body>
</html>
