# ============================================================
#  导航站 v2 — 应用镜像（PHP-FPM + 后端）
#
#  体积小、构建快：php:8.2-fpm-alpine 已经自带本项目要用到的
#  全部扩展，一个都不用装 ——
#    pdo_sqlite / sqlite3  数据库
#    curl                 抓 favicon、代理 Iconify
#    mbstring             字符串长度校验
#    fileinfo / iconv / json / openssl / session / xml / posix
#    Zend OPcache          已启用
#
#  前端不在这里构建，见 Dockerfile.web。
# ============================================================

FROM php:8.2-fpm-alpine

# ---------------- PHP 生产参数 ----------------
RUN { \
        echo 'expose_php = Off'; \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /proc/self/fd/2'; \
        echo 'upload_max_filesize = 4M'; \
        echo 'post_max_size = 6M'; \
        echo 'max_execution_time = 60'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.validate_timestamps = 1'; \
        echo 'opcache.revalidate_freq = 2'; \
        echo 'session.use_strict_mode = 1'; \
    } > /usr/local/etc/php/conf.d/zz-nav.ini

RUN apk add --no-cache tzdata

WORKDIR /var/www/html

# 后端源码（前端产物在 web 镜像里）
COPY api/    ./api/
COPY config/ ./config/

# 数据卷挂载点：
#   /data                        数据库 + 会话，都在站点根之外 → HTTP 拿不到
#   /var/www/html/uploads        用户上传的图标 / favicon 缓存
# 先把属主设成 www-data（alpine 下 uid=82），命名卷首次创建时会继承这个属主，
# 否则 PHP 连库都建不出来 —— 而后端在库建不出来时会「静默退回站点根内的
# storage/data.db」，那是个可以被匿名下载的位置。
RUN mkdir -p /data/sessions /var/www/html/uploads \
 && chown -R www-data:www-data /data /var/www/html/uploads \
 && chmod 700 /data/sessions

# 启动脚本：每次启动都把数据卷属主纠正成 www-data。
# 光靠上面这行 chown 不够 —— 卷的属主只在首次创建时从镜像继承一次，
# 换成宿主机目录挂载、或卷被 web 镜像的容器抢先初始化时都会是 root。
COPY docker/entrypoint.sh /usr/local/bin/nav-entrypoint
RUN chmod +x /usr/local/bin/nav-entrypoint

VOLUME ["/data", "/var/www/html/uploads"]

EXPOSE 9000

ENTRYPOINT ["nav-entrypoint"]
CMD ["php-fpm"]
