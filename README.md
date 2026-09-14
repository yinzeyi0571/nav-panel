# 导航站 v2

局域网自用的导航站，对标并超越 [Sun-Panel](https://github.com/naiba/sun-panel)。  
前端 Vue 3 单页应用，后端 PHP + SQLite，**多用户**：每个账号有自己的网址分组、站点和自己的外观主题；未登录时展示「公共导航页」。

> 当前版本 **v2.1.1**

---

## 特性

- **多用户隔离** —— 每个用户一套分组 / 站点 / 外观设置，互不可见
- **公共导航页** —— 未登录时展示指定用户（默认最早的管理员）的导航，管理员可在后台切换
- **长期登录** —— 登录页「记住我」（默认勾选）＝ 无固定期限，每次访问自动滑动续期；不勾则关浏览器即失效
- **分组即分类** —— 站点永远属于某个真实分组，没有「未分类」这种特殊桶；分组可改名、排序、删除
- **图标三选一** —— 文字图标 / 上传图片 / Iconify 在线图标库（后端代理，避免跨域与 UA 拦截）
- **favicon 自动抓取** —— 填个网址就自动取站点图标，结果缓存 7 天
- **外观自定义** —— 亮/暗主题、主题色、背景图、背景模糊与遮罩
- **拖拽排序** —— 分组内、跨分组拖动均可
- **备份导入导出** —— 一键导出全部数据为 JSON，可在别的实例还原
- **零配置升级** —— 数据库迁移幂等，每次请求自动跑，升级只需覆盖文件

---

## 技术栈

| 层 | 选型 |
| --- | --- |
| 前端 | Vue 3 + Vite + Pinia + vue-router（**Hash 路由**，服务端无需 rewrite）+ SortableJS |
| 后端 | 原生 PHP 7.4+（生产用 8.2），单入口 `/api/index.php?action=xxx` |
| 数据库 | SQLite（WAL 模式），位置在**站点根目录之外**，无法被 HTTP 下载 |
| 会话 | 独立会话目录 + 长期 Cookie 滑动续期 |
| Web 服务器 | nginx（宝塔或 Docker 均可） |

---

## 目录结构

```
123.lan/
├── api/                    后端（唯一入口 index.php + 类 + modules/ 业务模块）
│   ├── index.php           入口：解析 action 并分发
│   ├── bootstrap.php       引导：读配置 → 连库 → 迁移 → 开会话
│   ├── Db.php / Model.php / Auth.php / Settings.php / Icons.php / System.php
│   └── modules/            auth / home / sites / categories / settings / icons / users / backup / releases / system
├── config/
│   ├── config.php          站点配置（库路径、上传、会话、图标抓取）
│   └── version.php         版本号
├── frontend/               前端源码（Vue 3 + Vite）
│   └── src/{api,components,router,stores,styles,utils,views}
├── docker/
│   ├── nginx.conf          Docker 部署用的 nginx 站点配置
│   ├── entrypoint.sh       容器启动脚本（每次启动修正数据卷属主）
│   └── install.sh          ★ 一键部署脚本（拉代码 → 生成 .env → 构建启动 → 探活）
├── Dockerfile              应用镜像（php-fpm + 后端）
├── Dockerfile.web          Web 镜像（nginx + 前端构建产物）
├── docker-compose.yml      一键部署
├── .env.example            环境变量样例
├── release/                部署包与部署说明（可直接上传宝塔站点根）
├── uploads/                用户上传的图标（运行时产生，不入库）
├── 404.html
└── 48x48.ico
```

---

## 快速开始

### 方式 A：Docker Compose（推荐，零依赖）

服务器只需装 Docker 与 Docker Compose 插件。前端会在镜像里自动构建，**不需要预先装 Node 或 PHP**。

#### A-1 一键脚本（最省事）

`docker/install.sh` 会依次完成：检查 Docker / Compose → 拉代码 → 生成 `.env` → 构建启动 → 探活并打印访问地址。

> ⚠️ 仓库是**私有**的，`raw.githubusercontent.com` 匿名下载会被 404，所以没法用 `curl … | bash` 那套。
> 先把脚本拷到服务器就行：

```bash
# 在你自己的电脑上
scp docker/install.sh root@<服务器IP>:/root/

# 在服务器上
chmod +x /root/install.sh
/root/install.sh --token <GitHub PAT>       # HTTPS 拉私有仓库
```

| 场景 | 命令 |
| --- | --- |
| 服务器已配好 GitHub SSH key | `./install.sh --ssh` |
| 源码已经拷贝到服务器上（离线） | `./install.sh --local /path/to/src` |
| 指定安装目录和端口 | `./install.sh --dir /opt/123.lan --port 8090` |
| 只拉代码不启动 | `./install.sh --no-start` |
| 先看它会做什么 | `./install.sh --dry-run` |
| **升级到新版本** | 再跑一次同样的命令，自动 `git pull` + 重新构建 |

参数：`-d/--dir`、`-p/--port`、`-b/--branch`、`-t/--token`（也可设环境变量 `GITHUB_TOKEN`）、
`--ssh`、`--local`、`--no-start`、`--dry-run`、`-h/--help`。

脚本**不会把 token 写进任何文件**，克隆完成后会立刻把 remote URL 里的 token 抹掉。

#### A-2 手动执行

```bash
git clone <你的仓库地址> 123.lan
cd 123.lan
cp .env.example .env          # 需要改端口就编辑 NAV_PORT
docker compose up -d --build
```

打开 `http://<服务器IP>:8080`（端口由 `.env` 里的 `NAV_PORT` 决定）。

#### 完整 compose 配置

下面是 `docker-compose.yml` 的完整内容（两服务：`app` = php-fpm 只监听 compose 内网 9000，
`web` = nginx 承载前端产物并独占对外端口）：

```yaml
name: nav

services:

  # ---------------- 后端：PHP-FPM ----------------
  app:
    build:
      context: .
      dockerfile: Dockerfile
    image: nav-app:2.1.0
    container_name: nav-app
    restart: unless-stopped
    environment:
      # 关键：库放数据卷里（站点根之外）
      # 不设这个的话，后端会算成「站点根上一级」，在容器里等于根目录，会失败并退回站点根内的 storage/
      NAV_DB_FILE: /data/data.db
      TZ: ${TZ:-Asia/Shanghai}
    volumes:
      - nav-data:/data
      - nav-uploads:/var/www/html/uploads
    healthcheck:
      test: ["CMD-SHELL", "php -r 'exit(@fsockopen(\"127.0.0.1\", 9000) ? 0 : 1);'"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 15s
    logging:
      driver: json-file
      options:
        max-size: "5m"
        max-file: "3"
    networks: [nav]

  # ---------------- 前端 + 网关：nginx ----------------
  web:
    build:
      context: .
      dockerfile: Dockerfile.web
    image: nav-web:2.1.0
    container_name: nav-web
    restart: unless-stopped
    depends_on:
      - app
    ports:
      # 默认 8080，改 .env 里的 NAV_PORT 即可
      - "${NAV_PORT:-8080}:80"
    volumes:
      # 只读：图标由 app 容器写入，nginx 只负责发出去
      - nav-uploads:/var/www/html/uploads:ro
    logging:
      driver: json-file
      options:
        max-size: "5m"
        max-file: "3"
    networks: [nav]

volumes:
  nav-data:
  nav-uploads:

networks:
  nav:
    driver: bridge
```

配套环境变量（`.env.example`）：

```ini
NAV_PORT=8080        # 宿主机端口，容器内 nginx 固定 80
TZ=Asia/Shanghai
```

数据落在两个命名卷里，容器删除也不会丢：

| 卷 | 内容 |
| --- | --- |
| `nav-data` | `/data/data.db`（数据库）+ `/data/sessions/`（会话） |
| `nav-uploads` | 用户上传的图标 / favicon 缓存 |

备份就是把 `nav-data` 卷的文件拷出来：

```bash
docker run --rm -v nav_nav-data:/data -v "$PWD":/backup alpine \
  tar czf /backup/nav-data-$(date +%Y%m%d).tar.gz -C /data .
```

> **改用宿主机目录挂载**（想直接看到文件时）：把 compose 里的 `nav-data:/data` 换成 `./data:/data`，
> 然后先 `mkdir -p ./data/sessions && sudo chown -R 82:82 ./data`（82 是 alpine 镜像里 www-data 的 uid）。

**升级**（两种方式都行）：

```bash
cd 123.lan
git pull && docker compose up -d --build      # 手动
# 或者干脆再跑一次 install.sh，它会自动 pull + 重新构建
```

#### Docker 排错速查

| 现象 | 原因与处理 |
| --- | --- |
| `Bind for 0.0.0.0:8080 failed: port is already allocated` | 端口被占（宝塔默认也有站点）。改 `.env` 里的 `NAV_PORT`，或 `ss -ltnp \\| grep 8080` 看是谁 |
| 页面 502 | `app` 还没起来。`docker compose ps` 看健康检查，`docker compose logs app` 看报错 |
| 后台显示「当前环境不支持」 | 数据目录权限问题，看 `docker compose logs app` 里是否有 `Permission denied` |
| 容器启动报 `entrypoint.sh: not found` 或 `^M` | 脚本被转成了 CRLF。仓库根目录 `.gitattributes` 已强制 `* text=auto eol=lf`，确认没有用 `core.autocrlf=true` 检出 |
| 数据库又退回站点根内的 `storage/` | `NAV_DB_FILE` 没生效或 `/data` 不可写。确认卷挂载正确，且 `entrypoint.sh` 执行了 `chown 82:82` |
| 构建很慢 / 拉镜像超时 | 前端构建在容器里跑 `npm install`，首次约几分钟。镜像拉取慢可给 Docker 配镜像加速器 |

### 方式 B：宝塔 / LNMP（生产现用）

站点根：`/www/wwwroot/123.lan`，PHP 8.2 + nginx。

1. 把 `api/`、`config/` 上传到站点根，`release/*.zip` 里的 `dist/` 内容**摊平**到站点根
   （`dist/index.html → 站点根/index.html`，`dist/assets → 站点根/assets`）。
2. **预建站外数据目录并授权给 PHP 运行用户**：
   ```bash
   mkdir -p /www/wwwroot/123.lan-data
   chown www:www /www/wwwroot/123.lan-data
   ```
   ⚠️ 这一步不能省。PHP 以 `www` 运行，而 `/www/wwwroot` 属主是 `root`，`www` 建不了子目录；
   建不出来时后端会**静默退回**站点根内的 `storage/data.db` —— 那个位置可以被匿名下载。
3. 在站点根加 `.user.ini`：
   ```ini
   open_basedir=/www/wwwroot/123.lan/:/www/wwwroot/123.lan-data/:/proc/:/tmp/
   display_errors=Off
   ```
   `:/proc/` 必须留着，否则后台「系统状态」会显示「当前环境不支持」。
   宝塔会给这个文件加 immutable 属性，改之前先 `chattr -i`，改完 `chattr +i`。
   改完执行 `/etc/init.d/php-fpm-82 reload`。
4. 加 nginx 兜底规则（见 `release/nginx-123.lan-deny.conf`），挡住 `.db/.json/.zip/.rar` 与 `storage/`。
5. 确保 `uploads/` 归 `www:www` 且可写。

详细的踩坑清单见 `release/部署说明-v2.1.1.md`（最新）与 `release/部署说明-v2.1.0.md`。

---

## 首次登录

| 账号 | 密码 |
| --- | --- |
| `admin` | `admin123` |

> ⚠️ **部署后第一件事就是改掉这个密码。** 代码是公开可查的，默认口令等于没锁门。

后台入口 `#/login` → 登录后右上角进后台。后台可以：
管理站点 / 分组 / 图标、切换主题与外观、指定公共导航页所属用户、管理用户与权限、查看系统状态与存储安全检查、导入导出备份、查看更新记录。

---

## 数据与备份

- **数据库**：`data.db`（WAL 模式）。位置默认在站点根的**上一级**同名 `-data` 目录，例如
  `/www/wwwroot/123.lan-data/data.db`。可用环境变量 `NAV_DB_FILE` 覆盖。
- **会话**：`<数据库同目录>/sessions/`。这样做的原因是宝塔默认把会话放在 `/tmp`，
  而 `/tmp` 是全服务器所有 PHP 程序共用的，别的程序触发垃圾回收会把本站会话删掉。
- **迁移**：启动时自动进行，幂等，不删数据。
- **备份**：后台「备份」页可导出 JSON；文件级备份直接打包 `data.db` + `sessions/` 即可。

---

## 安全说明

- 密码用 `password_hash()`（bcrypt）存储，**不存明文**，也不使用弱哈希。
- 数据库与会话都放在站点根之外，HTTP 无法下载。
- `api/`、`config/` 下的每个 PHP 文件顶部都有入口守卫，直接访问返回 403。
- 响应统一为 `{code, msg, data}`：`0` 成功 / `401` 未登录 / `403` 无权限 / `404` 未知操作 / `500` 异常。

**请勿提交到仓库的东西**（`.gitignore` 已覆盖）：`data.db`、`data.json`、`.user.ini`、`uploads/`、`node_modules/`。

---

## 许可

私人项目，未附开源许可。
