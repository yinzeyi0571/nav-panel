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
│   └── nginx.conf          Docker 部署用的 nginx 站点配置
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

```bash
git clone <你的仓库地址> 123.lan
cd 123.lan
cp .env.example .env          # 需要改端口就编辑 NAV_PORT
docker compose up -d --build
```

打开 `http://<服务器IP>:8080`（端口由 `.env` 里的 `NAV_PORT` 决定）。

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

升级：`git pull && docker compose up -d --build`。

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
