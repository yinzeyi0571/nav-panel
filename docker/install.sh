#!/usr/bin/env bash
# ============================================================
#  导航站 v2 — Docker Compose 一键部署脚本
#
#  新装：
#    ./install.sh --token <GitHub PAT>      # HTTPS 拉私有仓库
#    ./install.sh --ssh                     # 服务器已配好 GitHub SSH key
#    ./install.sh --local /path/to/src      # 源码已在本地（离线 / 已拷贝过来）
#
#  更新（换版本、改了代码）：再跑一次同样的命令即可，
#    脚本会自动 git pull（或复用 --local 目录）并重新构建。
#
#  其它参数：
#    -d, --dir DIR     安装目录，默认 ./123.lan
#    -p, --port PORT   对外端口，默认 8080
#    -b, --branch BR   分支，默认 main
#        --no-start    只拉代码 / 写配置，不构建启动
#        --dry-run     只打印要做什么，不真的执行
#    -h, --help
#
#  注意：仓库是私有的，脚本不会把 token 写进任何文件；
#        克隆完成后会立即把 remote URL 里的 token 抹掉。
# ============================================================

set -euo pipefail

REPO_SSH="git@github.com:yinzeyi0571/nav-panel.git"
REPO_HTTPS="https://github.com/yinzeyi0571/nav-panel.git"

INSTALL_DIR="./123.lan"
PORT=""
BRANCH="main"
TOKEN="${GITHUB_TOKEN:-}"
MODE=""            # ssh | https | local
LOCAL_SRC=""
NO_START=0
DRY_RUN=0

# ---------------- 输出辅助 ----------------
c_reset=$'\033[0m'; c_red=$'\033[31m'; c_green=$'\033[32m'
c_yellow=$'\033[33m'; c_cyan=$'\033[36m'; c_bold=$'\033[1m'

info() { printf '%s[info]%s %s\n' "$c_cyan" "$c_reset" "$*"; }
ok()   { printf '%s[ ok ]%s %s\n' "$c_green" "$c_reset" "$*"; }
warn() { printf '%s[warn]%s %s\n' "$c_yellow" "$c_reset" "$*"; }
die()  { printf '%s[fail]%s %s\n' "$c_red" "$c_reset" "$*" >&2; exit 1; }

run() {
  if [ "$DRY_RUN" -eq 1 ]; then
    printf '%s[dry]%s  %s\n' "$c_yellow" "$c_reset" "$*"
  else
    "$@"
  fi
}

usage() {
  # 打印文件顶部连续的注释块（遇到第一行非注释就停）
  awk 'NR==1 {next} /^#/ {sub(/^#[ ]?/, ""); print; next} {exit}' "$0"
  exit 0
}

# ---------------- 参数解析 ----------------
while [ $# -gt 0 ]; do
  case "$1" in
    -d|--dir)     INSTALL_DIR="${2:-}"; shift 2 ;;
    -p|--port)    PORT="${2:-}"; shift 2 ;;
    -b|--branch)  BRANCH="${2:-}"; shift 2 ;;
    -t|--token)   TOKEN="${2:-}"; MODE="https"; shift 2 ;;
    --ssh)        MODE="ssh"; shift ;;
    --local)      LOCAL_SRC="${2:-}"; MODE="local"; shift 2 ;;
    --no-start)   NO_START=1; shift ;;
    --dry-run)    DRY_RUN=1; shift ;;
    -h|--help)    usage ;;
    *)            die "未知参数：$1（用 --help 看用法）" ;;
  esac
done

if [ -z "$MODE" ]; then
  # 没指定来源时：目录里已有 git 仓库就当更新，否则默认 SSH
  if [ -d "$INSTALL_DIR/.git" ]; then MODE="ssh"; else MODE="ssh"; fi
fi

# ---------------- 1. 环境检查 ----------------
info "检查运行环境…"

command -v docker >/dev/null 2>&1 || die "没找到 docker。请先安装：https://docs.docker.com/engine/install/"

if docker compose version >/dev/null 2>&1; then
  COMPOSE="docker compose"
elif command -v docker-compose >/dev/null 2>&1; then
  COMPOSE="docker-compose"
  warn "检测到老版 docker-compose，建议升级到 Docker Compose v2 插件"
else
  die "没找到 docker compose。请安装 Compose 插件：https://docs.docker.com/compose/install/"
fi
ok "Compose：$COMPOSE"

if ! docker info >/dev/null 2>&1; then
  die "docker 守护进程不可用。常见原因：当前用户不在 docker 组（sudo usermod -aG docker \$USER 后重登），或 Docker 没启动"
fi
ok "Docker 守护进程正常"

# ---------------- 2. 准备源码 ----------------
if [ "$MODE" = "local" ]; then
  [ -n "$LOCAL_SRC" ] || die "--local 后面要跟源码目录"
  [ -d "$LOCAL_SRC" ] || die "源码目录不存在：$LOCAL_SRC"
  [ -f "$LOCAL_SRC/docker-compose.yml" ] || die "$LOCAL_SRC 里没有 docker-compose.yml，不像项目源码"
  info "使用本地源码：$LOCAL_SRC（跳过克隆）"
  # --local 时直接就地操作，不再复制
  INSTALL_DIR="$LOCAL_SRC"
else
  if [ -d "$INSTALL_DIR/.git" ]; then
    info "目录已存在，执行更新：$(cd "$INSTALL_DIR" && git rev-parse --abbrev-ref HEAD)"
    run git -C "$INSTALL_DIR" fetch --quiet origin "$BRANCH" || warn "fetch 失败，用本地现有代码继续"
    run git -C "$INSTALL_DIR" checkout --quiet "$BRANCH" || true
    run git -C "$INSTALL_DIR" pull --quiet --ff-only origin "$BRANCH" || warn "pull 失败（有本地改动？），用现有代码继续"
  else
    command -v git >/dev/null 2>&1 || die "没找到 git，请先安装"
    if [ "$MODE" = "ssh" ]; then
      info "SSH 克隆 $REPO_SSH → $INSTALL_DIR"
      run git clone --branch "$BRANCH" "$REPO_SSH" "$INSTALL_DIR" \
        || die "SSH 克隆失败。确认服务器公钥已加到 https://github.com/settings/keys ，或改用 --token <PAT>"
    else
      [ -n "$TOKEN" ] || die "拉取私有仓库需要 token：加 --token <PAT>（或设环境变量 GITHUB_TOKEN）"
      info "HTTPS 克隆（使用 token）→ $INSTALL_DIR"
      run git clone --branch "$BRANCH" \
          "https://x-access-token:${TOKEN}@github.com/yinzeyi0571/nav-panel.git" "$INSTALL_DIR" \
        || die "HTTPS 克隆失败，检查 token 是否有 Contents 读权限"
      # token 不该留在 .git/config 里
      run git -C "$INSTALL_DIR" remote set-url origin "$REPO_HTTPS"
      ok "已抹掉 remote URL 中的 token"
    fi
  fi
fi

cd "$INSTALL_DIR" || die "进不去目录：$INSTALL_DIR"

[ -f docker-compose.yml ] || die "$INSTALL_DIR 里没有 docker-compose.yml"

# ---------------- 3. 生成 .env ----------------
if [ -f .env ]; then
  info ".env 已存在，保留现有配置"
  CUR_PORT="$(grep -E '^NAV_PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '"' || true)"
  [ -n "${CUR_PORT:-}" ] && info "当前端口：$CUR_PORT"
else
  if [ -f .env.example ]; then
    run cp .env.example .env
    ok "已从 .env.example 生成 .env"
  else
    warn "没有 .env.example，手写一份最小 .env"
    run bash -c 'printf "NAV_PORT=8080\nTZ=Asia/Shanghai\n" > .env'
  fi
fi

if [ -n "$PORT" ]; then
  if grep -qE '^NAV_PORT=' .env 2>/dev/null; then
    run sed -i.bak "s/^NAV_PORT=.*/NAV_PORT=$PORT/" .env
  else
    run bash -c "printf 'NAV_PORT=%s\n' '$PORT' >> .env"
  fi
  ok "端口设为 $PORT"
fi

# 注意：dry-run 下 .env 可能并没真的生成，这里的读取必须容错
FINAL_PORT="$(grep -E '^NAV_PORT=' .env 2>/dev/null | cut -d= -f2 | tr -d '"' || true)"
FINAL_PORT="${FINAL_PORT:-8080}"

# 端口占用检查
if command -v ss >/dev/null 2>&1; then
  PORT_CHECK="$(ss -ltn 2>/dev/null | grep -E "[:.]${FINAL_PORT}\b" || true)"
elif command -v netstat >/dev/null 2>&1; then
  PORT_CHECK="$(netstat -ltn 2>/dev/null | grep -E "[:.]${FINAL_PORT}\b" || true)"
else
  PORT_CHECK=""
fi
if [ -n "${PORT_CHECK:-}" ]; then
  warn "端口 $FINAL_PORT 已被占用："
  echo "$PORT_CHECK"
  warn "若不是本项目的旧容器，请换端口重跑：--port 8090"
fi

# ---------------- 4. 构建并启动 ----------------
if [ "$NO_START" -eq 1 ]; then
  ok "已按 --no-start 跳过构建，代码就绪于 $INSTALL_DIR"
  exit 0
fi

info "开始构建镜像并启动（首次会编译前端，约需几分钟）…"
run $COMPOSE up -d --build

# ---------------- 5. 等待可访问 ----------------
info "等待服务就绪…"
UP=0
if [ "$DRY_RUN" -eq 1 ]; then
  info "dry-run：跳过探活"
else
  for i in $(seq 1 60); do
    if curl -fsS -o /dev/null --max-time 3 "http://127.0.0.1:${FINAL_PORT}/" 2>/dev/null; then
      UP=1; break
    fi
    sleep 2
  done
fi

IP="$(hostname -I 2>/dev/null | awk '{print $1}')"
[ -n "${IP:-}" ] || IP="<服务器IP>"

echo
printf '%s============================================%s\n' "$c_bold" "$c_reset"
if [ "$UP" -eq 1 ]; then
  ok "部署完成！打开下面任一地址："
else
  warn "构建已提交，但 60 秒内还没探活成功。看下日志：cd $INSTALL_DIR && $COMPOSE logs -f"
fi
echo "    http://127.0.0.1:${FINAL_PORT}"
echo "    http://${IP}:${FINAL_PORT}"
echo
echo "  默认账号：admin / admin123   ← 登录后第一件事就是改掉"
echo
echo "  常用命令（需先 cd $INSTALL_DIR）："
echo "    看日志   $COMPOSE logs -f"
echo "    停       $COMPOSE down"
echo "    升级     重新跑一次本脚本"
echo "    备份     docker run --rm -v nav_nav-data:/data -v \"\$PWD\":/backup alpine \\"
echo "               tar czf /backup/nav-data-\$(date +%Y%m%d).tar.gz -C /data ."
printf '%s============================================%s\n' "$c_bold" "$c_reset"
