#!/usr/bin/env bash
# ============================================================
# SubGuard 节点哨兵 · 一键安装 v1.3
# 用法（在节点机上，必须先下载再运行，不要 curl|bash 管道执行）:
#   curl -sSL -o /tmp/install.sh https://raw.githubusercontent.com/linglala/zng/master/install_node_sentinel.sh
#   bash /tmp/install.sh
# 前置: 面板端须先在「规则设置」设好节点哨兵密钥（jk/tk 两站同一把）
# ============================================================
set -e

# 防管道执行：交互 read 会从管道吞掉脚本自身
if [ ! -t 0 ]; then
  echo "✗ 检测到管道执行（curl | bash）。交互安装必须先下载再运行："
  echo "  curl -sSL -o /tmp/install.sh https://raw.githubusercontent.com/linglala/zng/master/install_node_sentinel.sh"
  echo "  bash /tmp/install.sh"
  exit 1
fi

echo "=========================================="
echo " SubGuard 节点哨兵 一键安装 v1.3"
echo "=========================================="

# ---- 依赖检查 ----
for cmd in perl curl crontab; do
  command -v $cmd >/dev/null 2>&1 || { echo "✗ 缺少 $cmd，请先安装（apt/yum install $cmd）"; exit 1; }
done

# ---- ① Hook 地址 ----
read -p "① Hook 地址（回车=双站默认）: " HOOK_IN
HOOK_URLS=${HOOK_IN:-"https://jk.098757.xyz/node_hook.php https://tk.098757.xyz/node_hook.php"}

# ---- ② 密钥（不回显）----
read -s -p "② NODE_KEY（面板规则设置里那把，输入不回显）: " HOOK_KEY; echo
if [ -z "$HOOK_KEY" ]; then echo "✗ 密钥不能为空"; exit 1; fi

# ---- ③④ TG 回退通道（可跳过）----
read -p "③ TG Bot Token（hook 全挂时的回退告警，回车跳过）: " BOT_TOKEN
read -p "④ TG Chat ID（回车跳过）: " CHAT_ID

# ---- V2bX：自动开启 access 日志（幂等）----
V2BX_DIR=""
for d in /etc/V2bX /etc/v2bx; do
  [ -f "$d/config.json" ] && V2BX_DIR="$d" && break
done
if [ -n "$V2BX_DIR" ]; then
  changed=0
  if grep -q '"Level": "error"' "$V2BX_DIR/config.json"; then
    sed -i 's/"Level": "error"/"Level": "info"/g' "$V2BX_DIR/config.json"
    changed=1
  fi
  if ! grep -q '"AccessPath"' "$V2BX_DIR/config.json"; then
    sed -i 's|"ErrorPath": "\([^"]*\)error.log"|"ErrorPath": "\1error.log",\n                "AccessPath": "\1access.log"|' "$V2BX_DIR/config.json"
    changed=1
  fi
  if [ "$changed" = "1" ]; then
    if command -v python3 >/dev/null 2>&1; then
      python3 -c "import json; json.load(open('$V2BX_DIR/config.json'))" 2>/dev/null || { echo "✗ config.json 修改后校验失败，请手工检查 $V2BX_DIR/config.json"; exit 1; }
    fi
    systemctl restart V2bX 2>/dev/null || systemctl restart v2bx 2>/dev/null || true
    sleep 5
    echo "✓ V2bX access 日志已开启并重启"
  fi
fi

# ---- 日志路径 ----
LOG=""
for p in /etc/V2bX/access.log /etc/XrayR/access.log /etc/v2bx/access.log; do
  [ -f "$p" ] && LOG="$p" && break
done
if [ -z "$LOG" ]; then
  echo "未在默认位置找到节点日志。常见查找命令："
  echo "  find /etc /www /usr/local /opt -maxdepth 4 -name 'access*.log' 2>/dev/null"
  read -p "请输入节点 access.log 完整路径: " LOG
  [ -f "$LOG" ] || { echo "✗ 日志不存在: $LOG"; exit 1; }
fi
echo "✓ 监控日志: $LOG"

# ---- 安装哨兵 ----
DST=/usr/local/bin/node_sentinel.sh
echo "下载哨兵..."
if ! curl -sfSL -m 30 -o "$DST" "https://raw.githubusercontent.com/linglala/zng/master/node_sentinel.sh"; then
  echo "✗ GitHub 下载失败"
  read -p "请输入本地 node_sentinel.sh 路径（手动上传后的）: " LOCAL
  [ -f "$LOCAL" ] || { echo "✗ 文件不存在"; exit 1; }
  cp "$LOCAL" "$DST"
fi
chmod +x "$DST"

# ---- 写入配置 ----
sed -i "s|^BOT_TOKEN=.*|BOT_TOKEN=\"$BOT_TOKEN\"|" "$DST"
sed -i "s|^CHAT_ID=.*|CHAT_ID=\"$CHAT_ID\"|" "$DST"
sed -i "s|^HOOK_KEY=.*|HOOK_KEY=\"$HOOK_KEY\"|" "$DST"
sed -i "s|^HOOK_URLS=.*|HOOK_URLS=\"$HOOK_URLS\"|" "$DST"
echo "✓ 配置已写入"

# ---- 日志切割（防硬盘满）----
if command -v logrotate >/dev/null 2>&1; then
  cat > /etc/logrotate.d/v2bx-node <<EOF
$(dirname "$LOG")/*.log {
    daily
    rotate 3
    maxsize 100M
    compress
    missingok
    notifempty
    copytruncate
}
EOF
  echo "✓ 日志切割已配置: /etc/logrotate.d/v2bx-node"
else
  echo "（无 logrotate，哨兵内置 300MB 自清兜底）"
fi

# ---- 挂 cron ----
( crontab -l 2>/dev/null | grep -v 'node_sentinel.sh'; echo "*/5 * * * * $DST" ) | crontab -
echo "✓ cron: */5 * * * * $DST"

# ---- 自检（首跑自动跳过历史日志）----
echo "=== 自检 ==="
"$DST" && echo "✓ 哨兵就位"
echo ""
echo "警报留痕: /var/log/node_sentinel_alerts.log"
echo "观察模式 TG 战报会带「观察模式」字样；面板处置记录同步留痕。"
echo "=========================================="
