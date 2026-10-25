#!/usr/bin/env bash
# ============================================================
# SubGuard 节点测活哨兵 v1.4（最终版）
# 定位：纯探头。检测→报警→留痕，【永不直接锁人】。
# 判定：hc>=3 且 other<=30（查IP为主） 或 hc>=2 且 other=0（纯探针）
#       other>30 视为"营业中流量"，不报警（由订阅层引擎规则量刑）
# 降噪：同一 UUID 6 小时内 TG 只响一次（留痕每次都记）
# 兼容：V2bX/XrayR 双日志格式（裸UUID / 标签|UUID / from tcp:IP:）
# 自卫：日志超300MB自清；增量读取；首装跳过历史
# ============================================================

# ↓↓↓ 回退通道：TG（hook 不可达时的告警）
BOT_TOKEN="填TG_BOT_TOKEN"
CHAT_ID="填TG_CHAT_ID"
# ↓↓↓ 主通道：监控台 node_hook（保持 false——哨兵只观察，处置走规则引擎）
HOOK_KEY="填NODE_HOOK密钥"
HOOK_URLS="https://jk.098757.xyz/node_hook.php https://tk.098757.xyz/node_hook.php"

ALERT_LOG="/var/log/node_sentinel_alerts.log"
SEEN_FILE="/tmp/node_sentinel_seen"
COOLDOWN=21600   # 6小时：同UUID TG 只响一次

# 1. 找日志（SG_LOG 环境变量可覆盖）
LOG="${SG_LOG:-}"
if [ -z "$LOG" ]; then
  for p in /etc/V2bX/access.log /etc/XrayR/access.log /etc/v2bx/access.log; do
    [ -f "$p" ] && LOG="$p" && break
  done
fi
[ -z "$LOG" ] && exit 0
[ -f "$LOG" ] || exit 0

# 2. 增量读取 + 自清
OFFSET_FILE="/tmp/node_sentinel.offset.$(printf '%s' "$LOG" | md5sum | awk '{print $1}' | cut -c1-8)"
START=0
[ -f "$OFFSET_FILE" ] && START=$(cat "$OFFSET_FILE" 2>/dev/null || echo 0)
SIZE=$(stat -c%s "$LOG" 2>/dev/null || echo 0)
[ "$START" -gt "$SIZE" ] && START=0
if [ "$START" -eq 0 ] && [ "$SIZE" -gt 1000000 ]; then
  echo "$SIZE" > "$OFFSET_FILE"
  exit 0
fi
# 日志自卫：超 300MB 清空（节点日志只喂哨兵，截断无损）
MAX_LOG=$((300*1024*1024))
if [ "$SIZE" -gt "$MAX_LOG" ]; then
  : > "$LOG"
  echo 0 > "$OFFSET_FILE"
  echo "$(date '+%F %T') 节点日志超300MB，已自清" >> "$ALERT_LOG"
  exit 0
fi
CHUNK=$(tail -c +$((START+1)) "$LOG" 2>/dev/null)
echo "$SIZE" > "$OFFSET_FILE"
[ -z "$CHUNK" ] && exit 0

# 3. 按 UUID 统计（兼容双格式；含 ipapi.co / ip4.ipip.net）
NOW=$(date +%s)
RESULTS=$(printf '%s\n' "$CHUNK" | perl -e '
my %hc = my %other = my %ip = ();
my $pat = qr/(?:^|\.)((?:api\.)?ipify\.org|ipwho\.is|(?:www\.|myip\.|ip4\.)?ipip\.net|ip-api\.com|ipinfo\.io|ifconfig\.(?:me|io)|icanhazip\.com|cip\.cc|ip\.sb|myip\.com|checkip\.dyndns\.org|test-ipv6\.com|ipapi\.co)/;
my $uuid_re = qr/([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/;
while (<STDIN>) {
  next unless /email: .*?$uuid_re/; my $u = lc($1);
  my $from = /from (?:tcp:|udp:)?([0-9.]+):/ ? $1 : "?";
  $ip{$u}{$from}++;
  if (/accepted (?:tcp|udp):([a-z0-9][a-z0-9.-]*?):/i) {
    my $d = lc($1);
    if ($d =~ $pat) { $hc{$u}{$1}++ } else { $other{$u}++ }
  }
}
for my $u (sort keys %hc) {
  my $nd = scalar keys %{$hc{$u}};
  my $no = $other{$u} // 0;
  my @ips = sort { $ip{$u}{$b} <=> $ip{$u}{$a} } keys %{$ip{$u}};
  printf "%s hc=%d other=%d topip=%s\n", $u, $nd, $no, ($ips[0] // "?");
}
')

# 4. 判定（other<=30 才报）+ 6h TG 降噪 + 双通道
while IFS= read -r line; do
  [ -z "$line" ] && continue
  UUID=$(echo "$line"   | awk '{print $1}')
  HC_N=$(echo "$line"   | sed -n 's/.*hc=\([0-9]*\).*/\1/p')
  OTHER=$(echo "$line"  | sed -n 's/.*other=\([0-9]*\).*/\1/p')
  TOPIP=$(echo "$line"  | sed -n 's/.*topip=\([^ ]*\).*/\1/p')

  HIT=0
  [ "$HC_N" -ge 3 ] && [ "$OTHER" -le 30 ] && HIT=1
  [ "$HC_N" -ge 2 ] && [ "$OTHER" -eq 0 ] && HIT=1
  [ "$HIT" -eq 0 ] && continue

  # 留痕（每次）
  echo "$(date '+%F %T') node=$(hostname) uuid=$UUID hc=$HC_N other=$OTHER ip=$TOPIP" >> "$ALERT_LOG"

  # TG 6 小时降噪
  LAST=$(awk -v u="$UUID" '$1==u{print $2}' "$SEEN_FILE" 2>/dev/null)
  if [ -n "$LAST" ] && [ $((NOW - LAST)) -lt $COOLDOWN ]; then
    echo "$UUID $NOW" >> "$SEEN_FILE"
    continue
  fi
  awk -v u="$UUID" '$1!=u' "$SEEN_FILE" 2>/dev/null > "$SEEN_FILE.tmp"
  mv "$SEEN_FILE.tmp" "$SEEN_FILE" 2>/dev/null || true
  echo "$UUID $NOW" >> "$SEEN_FILE"

  NODE=$(hostname)
  # 通道①：POST 监控台（只记录+看板，hook DRY_RUN=true 不动人）
  HOOK_OK=0
  if [ -n "$HOOK_KEY" ] && [ "$HOOK_KEY" != "填NODE_HOOK密钥" ]; then
    for hook in $HOOK_URLS; do
      resp=$(curl -s -m 10 -A "Mozilla/5.0" -X POST "$hook" \
        --data-urlencode "key=$HOOK_KEY" --data-urlencode "uuid=$UUID" \
        --data-urlencode "node=$NODE" --data-urlencode "hc=$HC_N" \
        --data-urlencode "other=$OTHER" --data-urlencode "ip=$TOPIP" \
        --data-urlencode "ts=$NOW" 2>/dev/null)
      case "$resp" in
        *'"found":true'*|*'"skipped"'*) HOOK_OK=1 ;;
      esac
    done
  fi
  # 通道②：TG 回退
  if [ "$HOOK_OK" -eq 0 ] && [ -n "$BOT_TOKEN" ] && [ "$BOT_TOKEN" != "填TG_BOT_TOKEN" ]; then
    MSG="👀 节点测活观察哨（不处置，请人工核验）
节点：$NODE
UUID：$UUID
查IP域名：$HC_N 种，其他流量：$OTHER
主来源IP：$TOPIP"
    curl -s -m 8 "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
      -d chat_id="$CHAT_ID" --data-urlencode text="$MSG" > /dev/null
  fi
done <<< "$RESULTS"

exit 0
