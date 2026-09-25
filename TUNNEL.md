# SSH 隧道搭建（auto_guard 前置）

SubGuard 服务器（139.196.180.104）需要读面板库（47.242.172.166 的 MySQL）。
MySQL 不对外开，走 SSH 隧道：本地 3307 → 面板服务器 127.0.0.1:3306。

## ① 两机配 SSH 免密（SubGuard 服务器上执行）

```bash
ssh-keygen -t ed25519 -N '' -f /root/.ssh/panel_tunnel
ssh-copy-id -i /root/.ssh/panel_tunnel.pub root@47.242.172.166
```

## ② systemd 保活（断了自动重连）

```bash
cat > /etc/systemd/system/subguard-tunnel.service <<'EOF'
[Unit]
Description=SubGuard Panel DB Tunnel
After=network.target

[Service]
ExecStart=/usr/bin/ssh -i /root/.ssh/panel_tunnel -o ExitOnForwardFailure=yes -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -N -L 127.0.0.1:3307:127.0.0.1:3306 root@47.242.172.166
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl enable --now subguard-tunnel
```

## ③ 验证

```bash
ss -tlnp | grep 3307
```

## ④ 面板服务器建专用 MySQL 账号（phpMyAdmin → SQL）

```sql
CREATE USER IF NOT EXISTS 'subguard'@'%' IDENTIFIED BY '设一个强密码';
GRANT SELECT ON `你的面板库名`.* TO 'subguard'@'%';
GRANT UPDATE (banned, plan_id, group_id, token, uuid) ON `你的面板库名`.`v2_user` TO 'subguard'@'%';
FLUSH PRIVILEGES;
```

## ⑤ 配置 SubGuard

把 panel_db 段复制进服务器的 config.php，填密码和库名，enabled 改 true。
刷新监控台 → 处置中心 → 显示 "✅ 面板库已连接" 即就绪。

## 使用节奏

1. 保持干跑模式，点「立即执行一次」，看处置记录的 dry 条目是否符合预期
2. 观察几天无误伤 → 关掉干跑 → 正式自动处置
