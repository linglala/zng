#!/usr/bin/env bash
# ============================================================
# SubGuard 一键部署 v1.0 —— 仓库 → 服务器（香港机上运行，root）
# 原则：包内指纹验证不过 = 拒绝部署；config.php / data/ 永不触碰
# 用法: bash deploy_from_repo.sh
# ============================================================
set -euo pipefail

# ====== 可按需修改的配置 ======
REPO="${REPO:-/root/subguard-git}"        # 仓库克隆路径（香港机上）
JK="/www/wwwroot/jk.098757.xyz"
TK="/www/wwwroot/tk.098757.xyz"
NODE01="root@01il"                         # 01il 的 ssh 目标（需已配密钥免密）
HOOK_KEY="e57a1204255ccf30e413589dd7defe48"
# ==============================

say()  { echo -e "\033[32m  ✔ $*\033[0m"; }
die()  { echo -e "\033[31m  ✗ $*\033[0m" >&2; exit 1; }

# ① 前置检查
[ -d "$REPO/.git" ] || die "仓库克隆不存在: $REPO（先执行：git clone git@github.com:linglala/zng.git $REPO）"
command -v git >/dev/null 2>&1 || die "缺 git"
say "仓库就位: $REPO"

# ② 拉取并判断是否有新版
git -C "$REPO" fetch --all -q
OLD=$(git -C "$REPO" rev-parse HEAD)
git -C "$REPO" pull --ff-only -q || die "git pull 失败（本地有改动？先 commit/stash）"
NEW=$(git -C "$REPO" rev-parse HEAD)
if [ "$OLD" = "$NEW" ]; then
  say "已是最新（${NEW:0:8}），无需部署"
  exit 0
fi
say "发现新版本: ${OLD:0:8} → ${NEW:0:8}"

# ③ 包内指纹验证（坏包不上生产线）
g=$(grep -c "cand_action" "$REPO/guard.php" || true)
[ "$g" = "7" ] || die "guard.php 指纹异常（cand_action=$g，应=7），中止部署"
grep -q "HAVING c >=" "$REPO/guard.php" && die "guard.php 仍含 HAVING 旧坑，中止部署"
i=$(grep -c "nodealerts" "$REPO/index.php" || true)
[ "$i" = "3" ] || die "index.php 指纹异常（nodealerts=$i，应=3），中止部署"
grep -q "哨兵 v1.5" "$REPO/node_sentinel.sh" || die "node_sentinel.sh 版本异常（应 v1.5）"
grep -q "CC-SIGHTING" "$REPO/node_sentinel.sh" || die "node_sentinel.sh 缺 CC-SIGHTING（8899目击）"
grep -q "接收端 v1.4" "$REPO/node_hook.php" || die "node_hook.php 版本异常（应 v1.4）"
say "包内指纹五项全过"

# ④ 部署 PHP 组件到两站（显式清单，绝不整目录拷贝；不动 config.php / data/）
FILES="guard.php index.php api.php lib.php scan.php node_hook.php engine.php"
for site in "$JK" "$TK"; do
  for f in $FILES; do
    [ -f "$REPO/$f" ] && cp "$REPO/$f" "$site/$f" && chown www:www "$site/$f"
  done
done
cp "$REPO/lock_by_uuid.php" /root/lock_by_uuid.php 2>/dev/null || true
say "两站 PHP 组件 7 件 + lock_by_uuid 已部署"

# ⑤ 部署哨兵到 01il（免密 ssh；密钥脚本内回填，不落仓库）
scp -q "$REPO/node_sentinel.sh" "$NODE01:/usr/local/bin/node_sentinel.sh"
ssh "$NODE01" "chmod +x /usr/local/bin/node_sentinel.sh
sed -i 's|^HOOK_KEY=.*|HOOK_KEY=\"$HOOK_KEY\"|' /usr/local/bin/node_sentinel.sh
bash -n /usr/local/bin/node_sentinel.sh
grep -m1 '哨兵 v' /usr/local/bin/node_sentinel.sh
grep -c 'CC-SIGHTING' /usr/local/bin/node_sentinel.sh" \
  || die "01il 部署或远端验证失败"
say "01il 哨兵已更新（密钥已回填，远端验证通过）"

# ⑥ 部署后复核（服务器侧指纹）
sg=$(grep -c "cand_action" "$JK/guard.php" || true)
si=$(grep -c "nodealerts" "$JK/index.php" || true)
[ "$sg" = "7" ] && [ "$si" = "3" ] || die "部署后复核失败（g=$sg i=$si）"
say "部署完成 ✅  guard×7 / index×3 / sentinel-v1.5 全部在线"
say "请 Ctrl+F5 强刷监控台确认"
