#!/bin/sh
# ============================================================
#  导航站 v2 — 容器启动脚本
#
#  做的事：把数据卷的属主纠正成 php-fpm 的实际运行用户（www-data），
#  然后把控制权交还给官方入口。
#
#  为什么必须有这一步：
#  数据卷的属主只在「卷第一次创建」时从镜像里继承一次。而
#    ① 卷可能被 web 镜像的容器抢先初始化（那边 /var/www/html/uploads 是 root 建的）；
#    ② 用户改用宿主机目录挂载时，属主完全由宿主机决定，通常是 root；
#    ③ 镜像重建、卷复用等情况下属主也可能不对。
#  一旦属主不对，PHP 建不了库、传不了图标 —— 而且后端在库建不出来时
#  会「静默退回」站点根内的 storage/data.db，那是个能被匿名下载的位置。
#  所以这里每次启动都纠正一遍，不依赖卷是怎么来的。
# ============================================================

set -e

# www-data 在 alpine 里固定是 uid/gid 82
UID_WWW=82

for d in /data /data/sessions /var/www/html/uploads; do
    [ -d "$d" ] || mkdir -p "$d" 2>/dev/null || true
done

# -R 递归：卷里可能已经有旧文件
chown -R "${UID_WWW}:${UID_WWW}" /data 2>/dev/null || true
chown -R "${UID_WWW}:${UID_WWW}" /var/www/html/uploads 2>/dev/null || true

# 会话目录必须 700：里面是会话文件，别的进程不该读得到
chmod 700 /data/sessions 2>/dev/null || true

# 交还给官方入口脚本，保证 php-fpm 的常规初始化（如有）不被跳过
exec docker-php-entrypoint "$@"
