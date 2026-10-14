#!/usr/bin/env bash
# ============================================================
# SubGuard 节点测活哨兵 v1.2
# 检测：节点日志按 UUID 统计"查IP域名种类/其他流量"
#   判定：hc>=3（连查三家）或 hc>=2 且 other=0（只查IP没人味）→ 警报
# 通报（双通道，自动降级）：
#   ① POST → SubGuard node_hook.php（自动对号+收押+留痕）
#   ② ①失败 → TG 直推你（手动跑 lock_by_uuid.php 接力）
# ============================================================

# ↓↓↓ 回退通道：TG（hook 全挂时才用）
BOT_TOKEN="填TG_BOT_TOKEN"
CHAT_ID="填TG_CHAT_ID"
# ↓↓↓ 主通道：node_hook.php 顶部的 $NODE_KEY，保持一致
HOOK_KEY="填NODE_HOOK密钥"
HOOK_URLS="https://jk.098757.xyz/node_hook.php https://tk.098757.xyz/node_hook.php"

ALERT_LOG="/var/log/node_sentinel_alerts.log"
SEEN_FILE="/tmp/node_sentinel_seen"
COOLDOWN=3600

# 1. 找日志（SG_LOG 环境变量可覆盖；V2bX/XrayR 自动识别）
LOG="${SG_LOG:-}"
if [ -z "$LOG" ]; then
  for p in /etc/V2bX/access.log /etc/XrayR/access.log /etc/v2bx/access.log; do
    [ -f "$p" ] && LOG="$p" && break
  done
fi
[ -z "$LOG" ] && exit 0
[ -f "$LOG" ] || exit 0

# 2. 增量读取（按日志路径分 offset；轮转自动归零；首装跳过历史）
OFFSET_FILE="/tmp/node_sentinel.offset.$(printf '%s' "$LOG" | md5sum | awk '{print $1}' | cut -c1-8)"
START=0
[ -f "$OFFSET_FILE" ] && START=$(cat "$OFFSET_FILE" 2>/dev/null || echo 0)
SIZE=$(stat -c%s "$LOG" 2>/dev/null || echo 0)
[ "$START" -gt "$SIZE" ] && START=0
if [ "$START" -eq 0 ] && [ "$SIZE" -gt 1000000 ]; then
  echo "$SIZE" > "$OFFSET_FILE"
  exit 0
fi
CHUNK=$(tail -c +$((START+1)) "$LOG" 2>/dev/null)
echo "$SIZE" > "$OFFSET_FILE"
[ -z "$CHUNK" ] && exit 0

# 3. 按 UUID 汇总 [查IP域名种类, 其他流量, 主来源IP]
NOW=$(date +%s)
RESULTS=$(printf '%s\n' "$CHUNK" | perl -e '
my %hc = my %other = my %ip = ();
my $pat = qr/(?:^|\.)((?:api\.)?ipify\.org|ipwho\.is|(?:www\.|myip\.)?ipip\.net|ip-api\.com|ipinfo\.io|ifconfig\.(?:me|io)|icanhazip\.com|cip\.cc|ip\.sb|myip\.com|checkip\.dyndns\.org|test-ipv6\.com)/;
while (<STDIN>) {
  next unless /email: .*?([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})/; my $u = lc($1);
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

# 4. 判定 + 冷却 + 双通道通报
while IFS= read -r line; do
  [ -z "$line" ] && continue
  UUID=$(echo "$line"   | awk '{print $1}')
  HC_N=$(echo "$line"   | sed -n 's/.*hc=\([0-9]*\).*/\1/p')
  OTHER=$(echo "$line"  | sed -n 's/.*other=\([0-9]*\).*/\1/p')
  TOPIP=$(echo "$line"  | sed -n 's/.*topip=\([^ ]*\).*/\1/p')

  HIT=0
  [ "$HC_N" -ge 3 ] && HIT=1
  [ "$HC_N" -ge 2 ] && [ "$OTHER" -eq 0 ] && HIT=1
  [ "$HIT" -eq 0 ] && continue

  LAST=$(awk -v u="$UUID" '$1==u{print $2}' "$SEEN_FILE" 2>/dev/null)
  if [ -n "$LAST" ] && [ $((NOW - LAST)) -lt $COOLDOWN ]; then continue; fi
  awk -v u="$UUID" '$1!=u' "$SEEN_FILE" 2>/dev/null > "$SEEN_FILE.tmp"
  mv "$SEEN_FILE.tmp" "$SEEN_FILE" 2>/dev/null || true
  echo "$UUID $NOW" >> "$SEEN_FILE"

  NODE=$(hostname)

  # 通道①：POST 到监控台（两站都推，谁认识这个 UUID 谁收押）
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

  echo "$(date '+%F %T') node=$NODE uuid=$UUID hc=$HC_N other=$OTHER ip=$TOPIP hook=$HOOK_OK" >> "$ALERT_LOG"

  # 通道②：TG 回退（hook 全部失败/无人认领才发）
  if [ "$HOOK_OK" -eq 0 ]; then
    MSG="🚨 节点测活警报（监控台未接管，需手动处置）
节点：$NODE
UUID：$UUID
查IP域名：$HC_N 种，其他流量：$OTHER
主来源IP：$TOPIP
处置：HK机执行  php lock_by_uuid.php $UUID tk"
    if [ -n "$BOT_TOKEN" ] && [ "$BOT_TOKEN" != "填TG_BOT_TOKEN" ]; then
      curl -s -m 8 "https://api.telegram.org/bot${BOT_TOKEN}/sendMessage" \
        -d chat_id="$CHAT_ID" --data-urlencode text="$MSG" > /dev/null
    fi
  fi
done <<< "$RESULTS"

exit 0
