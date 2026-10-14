<?php
require __DIR__ . '/lib.php';
session_start();
$cfg = cfg();

if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }
$lip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lock_until = (int)state_get('loginlock:' . $lip, 0);
$fail_n = (int)state_get('loginfail:' . $lip, 0);
if (time() < $lock_until) {
    $login_err = '失败次数过多，已锁定 ' . ceil(($lock_until - time()) / 60) . ' 分钟';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pwd'])) {
    $expect_user = (string)($cfg['username'] ?? 'admin');
    if (hash_equals($expect_user, (string)($_POST['user'] ?? '')) && hash_equals((string)$cfg['password'], (string)$_POST['pwd'])) {
        state_set('loginfail:' . $lip, 0);
        $_SESSION['subguard_ok'] = 1;
        header('Location: index.php');
        exit;
    }
    $fail_n++;
    state_set('loginfail:' . $lip, $fail_n);
    if ($fail_n >= 5) {
        state_set('loginlock:' . $lip, time() + 600);
        state_set('loginfail:' . $lip, 0);
        $login_err = '密码错误 5 次，已锁定 10 分钟';
    } else {
        $login_err = "密码错误（再错 " . (5 - $fail_n) . " 次锁定 10 分钟）";
    }
}
if (empty($_SESSION['subguard_ok'])) {
    ?>
    <!DOCTYPE html><html lang="zh"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SubGuard 登录</title>
    <style>
        body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:#0f172a;font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
        .box{background:#fff;border-radius:14px;padding:38px 42px;width:300px;box-shadow:0 10px 40px rgba(0,0,0,.3)}
        h1{margin:0 0 6px;font-size:20px;color:#1f2328} p{margin:0 0 20px;font-size:13px;color:#57606a}
        input{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;border:1px solid #d0d7de;background:#f6f8fa;color:#1f2328;font-size:14px}
        button{margin-top:14px;width:100%;padding:10px;border:0;border-radius:8px;background:#2563eb;color:#fff;font-size:14px;cursor:pointer}
        .err{color:#d1242f;font-size:13px;margin-top:10px}
    </style></head><body>
    <form class="box" method="post">
        <h1>SubGuard</h1><p>订阅异常监控台</p>
        <input type="text" name="user" placeholder="用户名" value="admin" autocomplete="username" style="margin-bottom:8px">
        <input type="password" name="pwd" placeholder="密码" autofocus autocomplete="current-password">
        <button type="submit">进入</button>
        <?php if (!empty($login_err)) echo '<div class="err">', htmlspecialchars($login_err), '</div>'; ?>
    </form></body></html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SubGuard 订阅监控台</title>
<style>
:root{--bg:#f6f8fa;--panel:#fff;--border:#e2e8f0;--txt:#1f2937;--sub:#64748b;--blue:#2563eb;--red:#dc2626;--orange:#b45309;--green:#16a34a;--side:#1e293b;--side-txt:#cbd5e1;--side-hover:#334155;--side-on:#2563eb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font:14px/1.6 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif;display:flex;min-height:100vh}
/* ===== 侧边栏 ===== */
aside{width:208px;background:var(--side);color:var(--side-txt);flex-shrink:0;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
aside .logo{padding:18px 18px 14px;font-size:16px;font-weight:600;color:#fff;border-bottom:1px solid var(--side-hover)}
aside .logo span{display:block;font-size:11px;font-weight:400;color:#7c8aa5;margin-top:2px}
nav{flex:1;padding:10px 0;overflow-y:auto}
nav a{display:flex;align-items:center;gap:10px;padding:10px 18px;color:var(--side-txt);text-decoration:none;font-size:13.5px;border-left:3px solid transparent}
nav a:hover{background:var(--side-hover);color:#fff}
nav a.on{background:var(--side-hover);color:#fff;border-left-color:var(--side-on)}
aside .ver{padding:12px 18px;font-size:11px;color:#64748b;border-top:1px solid var(--side-hover)}
/* ===== 主区 ===== */
.wrap{flex:1;min-width:0;display:flex;flex-direction:column}
header{display:flex;align-items:center;gap:12px;padding:12px 22px;background:#fff;border-bottom:1px solid var(--border);position:sticky;top:0;z-index:9}
header h1{font-size:16px;margin:0}
.lights{display:flex;gap:14px;font-size:12px;color:var(--sub)}
.lights b{font-weight:600}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:4px;vertical-align:middle}
.dot.g{background:var(--green)}.dot.r{background:var(--red)}.dot.y{background:var(--orange)}
header .sp{flex:1}
header a{color:var(--sub);text-decoration:none;font-size:13px}
main{padding:18px 22px 60px;max-width:1360px;width:100%}
.page{display:none}
.page.on{display:block}
/* ===== 组件 ===== */
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;margin-bottom:16px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:14px 16px;box-shadow:0 1px 2px rgba(15,23,42,.04)}
.card .n{font-size:24px;font-weight:700}
.card .l{font-size:12px;color:var(--sub)}
.card.bad .n{color:var(--red)}
section.sec{background:var(--panel);border:1px solid var(--border);border-radius:12px;margin-bottom:16px;overflow:hidden;box-shadow:0 1px 2px rgba(15,23,42,.04)}
section.sec>h2{margin:0;padding:12px 16px;font-size:14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
section.sec h2 .tag{font-size:11px;font-weight:400;color:var(--sub)}
.pad{padding:14px 16px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 14px;text-align:left;border-bottom:1px solid #eef2f7;vertical-align:top}
th{color:var(--sub);font-weight:500;font-size:12px;white-space:nowrap;background:#fafbfc}
tr:last-child td{border-bottom:0}
tr.warn td{background:rgba(220,38,38,.05)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
td .ua{color:var(--sub);font-size:12px;word-break:break-all}
.pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px}
.pill.red{background:rgba(220,38,38,.1);color:var(--red)}
.pill.orange{background:rgba(180,83,9,.12);color:var(--orange)}
.pill.green{background:rgba(22,163,74,.12);color:var(--green)}
.pill.blue{background:rgba(37,99,235,.1);color:var(--blue)}
.btn{cursor:pointer;color:var(--blue);background:none;border:0;padding:0;font-size:12px}
.btn:hover{text-decoration:underline}
.btn2{cursor:pointer;font-size:13px;border:1px solid var(--border);background:#fff;padding:6px 16px;border-radius:7px;color:var(--txt)}
.btn2:hover{border-color:var(--blue);color:var(--blue)}
.btn2.pri{background:var(--blue);border-color:var(--blue);color:#fff}
.btn2.pri:hover{opacity:.9;color:#fff}
.btn2.red{color:var(--red);border-color:#fecaca}
.mask{cursor:pointer}
.empty{padding:24px;text-align:center;color:var(--sub)}
input[type=text],input[type=number],input[type=password],select{padding:7px 10px;border:1px solid var(--border);border-radius:7px;font-size:13px;background:#fff;color:var(--txt)}
textarea{width:100%;background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:8px;font-family:ui-monospace,monospace;font-size:12px;resize:vertical}
#warn{display:none;background:rgba(220,38,38,.06);border:1px solid var(--red);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:13px}
.sortbar{display:flex;gap:6px;margin-left:auto}
.sortbar button{font-size:11px;border:1px solid var(--border);background:#fff;border-radius:6px;padding:2px 10px;cursor:pointer;color:var(--sub)}
.sortbar button.on{border-color:var(--blue);color:var(--blue);background:rgba(37,99,235,.06)}
details.ua-stat summary{cursor:pointer;padding:12px 16px;font-size:14px;font-weight:600;user-select:none}
details.ua-stat[open] summary{border-bottom:1px solid var(--border)}
.modal{position:fixed;inset:0;background:rgba(15,23,42,.5);display:none;align-items:flex-start;justify-content:center;padding:60px 16px;z-index:99}
.modal.show{display:flex}
.modal .box{background:#fff;border-radius:12px;max-width:880px;width:100%;max-height:75vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.2)}
.modal h3{margin:0;padding:14px 18px;border-bottom:1px solid var(--border);font-size:14px;display:flex;gap:10px;align-items:center}
.modal .x{margin-left:auto;cursor:pointer;color:var(--sub)}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:8px 18px;border-radius:8px;display:none;font-size:13px;z-index:100}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px}
label.ck{display:inline-flex;align-items:center;gap:5px;margin-right:14px;font-size:13px}
#t_out{background:#0f172a;color:#c9d1d9;border:1px solid var(--border)}
@media(max-width:860px){
    body{flex-direction:column}
    aside{width:100%;height:auto;flex-direction:row;align-items:center;position:sticky;top:0;z-index:20}
    aside .logo{border:0;padding:10px 14px}
    aside .logo span,aside .ver{display:none}
    nav{display:flex;overflow-x:auto;padding:0}
    nav a{white-space:nowrap;border-left:0;border-bottom:3px solid transparent;padding:10px 12px}
    nav a.on{border-bottom-color:var(--side-on)}
}
</style>
</head>
<body>

<aside>
    <div class="logo" id="logo-name"><?= htmlspecialchars(state_get('panel_name', 'SubGuard')) ?><span>订阅监控台</span></div>
    <nav id="nav">
        <a data-p="dash" class="on">📊 总览</a>
        <a data-p="logsrc">📥 日志源</a>
        <a data-p="guard">🛡 处置中心</a>
        <a data-p="bl">🚫 黑名单</a>
        <a data-p="wl">⚪ 白名单</a>
        <a data-p="locks">🔒 蜜罐</a>
        <a data-p="acts">📝 处置记录</a>
        <a data-p="rules">⚙️ 规则设置</a>
        <a data-p="sys">🔧 系统设置</a>
    </nav>
    <div class="ver">数据保留 <?= (int)$cfg['keep_days'] ?> 天</div>
</aside>

<div class="wrap">
<header>
    <h1 id="page-title">总览</h1>
    <div class="lights">
        <span><i class="dot g" id="scan-dot"></i><span id="scaninfo">加载中…</span></span>
        <span id="light-tunnel" style="display:none"><i class="dot r"></i><b>隧道未连接</b></span>
        <span id="light-engine" style="display:none"></span>
    </div>
    <span class="sp"></span>
    <a href="javascript:load(true)" style="margin-right:12px">立即刷新</a>
    <a href="?logout=1">退出</a>
</header>
<main>

<!-- ========== 总览 ========== -->
<div class="page on" id="page-dash">
    <div id="warn"></div>
    <div class="cards">
        <div class="card"><div class="n" id="c_total">-</div><div class="l">24h 订阅请求</div></div>
        <div class="card bad"><div class="n" id="c_ab">-</div><div class="l">异常 UA 请求</div></div>
        <div class="card"><div class="n" id="c_abtok">-</div><div class="l">涉异常 token 数</div></div>
        <div class="card"><div class="n" id="c_4xx">-</div><div class="l">4xx 探测</div></div>
        <div class="card"><div class="n" id="c_acts">-</div><div class="l">24h 处置（已执行）</div></div>
        <div class="card bad"><div class="n" id="c_locks">-</div><div class="l">套餐锁名单（待审核）</div></div>
    </div>
    <div id="act_sum_bar" style="display:none;background:#fff;border:1px solid var(--border);border-radius:12px;padding:10px 16px;margin:-6px 0 16px;font-size:13px;color:var(--sub)"></div>

    <section class="sec">
        <h2>异常 UA 实时流 <span class="tag">命中爬虫/服务器/转换站 UA 的订阅拉取</span></h2>
        <table><thead><tr><th>时间</th><th>IP</th><th>Token</th><th>flag</th><th>命中规则</th><th>UA</th></tr></thead><tbody id="stream"></tbody></table>
    </section>

    <section class="sec">
        <h2>涉异常 Token 排行 <span class="tag">命中越多越可疑</span>
            <span class="sortbar"><button data-t="abtok" data-k="hits">按命中</button><button data-t="abtok" data-k="last">按最新</button></span></h2>
        <table><thead><tr><th>#</th><th>Token</th><th>异常命中</th><th>涉及IP</th><th>UA种类</th><th>最近</th><th>UA 明细</th></tr></thead><tbody id="abtokens"></tbody></table>
    </section>

    <section class="sec">
        <h2>Token 独立 IP 排行榜 <span class="tag">≥<?= (int)$cfg['token_ip_warn'] ?> 个 IP 标红 = 疑似转售</span>
            <span class="sortbar"><button data-t="tok" data-k="ips">按IP数</button><button data-t="tok" data-k="hits">按请求</button><button data-t="tok" data-k="last">按最新</button></span></h2>
        <table><thead><tr><th>#</th><th>Token</th><th>独立IP</th><th>请求数</th><th>首次</th><th>最近</th><th>主要 UA</th></tr></thead><tbody id="tokens"></tbody></table>
    </section>

    <section class="sec">
        <h2>可疑 IP 名单 <span class="tag">多拉token / 异常UA高频 / 高频请求</span>
            <span class="sortbar"><button data-t="sus" data-k="hits">按次数</button><button data-t="sus" data-k="tokens">按token数</button></span></h2>
        <table><thead><tr><th>IP</th><th>归属</th><th>拉取token数</th><th>异常UA命中</th><th>请求数</th><th>最近活动</th><th>操作</th></tr></thead><tbody id="ips"></tbody></table>
    </section>

    <details class="sec ua-stat">
        <summary>📁 异常 UA 类型统计（点击展开）</summary>
        <table><thead><tr><th>UA</th><th>次数</th></tr></thead><tbody id="uarank"></tbody></table>
    </details>
</div>

<!-- ========== 日志源 ========== -->
<div class="page" id="page-logsrc">
    <div class="card">
        <b>添加远程日志源</b> <span class="tag" style="color:var(--sub);font-size:11px">如：订阅服务器在中国，SubGuard 在香港 → 这里填中国机的信息，日志自动拉过来监控</span><br><br>
        名称 <input type="text" id="ls_name" placeholder="订阅服务器日志" style="width:150px">
        远程IP <input type="text" id="ls_host" placeholder="139.196.180.104" style="width:130px">
        SSH端口 <input type="number" id="ls_port" value="22" style="width:64px"><br><br>
        用户名 <input type="text" id="ls_user" value="root" style="width:90px">
        远程日志路径 <input type="text" id="ls_rpath" placeholder="/www/wwwlogs/subscribe.log" style="width:260px">
        每 <input type="number" id="ls_mins" value="1" style="width:50px"> 分钟同步<br><br>
        <button class="btn2 pri" onclick="lsAdd()">添加</button>
        <span id="ls_msg" style="font-size:12px;color:var(--sub)"></span>
    </div>
    <section class="sec" style="margin-top:14px">
        <h2>已配置的日志源</h2>
        <table><thead><tr><th>名称</th><th>远程</th><th>路径</th><th>同步频率</th><th>本地落盘</th><th></th></tr></thead><tbody id="ls_list"></tbody></table>
    </section>
    <div class="card" id="ls_script_card" style="display:none">
        <b>同步命令</b> <span class="tag" style="color:var(--sub);font-size:11px">在 SubGuard 服务器终端执行一次（会问一次远程密码），之后自动同步</span><br>
        <textarea id="ls_script" rows="6" readonly style="margin-top:8px;background:#0f172a;color:#c9d1d9"></textarea>
        <button class="btn2" onclick="copy(document.getElementById('ls_script').value)">复制命令</button>
    </div>
</div>

<!-- ========== 处置中心 ========== -->
<div class="page" id="page-guard">
    <div class="grid2">
        <div class="card">
            <b>面板库连接</b> <span class="pill blue" id="guard_tunnel">检测中…</span><br><br>
            <label class="ck"><input type="checkbox" id="db_en"> 启用连接</label><br><br>
            主机 <input type="text" id="db_host" value="127.0.0.1" style="width:100px">
            端口 <input type="number" id="db_port" value="3307" style="width:70px"><br><br>
            库名 <input type="text" id="db_name" style="width:130px" placeholder="面板库名">
            表前缀 <input type="text" id="db_prefix" value="v2_" style="width:60px"><br><br>
            用户名 <input type="text" id="db_user" value="subguard" style="width:100px">
            密码 <input type="password" id="db_pass" style="width:150px" placeholder="宝塔生成的密码"><br><br>
            <button class="btn2" onclick="saveDbCfg()">保存连接</button>
            <button class="btn2" onclick="testDb()">测试连接</button>
            <span id="db_msg" style="font-size:12px"></span>
        </div>
        <div class="card">
            <b>隧道搭建助手</b> <span class="tag" style="color:var(--sub);font-size:11px">生成命令 → 到 SubGuard 服务器终端粘贴执行</span><br><br>
            面板服务器IP <input type="text" id="t_ip" style="width:130px" placeholder="47.242.172.166">
            SSH端口 <input type="number" id="t_port" value="22" style="width:64px">
            本地端口 <input type="number" id="t_lport" value="3307" style="width:64px">
            <button class="btn2" onclick="genTunnel()">生成</button><br>
            <textarea id="t_out" rows="9" readonly style="margin-top:8px" placeholder="生成的命令显示在这里"></textarea>
            <button class="btn2" onclick="copyTunnel()">复制命令</button>
            <span id="t_msg" style="font-size:12px;color:var(--sub)"></span>
        </div>
    </div>
        <div class="card" style="margin-top:14px">
            <b>Telegram 通知</b> <span class="tag" style="color:var(--sub);font-size:11px">引擎每轮有实际处置时推送（干跑不推）。需本机可访问 api.telegram.org（国外服务器OK，国内需代理）</span><br><br>
            <label class="ck"><input type="checkbox" id="tg_en"> 启用通知</label>　
            Bot Token <input type="password" id="tg_token" style="width:260px" placeholder="123456:ABC-DEF..."><br><br>
            Chat ID <input type="text" id="tg_chatid" style="width:160px" placeholder="如 123456789">
            <button class="btn2" onclick="tgTest()">测试发送</button>
            <span id="tg_msg" style="font-size:12px"></span>
        </div>
    <div class="card" style="margin-top:14px">
        <b>引擎</b>　
        <label class="ck"><input type="checkbox" id="g_enabled"> 启用自动处置</label>
        <label class="ck"><input type="checkbox" id="g_dry"> 干跑（只记录）</label>
        <label class="ck"><input type="checkbox" id="g_ruuid"> 处置时重置UUID</label>
        <label class="ck"><input type="checkbox" id="g_autolock"> 触发即自动加套餐锁（事后审核）</label>
        　<button class="btn2" onclick="saveRules()">保存设置</button>
        <button class="btn2 pri" onclick="runGuard()">立即执行一次</button>
        <span id="guard_msg" style="font-size:12px;color:var(--sub)"></span>
        <div style="margin-top:10px;font-size:12px;color:var(--sub)">阈值与规则在左侧「规则设置」里配置。处置记录见「处置记录」页。</div>
    </div>
</div>

<!-- ========== 黑名单 ========== -->
<div class="page" id="page-bl">
    <div class="card" style="margin-bottom:14px;display:flex;gap:8px;align-items:center">
        <b style="margin-right:6px">搜索名单</b>
        <input type="text" id="bl_q" placeholder="按值/备注过滤全部名单" style="width:260px" oninput="blRender()">
        <span style="font-size:12px;color:var(--sub)">输入即过滤，五个名单同时筛</span>
    </div>
    <div class="grid2">
        <div class="card">
            <b>Token 黑名单</b> <span class="pill orange">命中即锁定套餐</span><br><br>
            <textarea id="blt_in" rows="4" placeholder="一行一条，可附备注：&#10;ccbbb58512622172c04f549f994dcd23 转售号一号"></textarea>
            <div style="margin-top:6px"><button class="btn2 pri" onclick="blAdd('bl_tokens','blt_in')">导入</button>
                <button class="btn2" onclick="blExport('bl_tokens')">导出</button></div>
            <table style="margin-top:10px"><thead><tr><th>Token</th><th>备注</th><th>加入时间</th><th></th></tr></thead><tbody id="list-bl_tokens"></tbody></table>
        </div>
        <div class="card">
            <b>账号黑名单（按邮箱）</b> <span class="pill orange">命中即锁定套餐</span><br><br>
            <textarea id="bla_in" rows="4" placeholder="一行一个邮箱：&#10;ruby13824234991@qq.com 内鬼一号"></textarea>
            <div style="margin-top:6px"><button class="btn2 pri" onclick="blAdd('bl_accounts','bla_in')">导入</button>
                <button class="btn2" onclick="blExport('bl_accounts')">导出</button></div>
            <table style="margin-top:10px"><thead><tr><th>邮箱</th><th>备注</th><th>加入时间</th><th></th></tr></thead><tbody id="list-bl_accounts"></tbody></table>
        </div>
        <div class="card">
            <b>IP 黑名单</b> <span class="pill orange">命中即锁定套餐（支持CIDR段）</span><br><br>
            <textarea id="bli_in" rows="4" placeholder="一行一个IP：&#10;103.204.174.78 机房扫描&#10;45.88.42.62 转售fleet"></textarea>
            <div style="margin-top:6px"><button class="btn2 pri" onclick="blAdd('bl_ips','bli_in')">导入</button>
                <button class="btn2" onclick="blExport('bl_ips')">导出</button></div>
            <table style="margin-top:10px"><thead><tr><th>IP</th><th>备注</th><th>加入时间</th><th></th></tr></thead><tbody id="list-bl_ips"></tbody></table>
        </div>
    </div>
</div>

<!-- ========== 白名单 ========== -->
<div class="page" id="page-wl">
    <div class="card">
        <b>Token 白名单</b> <span class="pill green">豁免处置</span> <span class="tag" style="color:var(--sub);font-size:11px">路由器用户、监控探活等误伤高发号放这里</span><br><br>
        <textarea id="wl_in" rows="4" placeholder="一行一个token：&#10;8e23b82fa17ff2dfda77fedf8a41ab1b 路由器用户"></textarea>
        <div style="margin-top:6px"><button class="btn2 pri" onclick="blAdd('wl_tokens','wl_in')">导入</button>
                <button class="btn2" onclick="blExport('wl_tokens')">导出</button></div>
        <table style="margin-top:10px"><thead><tr><th>Token</th><th>备注</th><th>加入时间</th><th></th></tr></thead><tbody id="list-wl_tokens"></tbody></table>
    </div>
    <div class="card" style="margin-top:14px">
        <b>IP 白名单</b> <span class="pill green">自家出口/代理IP豁免</span> <span class="tag" style="color:var(--sub);font-size:11px">名单内IP不计入"单IP多Token"和"独立IP数"统计</span><br><br>
        <textarea id="wlip_in" rows="4" placeholder="一行一个IP：&#10;152.175.31.228 自家HK入口&#10;13.212.106.254 AWS入口1"></textarea>
        <div style="margin-top:6px"><button class="btn2 pri" onclick="blAdd('wl_ips','wlip_in')">导入</button>
                <button class="btn2" onclick="blExport('wl_ips')">导出</button></div>
        <table style="margin-top:10px"><thead><tr><th>IP</th><th>备注</th><th>加入时间</th><th></th></tr></thead><tbody id="list-wl_ips"></tbody></table>
    </div>
</div>

<!-- ========== 套餐锁 ========== -->
<div class="page" id="page-locks">
    <div class="card">
        <b>套餐锁</b> <span class="tag" style="color:var(--sub);font-size:11px">被收押用户无论购买什么套餐，都会被自动拉回蜜罐</span><br><br>
        <input type="text" id="lock_input" placeholder="用户邮箱或ID" style="width:200px">
        <input type="number" id="lock_plan" placeholder="锁定套餐ID" style="width:120px">
        <input type="text" id="lock_note" placeholder="备注(可选)" style="width:150px">
        <button class="btn2 pri" onclick="lockAdd()">加锁</button>
        <span style="float:right;display:flex;gap:6px">
            <input type="text" id="lock_q" placeholder="搜邮箱/ID/备注" style="width:200px" onkeydown="if(event.key==='Enter')locksSearch()">
            <button class="btn2" onclick="locksSearch()">搜索</button>
            <button class="btn2" onclick="document.getElementById('lock_q').value='';locksLoad()">重置</button>
        </span>
        <span id="lock_msg" style="font-size:12px;color:var(--sub)"></span>
        <table style="margin-top:12px"><thead><tr><th>用户</th><th>锁定套餐</th><th>备注</th><th>加锁时间</th><th></th></tr></thead><tbody id="lock_list"></tbody></table>
    </div>
</div>

<!-- ========== 处置记录 ========== -->
<div class="page" id="page-acts">
    <section class="sec">
        <h2>处置记录 <span class="tag">dry=干跑 done=已执行 rev=已撤销</span>
            <span style="margin-left:auto;display:flex;gap:6px">
                <input type="text" id="act_q" placeholder="搜账号/邮箱/token/原因" style="width:220px" onkeydown="if(event.key==='Enter')actionsSearch()">
                <button class="btn2" onclick="actionsSearch()">搜索</button>
                <button class="btn2" onclick="document.getElementById('act_q').value='';actionsLoad()">重置</button>
            </span></h2>
        <div style="padding:10px 16px;display:flex;gap:8px;align-items:center;border-bottom:1px solid var(--border)">
            <label class="ck"><input type="checkbox" id="act_all" onchange="actToggleAll(this.checked)"> 全选本页</label>
            <button class="btn2" onclick="actBatchRevert()">撤销所选</button>
            <button class="btn2" style="color:var(--red);border-color:#fecaca" onclick="actBatchDel()">删除所选</button>
            <span id="act_pageinfo" style="margin-left:auto;font-size:12px;color:var(--sub)"></span>
            <button class="btn2" onclick="actPageGo(-1)">‹ 上一页</button>
            <button class="btn2" onclick="actPageGo(1)">下一页 ›</button>
        </div>
        <table><thead><tr><th></th><th>时间</th><th>账号</th><th>动作</th><th>详情</th><th>状态</th><th></th></tr></thead><tbody id="act_list"></tbody></table>
    </section>
</div>

<!-- ========== 规则设置 ========== -->
<div class="page" id="page-rules">
    <div class="card">
        <b>处置规则</b><br><br>
        蜜罐套餐ID <input type="number" id="g_plan" style="width:90px">　
        
        移套餐阈值（异常UA≥） <input type="number" id="g_abn" style="width:80px">　
        移套餐阈值（IP≥） <input type="number" id="g_ips" style="width:70px">　
        执行间隔（秒） <input type="number" id="g_int" style="width:80px">　
        <label class="ck"><input type="checkbox" id="g_defense"> 自动防御</label>
        单IP≥ <input type="number" id="g_defense_hits" style="width:70px"> 次/24h<br><br>
        <span style="font-size:12px;color:var(--sub)">自动防御开启后：高频 IP 自动拉黑 + 其触碰的订阅全部重置（UUID+token）+ TG 通知。白名单内的 IP/用户豁免。误伤成本高，建议配合白名单使用。</span><br><br>
        <b>节点哨兵</b>　密钥 <input id="g_nodekey" style="width:230px">　<button class="btn2" type="button" onclick="document.getElementById('g_nodekey').value=Array.from(crypto.getRandomValues(new Uint8Array(16))).map(b=>b.toString(16).padStart(2,'0')).join('')">随机生成</button><br>
        <span style="font-size:12px;color:var(--sub)">哨兵装在节点上，测活警报 POST 回 node_hook.php 时验此密钥。jk/tk 两站请设同一把；装节点时安装脚本会要求输入同一把。留空 = 关闭节点警报接口。</span><br><br>
        <button class="btn2 pri" onclick="saveRules()">保存规则</button>
        <span id="rules_msg" style="font-size:12px;color:var(--sub)"></span>
    </div>
    <div class="card" style="margin-top:14px">
        <b>自定义 UA 黑名单</b> <span class="tag" style="color:var(--sub);font-size:11px">一行一条特征，命中即标记异常（与内置规则合并生效，全站扫描即时应用）</span><br><br>
        <textarea id="custom_ua" rows="6" placeholder="例如：&#10;Chrome/61.0&#10;Go-http-client/2&#10;python-urllib"></textarea>
        <div style="margin-top:6px">
            <button class="btn2 pri" onclick="saveRules()">保存 UA 名单</button>
            <button class="btn2" onclick="recalcHistory()">按当前名单重算历史命中</button>
            <span id="recalc_msg" style="font-size:12px;color:var(--sub)"></span>
        </div>
    </div>
</div>

<!-- ========== 系统设置 ========== -->
<div class="page" id="page-sys">
    <div class="card">
        <b>面板名称</b> <span class="tag" style="color:var(--sub);font-size:11px">显示在侧边栏和 TG 通知开头，多站点时用来区分谁是谁</span><br><br>
        <input type="text" id="sys_name" style="width:220px" placeholder="SubGuard">
        <button class="btn2 pri" onclick="saveSysName()">保存名称</button>
        <span id="sys_name_msg" style="font-size:12px;color:var(--sub)"></span>
    </div>
    <div class="card" style="margin-top:14px">
        <b>登录用户名</b> <span class="tag" style="color:var(--sub);font-size:11px">默认为 admin，改掉更安全</span><br><br>
        <input type="text" id="sys_user" style="width:220px" placeholder="admin">
        <button class="btn2 pri" onclick="saveSysUser()">保存用户名</button>
        <span id="sys_user_msg" style="font-size:12px;color:var(--sub)"></span>
    </div>
    <div class="card" style="margin-top:14px">
        <b>修改登录密码</b> <span class="tag" style="color:var(--sub);font-size:11px">直接写入 config.php，改完下次登录生效</span><br><br>
        <input type="password" id="sys_pwd1" placeholder="新密码（至少8位）" style="width:220px">
        <input type="password" id="sys_pwd2" placeholder="再输一遍" style="width:220px">
        <button class="btn2 pri" onclick="changePwd()">修改密码</button>
        <span id="sys_pwd_msg" style="font-size:12px"></span>
    </div>
</div>

</main>
</div>

<div class="modal" id="modal"><div class="box"><h3 id="modal_title"></h3><div id="modal_body" style="padding:6px 10px 12px"></div></div></div>
<div class="toast" id="toast"></div>

<script>
const WARN = <?= (int)$cfg['token_ip_warn'] ?>;
let ABTOK = [], TOKS = [], SUS = [];

function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function mask(t){return t.length>12 ? t.slice(0,6)+'…'+t.slice(-4) : t}
function toast(m){const el=document.getElementById('toast');el.textContent=m;el.style.display='block';setTimeout(()=>el.style.display='none',1500)}
function copy(s){const ta=document.createElement('textarea');ta.value=s;ta.style.cssText='position:fixed;opacity:0';document.body.appendChild(ta);ta.select();try{document.execCommand('copy');toast('已复制')}catch(e){toast('复制失败')}ta.remove()}

/* ---- 导航 ---- */
const titles = {dash:'总览',logsrc:'日志源',guard:'处置中心',bl:'黑名单',wl:'白名单',locks:'套餐锁',acts:'处置记录',rules:'规则设置'};
document.querySelectorAll('#nav a').forEach(a=>{
    a.onclick = ()=>{
        document.querySelectorAll('#nav a').forEach(x=>x.classList.remove('on'));
        a.classList.add('on');
        const p = a.dataset.p;
        document.querySelectorAll('.page').forEach(x=>x.classList.remove('on'));
        document.getElementById('page-'+p).classList.add('on');
        document.getElementById('page-title').textContent = titles[p];
    };
});

/* ---- 总览 ---- */
async function load(manual){
    try{
        const resp = await fetch('api.php?action=stats');
        const txt = await resp.text();
        let d; try{ d = JSON.parse(txt); } catch(e){ throw new Error('HTTP '+resp.status+' · '+txt.slice(0,300)); }
        document.getElementById('scan-dot').className='dot g';
        let info = '上次扫描 ' + fmtT(d.cards.last_scan);
        if (d.scan && !d.scan.locked && !d.scan.error) info += ' · 新入库 ' + d.scan.inserted + ' 条';
        if (d.db_range) info += ' · 库内 ' + d.cards.db_total + ' 条';
        document.getElementById('scaninfo').textContent = info;

        const bad = (d.log_check||[]).filter(x=>!x.readable);
        const warnEl = document.getElementById('warn');
        if (bad.length){
            warnEl.style.display='block';
            warnEl.innerHTML = '⚠️ PHP 读不到日志：<span class="mono">'+bad.map(x=>esc(x.path)).join('、')+'</span>。多半是宝塔 open_basedir 限制：网站 → 设置 → 网站目录 → 取消勾选「防跨站攻击」或追加 /www/wwwlogs/ 后重载 PHP。';
        } else warnEl.style.display='none';

        document.getElementById('c_total').textContent = d.cards.total;
        document.getElementById('c_ab').textContent = d.cards.abnormal;
        document.getElementById('c_abtok').textContent = d.cards.abnormal_tokens;
        document.getElementById('c_4xx').textContent = d.cards.err4xx;
        document.getElementById('c_acts').textContent = d.cards.acts_24h ?? 0;
        document.getElementById('c_locks').textContent = d.cards.locks_now ?? 0;
        const ab = document.getElementById('act_sum_bar');
        if (d.act_sum && d.act_sum.length) {
            ab.style.display = 'block';
            const nm = {ban:'封禁', move:'移蜜罐套餐', plan_lock:'套餐锁拉回', lock:'黑名单锁定'};
            ab.innerHTML = '24h 处置分布：' + d.act_sum.map(x => `${nm[x.action]||x.action} ${x.count} 次`).join('　·　') + (d.cards.acts_reverted ? `　·　已撤销 ${d.cards.acts_reverted} 次` : '');
        } else ab.style.display = 'none';

        document.getElementById('stream').innerHTML = d.abnormal_stream.length ? d.abnormal_stream.map(e=>`
            <tr><td class="mono">${e.time}</td><td class="mono">${e.ip}</td>
            <td class="mono">${e.token} <button class="btn" style="color:var(--sub)" onclick="copy('${e.token}')">复制</button>${e.locked ? ' <span class="pill blue">🔒已锁</span>' : ''}${e.exempt ? ' <span class="pill green">⏳豁免中</span>' : ''}</td>
            <td>${esc(e.flag)||'-'}</td><td><span class="pill orange">${esc(e.reason.replace('abnormal_ua:',''))}</span></td>
            <td class="ua">${esc(e.ua)}</td></tr>`).join('')
            : '<tr><td colspan="6" class="empty">24小时内没有异常 UA 命中 🎉</td></tr>';

        ABTOK = d.abnormal_token_rank||[]; renderAbtok(sortState.abtok||'hits');
        TOKS = d.token_rank||[]; renderTok(sortState.tok||'ips');
        SUS = d.suspect_ips||[]; renderSus(sortState.sus||'hits');
        document.getElementById('uarank').innerHTML = d.ua_rank.length
            ? d.ua_rank.map(u=>`<tr><td class="ua">${esc(u.ua)}</td><td>${u.count}</td></tr>`).join('')
            : '<tr><td colspan="2" class="empty">24小时内没有异常 UA</td></tr>';
    }catch(e){
        document.getElementById('scan-dot').className='dot r';
        document.getElementById('scaninfo').textContent = '连接失败';
        const warnEl = document.getElementById('warn');
        warnEl.style.display='block';
        warnEl.innerHTML = '⚠️ API 请求失败：<span class="mono">'+esc(e.message||String(e))+'</span>';
    }
    if(manual) toast('已刷新');
}

/* ---- 排序 ---- */
let sortState = {};
document.querySelectorAll('.sortbar button').forEach(b=>{
    b.onclick = ()=>{
        const t = b.dataset.t, k = b.dataset.k;
        sortState[t] = k;
        document.querySelectorAll(`.sortbar button[data-t="${t}"]`).forEach(x=>x.classList.toggle('on', x===b));
        if(t==='abtok') renderAbtok(k);
        if(t==='tok') renderTok(k);
        if(t==='sus') renderSus(k);
    };
});
document.querySelector('.sortbar button[data-t="abtok"][data-k="hits"]').classList.add('on');
document.querySelector('.sortbar button[data-t="tok"][data-k="ips"]').classList.add('on');
document.querySelector('.sortbar button[data-t="sus"][data-k="hits"]').classList.add('on');

function renderAbtok(k){
    const arr = [...ABTOK].sort((a,b)=> k==='hits' ? b.hits-a.hits : b.last.localeCompare(a.last));
    document.getElementById('abtokens').innerHTML = arr.map((t,i)=>`
        <tr><td>${i+1}</td>
        <td class="mono"><span class="btn" onclick="tokenDetail('${t.token}')">${t.token}</span> <button class="btn" style="color:var(--sub)" onclick="copy('${t.token}')">复制</button></td>
        <td>${t.hits}</td><td>${t.ips}</td><td>${t.uas}</td><td class="mono">${t.last}</td>
        <td class="ua">${esc(t.ua_list)}</td></tr>`).join('')
        || '<tr><td colspan="7" class="empty">24小时内没有 token 被异常 UA 拉取 🎉</td></tr>';
}
function renderTok(k){
    const arr = [...TOKS].sort((a,b)=> k==='ips' ? b.ips-a.ips : (k==='hits' ? b.hits-a.hits : b.last.localeCompare(a.last)));
    document.getElementById('tokens').innerHTML = arr.map((t,i)=>`
        <tr class="${t.warn?'warn':''}"><td>${i+1}</td>
        <td><span class="btn" onclick="tokenDetail('${t.token}')">${mask(t.token)}</span> <button class="btn" style="color:var(--sub)" onclick="copy('${t.token}')">复制</button></td>
        <td>${t.ips}${t.warn?' <span class="pill red">转售?</span>':''}</td>
        <td>${t.hits}</td><td class="mono">${t.first}</td><td class="mono">${t.last}</td>
        <td class="ua">${esc(t.ua)}</td></tr>`).join('')
        || '<tr><td colspan="7" class="empty">暂无数据</td></tr>';
}
function geoText(g){
    if(!g) return '<span style="color:var(--sub)">未查</span>';
    if(g.error) return '<span style="color:var(--orange)">查询失败</span>';
    return esc([g.country,g.region,g.city,g.org].filter(Boolean).join(' · '));
}
function renderSus(k){
    const arr = [...SUS].sort((a,b)=> k==='tokens' ? b.tokens-a.tokens : b.hits-a.hits);
    document.getElementById('ips').innerHTML = arr.length ? arr.map(e=>`
        <tr><td class="mono"><span class="btn" onclick="ipDetail('${e.ip}')">${e.ip}</span></td>
        <td>${geoText(e.geo)} <button class="btn" onclick="lookupGeo('${e.ip}',this)">查</button></td>
        <td>${e.tokens}${e.tokens>=3?' <span class="pill red">扫描/转售</span>':''}</td>
        <td>${e.abnormal}</td><td>${e.hits}</td><td class="mono">${e.last}</td>
        <td class="mono" style="white-space:nowrap"><button class="btn" onclick="copy('iptables -A INPUT -s ${e.ip} -j DROP')">复制封禁</button></td></tr>`).join('')
        : '<tr><td colspan="7" class="empty">24小时内没有可疑 IP</td></tr>';
}
window.lookupGeo = async function(ip,btn){
    btn.disabled = true; btn.textContent='查…';
    try{
        const r = await fetch('api.php?action=geo&ip='+encodeURIComponent(ip)).then(x=>x.json());
        btn.closest('td').innerHTML = geoText(r);
    }catch(e){ btn.textContent='重试'; btn.disabled=false; }
};

/* ---- 弹窗 ---- */
const modal=document.getElementById('modal');
window.tokenDetail = async function(token){
    document.getElementById('modal_title').innerHTML = `Token <span class="mono">${token}</span> 的访问 IP <span class="x" onclick="modal.classList.remove('show')">✕</span>`;
    document.getElementById('modal_body').innerHTML='<div class="empty">加载中…</div>';
    modal.classList.add('show');
    const d = await fetch('api.php?action=token_detail&token='+token).then(x=>x.json());
    document.getElementById('modal_body').innerHTML = `<table><thead><tr><th>IP</th><th>归属</th><th>次数</th><th>最近</th><th>UA</th></tr></thead><tbody>${
        d.ips.map(r=>`<tr><td class="mono">${r.ip}</td><td>${geoText(r.geo)}</td><td>${r.hits}</td><td class="mono">${r.last}</td><td class="ua">${esc(r.uas)}</td></tr>`).join('')
        || '<tr><td colspan="5" class="empty">无记录</td></tr>'}</tbody></table>`;
};
window.ipDetail = async function(ip){
    document.getElementById('modal_title').innerHTML = `IP <span class="mono">${ip}</span> 拉取的 token <span class="x" onclick="modal.classList.remove('show')">✕</span>`;
    document.getElementById('modal_body').innerHTML='<div class="empty">加载中…</div>';
    modal.classList.add('show');
    const d = await fetch('api.php?action=ip_detail&ip='+ip).then(x=>x.json());
    document.getElementById('modal_body').innerHTML = `<table><thead><tr><th>Token</th><th>次数</th><th>首次</th><th>最近</th><th>UA</th></tr></thead><tbody>${
        d.tokens.map(r=>`<tr><td class="mono">${r.token} <button class="btn" style="color:var(--sub)" onclick="copy('${r.token}')">复制</button></td>
            <td>${r.hits}</td><td class="mono">${r.first}</td><td class="mono">${r.last}</td><td class="ua">${esc(r.uas)}</td></tr>`).join('')
        || '<tr><td colspan="5" class="empty">无记录</td></tr>'}</tbody></table>`;
};
modal.addEventListener('click',e=>{ if(e.target===modal) modal.classList.remove('show'); });

/* ---- 处置中心 ---- */
function collectGuardCfg(){
    return {enabled:document.getElementById('g_enabled').checked?1:0,
        dry_run:document.getElementById('g_dry').checked?1:0,
        reset_uuid_on_move:document.getElementById('g_ruuid').checked?1:0,
        auto_lock:document.getElementById('g_autolock').checked?1:0,
        plan_quarantine:+document.getElementById('g_plan').value,
        rule_abnormal_hits:+document.getElementById('g_abn').value,
        rule_token_ips:+document.getElementById('g_ips').value,
        interval:+document.getElementById('g_int').value,
        custom_ua:document.getElementById('custom_ua').value,
        tg_enabled:document.getElementById('tg_en').checked?1:0,
        tg_token:document.getElementById('tg_token').value,
        tg_chatid:document.getElementById('tg_chatid').value,
        auto_defense:document.getElementById('g_defense').checked?1:0,
        auto_defense_hits:+document.getElementById('g_defense_hits').value,
        node_key:document.getElementById('g_nodekey').value.trim()};
}
function dbBody(){
    return {db_enabled:document.getElementById('db_en').checked?1:0,
        db_host:document.getElementById('db_host').value,
        db_port:+document.getElementById('db_port').value,
        db_name:document.getElementById('db_name').value,
        db_prefix:document.getElementById('db_prefix').value,
        db_user:document.getElementById('db_user').value,
        db_pass:document.getElementById('db_pass').value};
}
async function loadGuard(){
    try{
        const g = await fetch('api.php?action=guard_config_get').then(x=>x.json());
        document.getElementById('g_enabled').checked = !!g.enabled;
        document.getElementById('g_dry').checked = !!g.dry_run;
        document.getElementById('g_ruuid').checked = !!g.reset_uuid_on_move;
        document.getElementById('g_autolock').checked = !!g.auto_lock;
        document.getElementById('g_plan').value = g.plan_quarantine;
        document.getElementById('g_abn').value = g.rule_abnormal_hits;
        document.getElementById('g_ips').value = g.rule_token_ips;
        document.getElementById('g_int').value = g.interval;
        document.getElementById('g_defense').checked = !!g.auto_defense;
        document.getElementById('g_defense_hits').value = g.auto_defense_hits ?? 100;
        document.getElementById('g_nodekey').value = g.node_key||'';
        document.getElementById('custom_ua').value = g.custom_ua||'';
        document.getElementById('tg_en').checked = !!g.tg_enabled;
        document.getElementById('tg_token').value = g.tg_token||'';
        document.getElementById('tg_chatid').value = g.tg_chatid||'';
        document.getElementById('db_en').checked = !!g.db_enabled;
        document.getElementById('db_host').value = g.db_host;
        document.getElementById('db_port').value = g.db_port;
        document.getElementById('db_name').value = g.db_name;
        document.getElementById('db_prefix').value = g.db_prefix;
        document.getElementById('db_user').value = g.db_user;
        document.getElementById('db_pass').value = g.db_pass;
        const tun = document.getElementById('guard_tunnel');
        tun.textContent = g.tunnel_ok ? '✅ 面板库已连接' : '❌ 未连接';
        tun.className = 'pill ' + (g.tunnel_ok ? 'green' : 'red');
        const lt = document.getElementById('light-tunnel');
        lt.style.display='inline';
        lt.innerHTML = `<i class="dot ${g.tunnel_ok?'g':'r'}"></i><b>${g.tunnel_ok?'隧道正常':'隧道未连接'}</b>`;
        const le = document.getElementById('light-engine');
        le.style.display='inline';
        if (g.enabled){
            le.innerHTML = `<i class="dot ${g.dry_run?'y':'g'}"></i><b>引擎${g.dry_run?'干跑中':'运行中'}</b>`;
        } else {
            le.innerHTML = `<i class="dot r"></i><b>引擎未启用</b>`;
        }
    }catch(e){}
}
async function recalcHistory(){
    if(!confirm('将按当前UA名单重新判定全部历史记录（约几万条，需十几秒）。继续？')) return;
    document.getElementById('recalc_msg').textContent='重算中，请稍候…';
    const r = await fetch('api.php?action=recalc').then(x=>x.json());
    document.getElementById('recalc_msg').textContent = r.ok ? ('已重算 ' + r.scanned + ' 条') : '重算失败';
    load(true);
}
async function loadSys(){
    const r = await fetch('api.php?action=sys_get').then(x=>x.json());
    if (r.ok) document.getElementById('sys_name').value = r.panel_name;
}
async function saveSysName(){
    const r = await fetch('api.php?action=sys_save_name',{method:'POST',body:JSON.stringify({name:document.getElementById('sys_name').value})}).then(x=>x.json());
    document.getElementById('sys_name_msg').textContent = r.ok ? '已保存 ✅' : ('失败：'+r.msg);
    if (r.ok) document.getElementById('logo-name').firstChild.textContent = document.getElementById('sys_name').value;
}
async function saveSysUser(){
    const r = await fetch('api.php?action=sys_save_user',{method:'POST',body:JSON.stringify({user:document.getElementById('sys_user').value})}).then(x=>x.json());
    document.getElementById('sys_user_msg').textContent = r.ok ? '已保存，下次登录生效' : ('失败：'+r.msg);
}
async function changePwd(){
    const p1 = document.getElementById('sys_pwd1').value, p2 = document.getElementById('sys_pwd2').value;
    const m = document.getElementById('sys_pwd_msg');
    if (p1 !== p2) { m.textContent='两次输入不一致'; m.style.color='var(--red)'; return; }
    const r = await fetch('api.php?action=sys_change_pwd',{method:'POST',body:JSON.stringify({pwd:p1})}).then(x=>x.json());
    m.textContent = r.ok ? '已修改，下次登录用新密码' : ('失败：'+r.msg);
    m.style.color = r.ok ? 'var(--green)' : 'var(--red)';
    if (r.ok) { document.getElementById('sys_pwd1').value=''; document.getElementById('sys_pwd2').value=''; }
}
loadSys();
async function saveDbCfg(){
    const body = Object.assign(collectGuardCfg(), dbBody());
    await fetch('api.php?action=guard_config_save',{method:'POST',body:JSON.stringify(body)});
    document.getElementById('db_msg').textContent='已保存'; document.getElementById('db_msg').style.color='var(--green)';
    setTimeout(()=>document.getElementById('db_msg').textContent='',2000);
    loadGuard();
}
async function testDb(){
    await saveDbCfg();
    const r = await fetch('api.php?action=guard_test').then(x=>x.json());
    const m = document.getElementById('db_msg');
    m.textContent = r.ok ? '✅ 连接成功' : ('❌ '+r.msg);
    m.style.color = r.ok ? 'var(--green)' : 'var(--red)';
    loadGuard();
}
async function saveRules(){
    const body = Object.assign(collectGuardCfg(), dbBody());
    await fetch('api.php?action=guard_config_save',{method:'POST',body:JSON.stringify(body)});
    const m = document.getElementById('rules_msg');
    m.textContent = '已保存（UA 名单即时生效）';
    setTimeout(()=>m.textContent='',2500);
    loadGuard();
}
async function runGuard(){
    document.getElementById('guard_msg').textContent='保存中…';
    await fetch('api.php?action=guard_config_save',{method:'POST',body:JSON.stringify(Object.assign(collectGuardCfg(), dbBody()))});
    document.getElementById('guard_msg').textContent='执行中…';
    const r = await fetch('api.php?action=guard_run').then(x=>x.json());
    document.getElementById('guard_msg').textContent = r.msg || `完成：候选${r.candidates??'?'} 执行${r.done||0} 干跑${r.dry||0} 跳过${r.skip||0} 套餐锁${r.locks||0}${r.proactive ? ' 新注册防御' + r.proactive : ''}${r.defense && r.defense.ips && r.defense.ips.length ? ' 自动防御' + r.defense.ips.map(d => d.ip + '重置' + d.reset).join(',') : ''}`;
    if (r.skip_detail && r.skip_detail.length) {
        document.getElementById('guard_msg').textContent += ' ｜ 跳过原因：' + r.skip_detail.join('，');
    }
    actionsLoad();
}
async function tgTest(){
    await saveDbCfg();
    const r = await fetch('api.php?action=tg_test').then(x=>x.json());
    const m = document.getElementById('tg_msg');
    m.textContent = r.ok ? '✅ 已发送，去 TG 查收' : ('❌ '+r.msg);
    m.style.color = r.ok ? 'var(--green)' : 'var(--red)';
}
function genTunnel(){
    const ip = document.getElementById('t_ip').value.trim();
    const port = document.getElementById('t_port').value.trim() || '22';
    const lp = document.getElementById('t_lport').value.trim() || '3307';
    if(!ip){ document.getElementById('t_msg').textContent='先填面板服务器IP'; return; }
    document.getElementById('t_out').value =
`ssh-keygen -t ed25519 -N '' -f /root/.ssh/panel_tunnel -q
ssh-copy-id -i /root/.ssh/panel_tunnel.pub -p ${port} root@${ip}
cat > /etc/systemd/system/subguard-tunnel.service <<'SERVICE_EOF'
[Unit]
Description=SubGuard Panel DB Tunnel
After=network.target
[Service]
ExecStart=/usr/bin/ssh -p ${port} -i /root/.ssh/panel_tunnel -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -N -L 127.0.0.1:${lp}:127.0.0.1:3306 root@${ip}
Restart=always
RestartSec=5
[Install]
WantedBy=multi-user.target
SERVICE_EOF
systemctl enable --now subguard-tunnel && ss -tlnp | grep ${lp} && echo 隧道OK`;
    document.getElementById('t_msg').textContent='已生成，复制后到 SubGuard 服务器终端执行';
}
function copyTunnel(){
    const ta=document.getElementById('t_out'); ta.select();
    try{document.execCommand('copy');document.getElementById('t_msg').textContent='已复制'}catch(e){document.getElementById('t_msg').textContent='请手动全选复制'}
}

/* ---- 名单 ---- */
const BL_SHOW = 5;
function blRow(t, r, extra){
    return `<tr${extra?' data-extra="1" style="display:none"':''}><td class="mono">${esc(r.val)}</td><td>${esc(r.note)}</td>
    <td class="mono" style="color:var(--sub)">${r.ts ? fmtT(r.ts).slice(0,8) : '-'}</td>
    <td><button class="btn" style="color:var(--red)" onclick="blDel('${t}','${r.val}')">删除</button></td></tr>`;
}
let BL_CACHE = {};
async function blLoadAll(){
    for(const t of ['bl_tokens','bl_accounts','bl_ips','wl_tokens','wl_ips']){
        BL_CACHE[t] = await fetch('api.php?action=bl_list&type='+t).then(x=>x.json());
    }
    blRender();
}
function blRender(){
    const q = (document.getElementById('bl_q') ? document.getElementById('bl_q').value : '').trim().toLowerCase();
    for(const t of ['bl_tokens','bl_accounts','bl_ips','wl_tokens','wl_ips']){
        let list = BL_CACHE[t] || [];
        if (q) list = list.filter(r => (r.val + ' ' + r.note).toLowerCase().includes(q));
        let html = list.slice(0, BL_SHOW).map(r=>blRow(t,r,false)).join('');
        if (list.length > BL_SHOW) {
            html += `<tr><td colspan="4" style="text-align:center;border-bottom:0">
                <button class="btn" onclick="blToggle('${t}',this)">展开剩余 ${list.length-BL_SHOW} 条 ▾</button></td></tr>`;
            html += list.slice(BL_SHOW).map(r=>blRow(t,r,true)).join('');
        }
        document.getElementById('list-'+t).innerHTML = html || '<tr><td colspan="4" class="empty">' + (q ? '无匹配结果' : '名单为空') + '</td></tr>';
    }
}
function blToggle(t, btn){
    const rows = document.querySelectorAll('#list-'+t+' tr[data-extra]');
    const show = rows[0] && rows[0].style.display === 'none';
    rows.forEach(tr => tr.style.display = show ? '' : 'none');
    btn.textContent = show ? '收起 ▴' : `展开剩余 ${rows.length} 条 ▾`;
}
async function blAdd(type, inputId){
    const r = await fetch('api.php?action=bl_add&type='+type,{method:'POST',body:document.getElementById(inputId).value}).then(x=>x.json());
    document.getElementById(inputId).value='';
    if (!r.ok) { alert('导入失败：'+(r.msg||'服务器错误')); return; }
    blLoadAll();
    toast(`导入${r.added||0}条` + (r.skipped ? `，跳过${r.skipped}条无效` : ''));
}
async function blExport(type){
    const list = await fetch('api.php?action=bl_list&type='+type).then(x=>x.json());
    if(!list.length){ toast('名单为空'); return; }
    const text = list.map(r => r.val + (r.note ? ' ' + r.note : '')).join('\n') + '\n';
    const blob = new Blob([text], {type:'text/plain;charset=utf-8'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = type + '-' + new Date().toISOString().slice(0,10) + '.txt';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(a.href), 1000);
    toast('已导出 ' + list.length + ' 条');
}
async function blDel(type,val){
    if(!confirm('删除 '+val+' ?')) return;
    await fetch('api.php?action=bl_del&type='+type+'&val='+encodeURIComponent(val));
    blLoadAll();
}

/* ---- 套餐锁 / 记录 ---- */
async function locksLoad(){
    const q = document.getElementById('lock_q') ? document.getElementById('lock_q').value.trim() : '';
    const list = await fetch('api.php?action=locks_list' + (q ? '&q='+encodeURIComponent(q) : '')).then(x=>x.json());
    if (q && !list.length) { document.getElementById('lock_list').innerHTML = '<tr><td colspan="5" class="empty">没有匹配 "'+esc(q)+'" 的锁定</td></tr>'; return; }
    document.getElementById('lock_list').innerHTML = list.map(r=>
        `<tr><td class="mono">${esc(r.email)} (#${r.user_id})</td><td>套餐 ${r.plan_id}</td><td>${esc(r.note)}</td><td class="mono">${fmtT(r.ts)}</td>
        <td><button class="btn" style="color:var(--red)" onclick="lockDel(${r.user_id})">解锁</button></td></tr>`).join('')
        || '<tr><td colspan="5" class="empty">暂无锁定</td></tr>';
}
async function locksSearch(){ locksLoad(); }
async function lockAdd(){
    const body = JSON.stringify({input:document.getElementById('lock_input').value,
        plan_id:+document.getElementById('lock_plan').value, note:document.getElementById('lock_note').value});
    const r = await fetch('api.php?action=lock_add',{method:'POST',body}).then(x=>x.json());
    document.getElementById('lock_msg').textContent = r.ok ? '已加锁' : ('失败：'+r.msg);
    locksLoad();
}
async function lockDel(uid){
    if(!confirm('解锁 #'+uid+' ？\n将同时：恢复原套餐 + 加入白名单（不再自动锁定）。')) return;
    await fetch('api.php?action=lock_del&user_id='+uid); locksLoad();
}
const ACT_NAME = {ban:'封禁', move:'打入蜜罐', plan_lock:'蜜罐拉回', lock:'黑名单收押', defense:'自动防御'};
const ACT_CLS = {ban:'red', move:'orange', plan_lock:'blue', lock:'red', defense:'red'};
let actPage = 1, actTotalPages = 1;
async function actionsSearch(){ actPage = 1; actionsLoad(); }
async function actionsLoad(){
    const q = document.getElementById('act_q') ? document.getElementById('act_q').value.trim() : '';
    const d = await fetch('api.php?action=actions_list&page=' + actPage + (q ? '&q='+encodeURIComponent(q) : '')).then(x=>x.json());
    actTotalPages = d.pages || 1;
    document.getElementById('act_pageinfo').textContent = '共 ' + d.total + ' 条 · 第 ' + d.page + '/' + actTotalPages + ' 页';
    const list = d.list || [];
    document.getElementById('act_list').innerHTML = list.map(r=>
        `<tr><td><input type="checkbox" class="act-cb" value="${r.id}"></td>
        <td class="mono">${r.time}</td><td>${esc(r.email)}</td><td><span class="pill ${ACT_CLS[r.action]||'blue'}">${ACT_NAME[r.action]||r.action}</span></td>
        <td class="ua">${esc(r.detail)}</td><td>${r.status}</td>
        <td>${r.reverted
            ? `<button class="btn" onclick="revertBack(${r.id},'${r.email}')">重新收押</button>`
            : `<button class="btn" onclick="revert(${r.id})">撤销</button>`}</td></tr>`).join('')
        || '<tr><td colspan="7" class="empty">暂无记录</td></tr>';
    document.getElementById('act_all').checked = false;
}
function actPageGo(d){
    actPage = Math.min(Math.max(1, actPage + d), actTotalPages);
    actionsLoad();
}
function actToggleAll(on){
    document.querySelectorAll('.act-cb').forEach(cb => cb.checked = on);
}
async function revertBack(aid, email){
    if(!confirm('重新收押 ' + email + ' ？\n将立即锁回蜜罐套餐并写死，本条记录恢复为有效锁定。')) return;
    const r = await fetch('api.php?action=relock&email=' + encodeURIComponent(email) + '&aid=' + aid).then(x=>x.json());
    alert(r.ok ? ('已重新收押：' + r.email) : ('失败：' + (r.msg || '未知错误')));
    actionsLoad();
}
async function actBatchRevert(){
    const ids = [...document.querySelectorAll('.act-cb:checked')].map(cb => +cb.value);
    if (!ids.length) { toast('先勾选记录'); return; }
    if (!confirm('撤销选中的 ' + ids.length + ' 条处置？\n每条都会：恢复原套餐 + 拆蜜罐锁 + 24h豁免')) return;
    const r = await fetch('api.php?action=actions_revert',{method:'POST',body:JSON.stringify({ids})}).then(x=>x.json());
    alert(r.ok ? ('已撤销 ' + r.reverted + ' 条' + (r.skipped ? '，跳过 ' + r.skipped + ' 条（已撤销或无旧套餐数据）' : '')) : ('失败：' + (r.msg||'')));
    actionsLoad();
}
async function actBatchDel(){
    const ids = [...document.querySelectorAll('.act-cb:checked')].map(cb => +cb.value);
    if (!ids.length) { toast('先勾选记录'); return; }
    if (!confirm('删除选中的 ' + ids.length + ' 条处置记录？\n（只删日志，不影响任何用户套餐，不可恢复）')) return;
    const r = await fetch('api.php?action=actions_del',{method:'POST',body:JSON.stringify({ids})}).then(x=>x.json());
    alert(r.ok ? ('已删除 ' + r.deleted + ' 条') : ('失败：' + (r.msg||'未知')));
    actPage = 1;
    actionsLoad();
}
async function revert(id){
    if(!confirm('撤销将：恢复原套餐 + 加24小时豁免（期内不再被自动处置）\n\n点错没关系——事后可在白名单页删除其豁免条目，再用本页的"重新收押"按钮锁回。确定撤销？')) return;
    const r = await fetch('api.php?action=action_revert&id='+id).then(x=>x.json());
    alert(r.ok ? '已撤销'+(r.dry?'（干跑，仅记录）':'') : ('失败：'+r.msg));
    actionsLoad();
}

/* ---- 通用时间：一律带日期 ---- */
function fmtT(ts){
    const d = new Date(ts*1000), now = new Date();
    const p = n => String(n).padStart(2,'0');
    return p(d.getMonth()+1)+'-'+p(d.getDate())+' '+p(d.getHours())+':'+p(d.getMinutes())+':'+p(d.getSeconds());
}

async function lsLoad(){
    const list = await fetch('api.php?action=logsrc_list').then(x=>x.json());
    const arr = Object.values(list).sort((a,b)=>b.ts-a.ts);
    document.getElementById('ls_list').innerHTML = arr.map(r=>`
        <tr><td>${esc(r.name)}</td><td class="mono">${r.user}@${r.host}:${r.port}</td>
        <td class="mono">${esc(r.rpath)}</td><td>每${r.mins}分钟</td><td class="mono">${esc(r.local)}</td>
        <td><button class="btn" onclick="lsScript('${r.id}')">生成命令</button>
            <button class="btn" style="color:var(--red)" onclick="lsDel('${r.id}')">删除</button></td></tr>`).join('')
        || '<tr><td colspan="6" class="empty">暂无远程日志源</td></tr>';
}
async function lsAdd(){
    const body = JSON.stringify({name:document.getElementById('ls_name').value,
        host:document.getElementById('ls_host').value, port:+document.getElementById('ls_port').value,
        user:document.getElementById('ls_user').value, rpath:document.getElementById('ls_rpath').value,
        mins:+document.getElementById('ls_mins').value});
    const r = await fetch('api.php?action=logsrc_add',{method:'POST',body}).then(x=>x.json());
    document.getElementById('ls_msg').textContent = r.ok ? ('已添加，落盘到 '+r.local) : ('失败：'+r.msg);
    lsLoad();
}
async function lsDel(id){
    if(!confirm('删除该日志源？')) return;
    await fetch('api.php?action=logsrc_del&id='+id); lsLoad();
}
async function lsScript(id){
    const r = await fetch('api.php?action=logsrc_script&id='+id).then(x=>x.json());
    if(!r.ok){ alert(r.msg); return; }
    document.getElementById('ls_script_card').style.display='block';
    document.getElementById('ls_script').value = r.script;
}

lsLoad();
load(true);
loadGuard();
blLoadAll();
locksLoad();
actionsLoad();
setInterval(load, 15000);
</script>
</body>
</html>
