<?php
require __DIR__ . '/lib.php';
session_start();
$cfg = cfg();

// 登录 / 登出
if (isset($_GET['logout'])) { session_destroy(); header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pwd'])) {
    if (hash_equals((string)$cfg['password'], (string)$_POST['pwd'])) {
        $_SESSION['subguard_ok'] = 1;
        header('Location: index.php');
        exit;
    }
    $login_err = '密码错误';
}
if (empty($_SESSION['subguard_ok'])) {
    ?>
    <!DOCTYPE html><html lang="zh"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>SubGuard 登录</title>
    <style>
        body{margin:0;height:100vh;display:flex;align-items:center;justify-content:center;background:#0d1117;font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
        .box{background:#fff;border:1px solid #d0d7de;border-radius:12px;padding:36px 40px;width:300px;box-shadow:0 4px 24px rgba(0,0,0,.06)}
        h1{margin:0 0 6px;font-size:20px;color:#1f2328} p{margin:0 0 20px;font-size:13px;color:#57606a}
        input{width:100%;box-sizing:border-box;padding:10px 12px;border-radius:8px;border:1px solid #d0d7de;background:#f6f8fa;color:#1f2328;font-size:14px}
        button{margin-top:14px;width:100%;padding:10px;border:0;border-radius:8px;background:#0969da;color:#fff;font-size:14px;cursor:pointer}
        .err{color:#f85149;font-size:13px;margin-top:10px}
    </style></head><body>
    <form class="box" method="post">
        <h1>SubGuard</h1><p>订阅异常监控台</p>
        <input type="password" name="pwd" placeholder="输入访问密码" autofocus>
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
:root{--bg:#f6f8fa;--panel:#ffffff;--border:#d0d7de;--txt:#1f2328;--sub:#57606a;--blue:#0969da;--red:#d1242f;--orange:#9a6700;--green:#1a7f37}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font:14px/1.6 -apple-system,"PingFang SC","Microsoft YaHei",sans-serif}
header{display:flex;align-items:center;gap:14px;padding:14px 22px;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:9}
header h1{font-size:17px;margin:0}
.dot{width:8px;height:8px;border-radius:50%;background:var(--green);display:inline-block}
.dot.off{background:var(--red)}
header .sp{flex:1}
header a{color:var(--sub);text-decoration:none;font-size:13px}
main{max-width:1280px;margin:0 auto;padding:18px 22px 60px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin-bottom:18px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.card .n{font-size:26px;font-weight:600}
.card .l{font-size:12px;color:var(--sub)}
.card.bad .n{color:var(--red)}
section{background:var(--panel);border:1px solid var(--border);border-radius:10px;margin-bottom:18px;overflow:hidden}
section h2{margin:0;padding:12px 16px;font-size:14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px}
section h2 .tag{font-size:11px;font-weight:400;color:var(--sub)}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px 14px;text-align:left;border-bottom:1px solid #e5e8ec;vertical-align:top}
th{color:var(--sub);font-weight:500;font-size:12px;white-space:nowrap}
tr:last-child td{border-bottom:0}
tr.warn td{background:rgba(209,35,49,.06)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px}
td .ua{color:var(--sub);font-size:12px;word-break:break-all}
.pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px}
.pill.red{background:rgba(248,81,73,.18);color:var(--red)}
.pill.orange{background:rgba(210,153,34,.15);color:var(--orange)}
.btn{cursor:pointer;color:var(--blue);background:none;border:0;padding:0;font-size:12px}
.btn:hover{text-decoration:underline}
.mask{cursor:pointer}
.empty{padding:26px;text-align:center;color:var(--sub)}
/* 弹窗 */
.modal{position:fixed;inset:0;background:rgba(0,0,0,.6);display:none;align-items:flex-start;justify-content:center;padding:60px 16px;z-index:99}
.modal.show{display:flex}
.modal .box{background:var(--panel);border:1px solid var(--border);border-radius:12px;max-width:860px;width:100%;max-height:75vh;overflow:auto}
.modal h3{margin:0;padding:14px 18px;border-bottom:1px solid var(--border);font-size:14px;display:flex;gap:10px;align-items:center}
.modal .x{margin-left:auto;cursor:pointer;color:var(--sub)}
.modal table{margin:0 0 10px}
footer{color:var(--sub);font-size:12px;text-align:center}
.toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#238636;color:#fff;padding:8px 18px;border-radius:8px;display:none;font-size:13px;z-index:100}
</style>
</head>
<body>
<header>
    <span class="dot" id="dot"></span>
    <h1>SubGuard 订阅监控台</h1>
    <span style="color:var(--sub);font-size:12px" id="scaninfo">加载中…</span>
    <span class="sp"></span>
    <a href="javascript:load(true)" style="margin-right:12px">立即刷新</a>
    <a href="?logout=1">退出</a>
</header>
<main>
    <div id="warn"></div>
    <div class="cards">
        <div class="card"><div class="n" id="c_total">-</div><div class="l">24h 订阅请求</div></div>
        <div class="card bad" id="card_ab"><div class="n" id="c_ab">-</div><div class="l">异常 UA 请求</div></div>
        <div class="card"><div class="n" id="c_abtok">-</div><div class="l">涉异常 token 数</div></div>
        <div class="card"><div class="n" id="c_4xx">-</div><div class="l">4xx 探测（封禁后试探）</div></div>
    </div>

    <section>
        <h2>异常 UA 实时流 <span class="tag">命中爬虫/服务器/转换站 UA 的订阅拉取，最新在前</span></h2>
        <div id="stream_wrap"><table><thead><tr><th>时间</th><th>IP</th><th>Token</th><th>flag</th><th>命中规则</th><th>UA</th></tr></thead><tbody id="stream"></tbody></table></div>
    </section>

    <section>
        <h2>涉异常 Token 排行 <span class="tag">按异常 UA 命中次数排序，命中越多越可疑，点 token 看 IP 明细</span></h2>
        <table><thead><tr><th>#</th><th>Token</th><th>异常命中</th><th>涉及IP</th><th>涉及UA种类</th><th>最近</th><th>UA 明细</th></tr></thead><tbody id="abtokens"></tbody></table>
    </section>

    <section>
        <h2>Token 独立 IP 排行榜 <span class="tag">单 token 24h 内被多少个不同 IP 拉取，≥<?= (int)$cfg['token_ip_warn'] ?> 个标红 = 疑似转售</span></h2>
        <table><thead><tr><th>#</th><th>Token（点击看 IP 明细）</th><th>独立IP</th><th>请求数</th><th>首次</th><th>最近</th><th>主要 UA</th></tr></thead><tbody id="tokens"></tbody></table>
    </section>

    <section>
        <h2>可疑 IP 名单 <span class="tag">多拉多个 token / 异常 UA 高频 / 请求总量异常</span></h2>
        <table><thead><tr><th>IP</th><th>归属</th><th>拉取token数</th><th>异常UA命中</th><th>请求数</th><th>最近活动</th><th>操作</th></tr></thead><tbody id="ips"></tbody></table>
    </section>

    <section>
        <h2>异常 UA 类型统计</h2>
        <table><thead><tr><th>UA</th><th>次数</th></tr></thead><tbody id="uarank"></tbody></table>
    </section>
</main>
<footer>数据保留 <?= (int)$cfg['keep_days'] ?> 天 · 每 15 秒自动刷新</footer>

<div class="modal" id="modal"><div class="box"><h3 id="modal_title"></h3><div id="modal_body"></div></div></div>
<div class="toast" id="toast"></div>

<script>
const WARN = <?= (int)$cfg['token_ip_warn'] ?>;
let timer = null;

function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function mask(t){return t.length>12 ? t.slice(0,6)+'…'+t.slice(-4) : t}
function toast(msg){const el=document.getElementById('toast');el.textContent=msg;el.style.display='block';setTimeout(()=>el.style.display='none',1500)}
function copy(s){
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(s).then(()=>toast('已复制'));
    } else {
        const ta=document.createElement('textarea');
        ta.value=s; ta.style.cssText='position:fixed;top:0;left:0;opacity:0';
        document.body.appendChild(ta); ta.focus(); ta.select();
        try { document.execCommand('copy'); toast('已复制'); } catch(e) { toast('复制失败，请手动选择复制'); }
        document.body.removeChild(ta);
    }
}

function geoText(g){
    if(!g) return '<span style="color:var(--sub)">未查</span>';
    if(g.error) return '<span style="color:var(--orange)">查询失败</span>';
    return esc([g.country,g.region,g.city,g.org].filter(Boolean).join(' · '));
}
function geoBtn(ip){
    return `<button class="btn" onclick="lookupGeo('${ip}',this)">查归属</button>`;
}
window.lookupGeo = async function(ip,btn){
    btn.disabled = true; btn.textContent = '查询中…';
    try{
        const r = await fetch('api.php?action=geo&ip='+encodeURIComponent(ip)).then(x=>x.json());
        const td = btn.closest('td'); if(td) td.innerHTML = geoText(r);
    }catch(e){ btn.textContent='重试'; btn.disabled=false; }
};

async function load(manual){
    try{
        const resp = await fetch('api.php?action=stats');
        const txt = await resp.text();
        let d;
        try { d = JSON.parse(txt); }
        catch(pe){ throw new Error('HTTP ' + resp.status + ' · ' + txt.slice(0, 400)); }
        document.getElementById('dot').className='dot';
        let info = '上次扫描 ' + new Date((d.cards.last_scan||0)*1000).toLocaleTimeString('zh-CN');
        if (d.scan && !d.scan.locked && !d.scan.error) info += ' · 新入库 ' + d.scan.inserted + ' 条';
        if (d.db_range) info += ' · 库内 ' + d.cards.db_total + ' 条（' + d.db_range[0] + ' ~ ' + d.db_range[1] + '）';
        document.getElementById('scaninfo').textContent = info;
        // 日志不可读警告（宝塔 open_basedir 最常见）
        const bad = (d.log_check||[]).filter(x=>!x.readable);
        const warnEl = document.getElementById('warn');
        if (bad.length) {
            warnEl.style.display='block';
            warnEl.innerHTML = '⚠️ PHP 读不到日志文件：<span class="mono">' + bad.map(x=>esc(x.path)).join('</span>、<span class="mono">') + '</span>。多半是宝塔的 <b>open_basedir</b> 限制：宝塔 → 网站 → 设置 → 网站目录 → 取消勾选「防跨站攻击(open_basedir)」，或在 php.ini 的 open_basedir 中追加 /www/wwwlogs/，然后重载 PHP。';
        } else if (!d.cards.db_total) {
            warnEl.style.display='block';
            warnEl.innerHTML = '⚠️ 日志可读但库内 0 条数据。试着 SSH 跑 <span class="mono">php ' + location.pathname.replace(/[^\/]+$/,'') + 'scan.php</span> 看输出；命令行能入库而网页不能 = open_basedir 问题。';
        } else {
            warnEl.style.display='none';
        }
        document.getElementById('c_total').textContent = d.cards.total;
        document.getElementById('c_ab').textContent = d.cards.abnormal;
        document.getElementById('c_abtok').textContent = d.cards.abnormal_tokens;
        document.getElementById('c_4xx').textContent = d.cards.err4xx;

        // 异常流
        const st = document.getElementById('stream');
        st.innerHTML = d.abnormal_stream.length ? d.abnormal_stream.map(e=>`
            <tr><td class="mono">${e.time}</td><td class="mono">${e.ip}</td>
            <td class="mono">${e.token} <button class="btn" style="color:var(--sub)" onclick="copy('${e.token}')">复制</button></td>
            <td>${esc(e.flag)||'-'}</td><td><span class="pill orange">${esc(e.reason.replace('abnormal_ua:',''))}</span></td>
            <td class="ua">${esc(e.ua)}</td></tr>`).join('')
            : '<tr><td colspan="6" class="empty">24小时内没有异常 UA 命中 🎉</td></tr>';

        // token 排行
        const abt = document.getElementById('abtokens');
        abt.innerHTML = (d.abnormal_token_rank||[]).length ? d.abnormal_token_rank.map((t,i)=>`
            <tr><td>${i+1}</td>
            <td class="mono"><span class="btn" onclick="tokenDetail('${t.token}')">${t.token}</span> <button class="btn" style="color:var(--sub)" onclick="copy('${t.token}')">复制</button></td>
            <td>${t.hits}</td><td>${t.ips}</td><td>${t.uas}</td><td class="mono">${t.last}</td>
            <td class="ua">${esc(t.ua_list)}</td></tr>`).join('')
            : '<tr><td colspan="7" class="empty">24小时内没有 token 被异常 UA 拉取 🎉</td></tr>';

        const tk = document.getElementById('tokens');
        tk.innerHTML = d.token_rank.map((t,i)=>`
            <tr class="${t.warn?'warn':''}">
            <td>${i+1}</td>
            <td><span class="btn" onclick="tokenDetail('${t.token}')">${mask(t.token)}</span>
                <button class="btn" style="color:var(--sub)" onclick="copy('${t.token}')">复制</button></td>
            <td>${t.ips}${t.warn?` <span class="pill red">转售?</span>`:''}</td>
            <td>${t.hits}</td><td class="mono">${t.first}</td><td class="mono">${t.last}</td>
            <td class="ua">${esc(t.ua)}</td></tr>`).join('')
            || '<tr><td colspan="7" class="empty">暂无数据</td></tr>';

        // 可疑 IP
        const ipb = document.getElementById('ips');
        ipb.innerHTML = d.suspect_ips.length ? d.suspect_ips.map(e=>`
            <tr><td class="mono"><span class="btn" onclick="ipDetail('${e.ip}')">${e.ip}</span> <button class="btn" style="color:var(--sub)" onclick="ipDetail('${e.ip}')">明细</button></td>
            <td>${geoText(e.geo)}</td>
            <td>${e.tokens}${e.tokens>=3?' <span class="pill red">扫描/转售</span>':''}</td>
            <td>${e.abnormal}</td><td>${e.hits}</td><td class="mono">${e.last}</td>
            <td class="mono" style="white-space:nowrap">
                ${geoBtn(e.ip)}
                <button class="btn" onclick="copy('iptables -A INPUT -s ${e.ip} -j DROP')">复制封禁</button>
            </td></tr>`).join('')
            : '<tr><td colspan="7" class="empty">今天没有可疑 IP</td></tr>';

        // UA 排行
        document.getElementById('uarank').innerHTML = d.ua_rank.length
            ? d.ua_rank.map(u=>`<tr><td class="ua">${esc(u.ua)}</td><td>${u.count}</td></tr>`).join('')
            : '<tr><td colspan="2" class="empty">今天没有异常 UA</td></tr>';
    }catch(e){
        document.getElementById('dot').className='dot off';
        document.getElementById('scaninfo').textContent = '连接失败，重试中…';
    }
    if(manual) toast('已刷新');
}

const modal=document.getElementById('modal');
window.tokenDetail = async function(token){
    document.getElementById('modal_title').innerHTML = `Token <span class="mono">${token}</span> 的访问 IP <span class="x" onclick="document.getElementById('modal').classList.remove('show')">✕</span>`;
    document.getElementById('modal_body').innerHTML='<div class="empty">加载中…</div>';
    modal.classList.add('show');
    const d = await fetch('api.php?action=token_detail&token='+token).then(x=>x.json());
    document.getElementById('modal_body').innerHTML = `<table><thead><tr><th>IP</th><th>归属</th><th>次数</th><th>最近</th><th>UA</th></tr></thead><tbody>${
        d.ips.map(r=>`<tr><td class="mono">${r.ip}</td><td>${geoText(r.geo)||geoBtn(r.ip)}</td>
            <td>${r.hits}</td><td class="mono">${r.last}</td><td class="ua">${esc(r.uas)}</td></tr>`).join('')
        || '<tr><td colspan="5" class="empty">无记录</td></tr>'}</tbody></table>`;
};
window.ipDetail = async function(ip){
    document.getElementById('modal_title').innerHTML = `IP <span class="mono">${ip}</span> 拉取的 token`;
    document.getElementById('modal_body').innerHTML='<div class="empty">加载中…</div>';
    modal.classList.add('show');
    const d = await fetch('api.php?action=ip_detail&ip='+ip).then(x=>x.json());
    document.getElementById('modal_body').innerHTML = `<table><thead><tr><th>Token</th><th>次数</th><th>首次</th><th>最近</th><th>UA</th></tr></thead><tbody>${
        d.tokens.map(r=>`<tr><td class="mono">${r.token} <button class="btn" style="color:var(--sub)" onclick="copy('${r.token}')">复制</button></td>
            <td>${r.hits}</td><td class="mono">${r.first}</td><td class="mono">${r.last}</td><td class="ua">${esc(r.uas)}</td></tr>`).join('')
        || '<tr><td colspan="5" class="empty">无记录</td></tr>'}</tbody></table>`;
};
modal.addEventListener('click',e=>{ if(e.target===modal) modal.classList.remove('show'); });
document.getElementById('modal_title').addEventListener('click',e=>{
    if(e.target.classList.contains('x')) modal.classList.remove('show');
});

load(true);
timer = setInterval(load, 15000);
</script>
</body>
</html>
