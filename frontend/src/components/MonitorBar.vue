<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { api } from '@/api'

/**
 * 系统监控条（CPU / 内存 / 磁盘）。
 * 后端读的是 /proc，非 Linux 环境会返回 supported:false，这时整块隐藏。
 */
const props = defineProps({
  showTitle: { type: [Boolean, Number, String], default: true }
})

const data = ref(null)
const hidden = ref(false)
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
  if (d > 0) return `${d} 天 ${h} 小时`
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
  const d = data.value
  if (!d) return []
  const out = []
  if (typeof d.cpu === 'number') {
    out.push({ key: 'cpu', label: 'CPU', percent: d.cpu, sub: d.cpu_count ? `${d.cpu_count} 核` : '' })
  }
  if (d.memory) {
    out.push({
      key: 'mem',
      label: '内存',
      percent: d.memory.percent,
      sub: `${fmtBytes(d.memory.used)} / ${fmtBytes(d.memory.total)}`
    })
  }
  if (d.disk) {
    out.push({
      key: 'disk',
      label: '磁盘',
      percent: d.disk.percent,
      sub: `${fmtBytes(d.disk.used)} / ${fmtBytes(d.disk.total)}`
    })
  }
  return out
})

async function load() {
  try {
    const d = await api.system.status()
    if (!d || d.supported === false) {
      hidden.value = true
      return
    }
    data.value = d
  } catch (e) {
    hidden.value = true
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
  <div v-if="!hidden && data" class="monitor">
    <div v-if="showTitle" class="monitor-host truncate">
      {{ data.hostname }} · {{ data.os }} · 已运行 {{ fmtUptime(data.uptime) }}
    </div>

    <div class="monitor-bars">
      <div v-for="b in bars" :key="b.key" class="mbar">
        <div class="mbar-head">
          <span class="mbar-label">{{ b.label }}</span>
          <span class="mbar-pct">{{ b.percent }}%</span>
        </div>
        <div class="mbar-track">
          <div
            class="mbar-fill"
            :class="'is-' + level(b.percent)"
            :style="{ width: Math.min(100, Math.max(0, b.percent)) + '%' }"
          />
        </div>
        <div class="mbar-sub truncate">{{ b.sub }}</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.monitor {
  margin-top: 28px;
  padding: 14px 16px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  background: var(--c-surface);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

.monitor-host {
  font-size: 11px;
  color: var(--c-text-weak);
  margin-bottom: 12px;
}

.monitor-bars {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

.mbar-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 5px;
}

.mbar-label {
  font-size: 11px;
  color: var(--c-text-dim);
}

.mbar-pct {
  font-size: 12px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.mbar-track {
  height: 5px;
  border-radius: var(--r-full);
  background: var(--c-border-strong);
  overflow: hidden;
}

.mbar-fill {
  height: 100%;
  border-radius: var(--r-full);
  transition: width .4s ease, background .3s ease;
}
.mbar-fill.is-ok { background: var(--c-accent); }
.mbar-fill.is-warn { background: var(--c-warn); }
.mbar-fill.is-danger { background: var(--c-danger); }

.mbar-sub {
  margin-top: 5px;
  font-size: 10.5px;
  color: var(--c-text-weak);
}

@media (max-width: 640px) {
  .monitor-bars {
    grid-template-columns: 1fr;
    gap: 10px;
  }
}
</style>
