<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { api } from '@/api'
import { err } from '@/utils/ui'

const status = ref(null)
const disks = ref([])
const storage = ref(null)
const loading = ref(true)
const unsupported = ref(false)
let timer = null

function fmtBytes(b) {
  const n = Number(b) || 0
  if (n <= 0) return '0'
  const u = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  let i = 0
  let v = n
  while (v >= 1024 && i < u.length - 1) {
    v /= 1024
    i++
  }
  return v.toFixed(v >= 100 || i === 0 ? 0 : 1) + ' ' + u[i]
}

function fmtUptime(s) {
  const n = Number(s) || 0
  if (n <= 0) return '—'
  const d = Math.floor(n / 86400)
  const h = Math.floor((n % 86400) / 3600)
  const m = Math.floor((n % 3600) / 60)
  if (d > 0) return `${d} 天 ${h} 小时 ${m} 分`
  if (h > 0) return `${h} 小时 ${m} 分`
  return `${m} 分钟`
}

function level(p) {
  const n = Number(p) || 0
  if (n >= 90) return 'danger'
  if (n >= 70) return 'warn'
  return 'ok'
}

const bars = computed(() => {
  const d = status.value
  if (!d) return []
  const out = []
  if (typeof d.cpu === 'number') out.push({ k: 'cpu', label: 'CPU', percent: d.cpu, sub: d.cpu_count ? d.cpu_count + ' 核' : '' })
  if (d.memory) out.push({ k: 'mem', label: '内存', percent: d.memory.percent, sub: `${fmtBytes(d.memory.used)} / ${fmtBytes(d.memory.total)}` })
  if (d.disk) out.push({ k: 'disk', label: '主分区', percent: d.disk.percent, sub: `${fmtBytes(d.disk.used)} / ${fmtBytes(d.disk.total)}` })
  return out
})

async function load() {
  // 存储自检与 /proc 无关，先独立取一次：即使监控不可用，安全检查也必须能看到。
  // 单独 try：老库、权限受限等异常不该影响整页渲染。
  try {
    storage.value = await api.system.storage()
  } catch (e) {
    storage.value = null
  }

  try {
    const d = await api.system.status()
    if (!d || d.supported === false) {
      unsupported.value = true
      return
    }
    status.value = d
    const dk = await api.system.disks()
    disks.value = dk.disks || []
  } catch (e) {
    err(e.message)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  timer = setInterval(load, 30000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div class="pane">
    <div class="pane-head">
      <div>
        <h2>系统状态</h2>
        <p class="text-dim">数据来自服务器 /proc，每 30 秒自动刷新一次。</p>
      </div>
      <button class="btn btn-sm" @click="load">立即刷新</button>
    </div>

    <div v-if="loading" class="loading-row">
      <span class="spinner" />
    </div>

    <template v-else>
      <!-- 存储与安全：不依赖 /proc，任何环境都要显示 -->
      <section v-if="storage" class="card">
        <div class="card-title">
          存储与安全
          <span class="chip" :class="storage.db_outside_webroot ? 'chip-ok' : 'chip-danger'">
            {{ storage.db_outside_webroot ? '数据库在站点根之外' : '数据库在站点根之内' }}
          </span>
        </div>

        <dl class="kv">
          <div>
            <dt>数据库</dt>
            <dd class="mono">
              {{ storage.db_file || '—' }}
              <span class="text-weak">
                （{{ storage.db_exists ? fmtBytes(storage.db_size) : '文件不存在' }}）
              </span>
            </dd>
          </div>
          <div>
            <dt>站点根</dt>
            <dd class="mono">{{ storage.web_root || '—' }}</dd>
          </div>
          <div>
            <dt>上传目录</dt>
            <dd class="mono">
              {{ storage.upload_dir || '—' }}
              <span :class="storage.upload_writable ? 'text-weak' : 'text-danger'">
                （{{ storage.upload_writable ? '可写' : '不可写' }}）
              </span>
            </dd>
          </div>
          <div>
            <dt>open_basedir</dt>
            <dd class="mono">{{ storage.open_basedir || '（未设置）' }}</dd>
          </div>
        </dl>

        <div v-if="storage.warnings && storage.warnings.length" class="warn-list">
          <div v-for="(w, i) in storage.warnings" :key="i" class="warn-item">
            <span class="warn-dot">!</span>
            <span>{{ w }}</span>
          </div>
        </div>
        <div v-else class="ok-note">
          一切正常：数据库不在站点根内，没有遗留的旧库文件。
        </div>
      </section>

      <div v-if="unsupported" class="empty">
        <div class="empty-title">系统监控不可用</div>
        <p>系统监控依赖 Linux 的 /proc 文件系统。</p>
        <p class="text-weak" style="font-size: 12.5px; margin-top: 6px">
          常见原因：PHP 的 open_basedir 未放行 /proc（宝塔「防跨站攻击」默认会拦）。
          在站点 <code>.user.ini</code> 的 open_basedir 里补上 <code>:/proc/</code>，然后重载 PHP-FPM 即可。
        </p>
      </div>

      <template v-else-if="status">
      <section class="card">
        <div class="card-title">主机信息</div>
        <dl class="kv">
          <div><dt>主机名</dt><dd>{{ status.hostname || '—' }}</dd></div>
          <div><dt>操作系统</dt><dd>{{ status.os || '—' }}</dd></div>
          <div><dt>PHP 版本</dt><dd>{{ status.php || '—' }}</dd></div>
          <div><dt>运行时长</dt><dd>{{ fmtUptime(status.uptime) }}</dd></div>
          <div>
            <dt>系统负载</dt>
            <dd>
              <template v-if="status.load">
                {{ status.load.one }} / {{ status.load.five }} / {{ status.load.fifteen }}
                <span class="text-weak">（1 / 5 / 15 分钟）</span>
              </template>
              <template v-else>—</template>
            </dd>
          </div>
        </dl>
      </section>

      <section class="card">
        <div class="card-title">资源占用</div>
        <div class="bars">
          <div v-for="b in bars" :key="b.k" class="bar">
            <div class="bar-head">
              <span>{{ b.label }}</span>
              <b>{{ b.percent }}%</b>
            </div>
            <div class="bar-track">
              <div
                class="bar-fill"
                :class="'is-' + level(b.percent)"
                :style="{ width: Math.min(100, Math.max(0, b.percent)) + '%' }"
              />
            </div>
            <div class="bar-sub truncate">{{ b.sub }}</div>
          </div>
        </div>

        <div v-if="status.memory" class="mem-extra">
          剩余可用 {{ fmtBytes(status.memory.free) }}
          <template v-if="status.memory.swap_total > 0">
            · Swap {{ fmtBytes(status.memory.swap_used) }} / {{ fmtBytes(status.memory.swap_total) }}
          </template>
        </div>
      </section>

      <section class="card">
        <div class="card-title">磁盘分区（{{ disks.length }}）</div>

        <div v-if="!disks.length" class="text-weak" style="font-size: 12.5px">
          没有检测到可显示的分区。
        </div>

        <div v-else class="disk-list">
          <div v-for="d in disks" :key="d.mount" class="disk">
            <div class="disk-head">
              <span class="mono truncate">{{ d.mount }}</span>
              <span class="text-weak truncate">{{ d.device }} · {{ d.fs }}</span>
              <b>{{ d.percent }}%</b>
            </div>
            <div class="bar-track">
              <div
                class="bar-fill"
                :class="'is-' + level(d.percent)"
                :style="{ width: Math.min(100, Math.max(0, d.percent)) + '%' }"
              />
            </div>
            <div class="bar-sub">
              已用 {{ fmtBytes(d.used) }} / 共 {{ fmtBytes(d.total) }}，剩余 {{ fmtBytes(d.free) }}
            </div>
          </div>
        </div>
      </section>
      </template>
    </template>
  </div>
</template>

<style scoped>
.pane-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.pane-head h2 {
  font-size: 17px;
  font-weight: 600;
  margin-bottom: 3px;
}
.pane-head p {
  font-size: 12.5px;
}

.card {
  margin-bottom: 14px;
}

.kv {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 10px 20px;
}
.kv > div {
  display: flex;
  gap: 4px;
  font-size: 12.5px;
  border-bottom: 1px dashed var(--c-border);
  padding-bottom: 7px;
}
.kv dt {
  color: var(--c-text-weak);
  flex: none;
  min-width: 72px;
}
.kv dd {
  margin: 0;
  min-width: 0;
  word-break: break-all;
}

.bars {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 16px;
}

.bar-head,
.disk-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 5px;
  font-size: 12px;
}
.bar-head b,
.disk-head b {
  font-size: 12.5px;
  font-variant-numeric: tabular-nums;
}

.bar-track {
  height: 6px;
  border-radius: var(--r-full);
  background: var(--c-border-strong);
  overflow: hidden;
}

.bar-fill {
  height: 100%;
  border-radius: var(--r-full);
  transition: width .4s ease, background .3s ease;
}
.bar-fill.is-ok { background: var(--c-accent); }
.bar-fill.is-warn { background: var(--c-warn); }
.bar-fill.is-danger { background: var(--c-danger); }

.bar-sub {
  margin-top: 5px;
  font-size: 11px;
  color: var(--c-text-weak);
}

.mem-extra {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--c-border);
  font-size: 11.5px;
  color: var(--c-text-dim);
}

.disk-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.disk-head {
  flex-wrap: wrap;
  justify-content: flex-start;
}
.disk-head b {
  margin-left: auto;
}

/* ---------------- 存储自检 ---------------- */
.card-title .chip {
  margin-left: 8px;
  vertical-align: middle;
}

.warn-list {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--c-border);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.warn-item {
  display: flex;
  gap: 8px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--c-text-dim);
}
.warn-dot {
  flex: none;
  width: 16px;
  height: 16px;
  margin-top: 1px;
  border-radius: var(--r-full);
  background: var(--c-danger);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.ok-note {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid var(--c-border);
  font-size: 11.5px;
  color: var(--c-ok);
}
</style>
