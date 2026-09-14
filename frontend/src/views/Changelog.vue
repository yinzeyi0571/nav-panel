<script setup>
import { ref, onMounted } from 'vue'
import { api } from '@/api'

/**
 * 版本更新页（项目要求：版本号 + 日期 + 新增/优化/修复）。
 * 数据来自 releases 表，管理员可在后台「版本管理」里追加记录。
 */
const loading = ref(true)
const data = ref(null)
const error = ref('')

const KINDS = [
  { key: '新增', cls: 'k-new' },
  { key: '优化', cls: 'k-opt' },
  { key: '修复', cls: 'k-fix' }
]

onMounted(async () => {
  try {
    data.value = await api.releases.list()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
})

function kindsOf(notes) {
  if (!notes) return []
  return KINDS.filter((k) => Array.isArray(notes[k.key]) && notes[k.key].length)
}
</script>

<template>
  <div class="nav-page">
    <header class="top">
      <div class="nav-wrap top-inner">
        <a class="brand" href="#/">
          <span class="back-arrow">←</span>
          <span class="brand-text">{{ (data && data.site_name) || '导航站' }}</span>
        </a>
        <div class="cur">
          当前版本
          <b>v{{ (data && data.current) || '—' }}</b>
        </div>
      </div>
    </header>

    <main class="nav-wrap nav-main">
      <div class="page-head">
        <h1>更新记录</h1>
        <p class="text-dim">记录每个版本的新增、优化与修复内容。</p>
      </div>

      <div v-if="loading" class="loading-row">
        <span class="spinner" />
        <span>正在加载…</span>
      </div>

      <div v-else-if="error" class="empty">
        <div class="empty-title">加载失败</div>
        <p>{{ error }}</p>
      </div>

      <div v-else-if="!data || !data.releases.length" class="empty">
        <div class="empty-title">还没有版本记录</div>
      </div>

      <div v-else class="timeline">
        <article
          v-for="(r, i) in data.releases"
          :key="r.version"
          class="rel"
          :class="{ 'is-current': r.version === data.current }"
        >
          <div class="rel-dot" />
          <div class="rel-card">
            <div class="rel-head">
              <span class="rel-ver">v{{ r.version }}</span>
              <span v-if="r.version === data.current" class="chip chip-accent">当前版本</span>
              <span class="rel-date">{{ r.released_at }}</span>
            </div>

            <div v-if="kindsOf(r.notes).length" class="rel-body">
              <div v-for="k in kindsOf(r.notes)" :key="k.key" class="rel-kind">
                <span class="kind-tag" :class="k.cls">{{ k.key }}</span>
                <ul class="kind-list">
                  <li v-for="(t, j) in r.notes[k.key]" :key="j">{{ t }}</li>
                </ul>
              </div>
            </div>
            <p v-else class="text-weak" style="font-size: 12px">（本条记录没有填写明细）</p>
          </div>
        </article>
      </div>
    </main>
  </div>
</template>

<style scoped>
.top-inner {
  display: flex;
  align-items: center;
  height: 54px;
  gap: 12px;
}
.brand {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
}
.back-arrow {
  font-size: 16px;
  color: var(--c-text-dim);
}
.brand:hover .back-arrow {
  color: var(--c-accent);
}
.cur {
  margin-left: auto;
  font-size: 12px;
  color: var(--c-text-dim);
}
.cur b {
  color: var(--c-accent);
}

.page-head {
  padding: 28px 0 22px;
}
.page-head h1 {
  font-size: 20px;
  font-weight: 600;
  margin-bottom: 4px;
}
.page-head p {
  font-size: 12.5px;
}

.timeline {
  position: relative;
  padding-left: 20px;
  padding-bottom: 40px;
}
.timeline::before {
  content: '';
  position: absolute;
  left: 4px;
  top: 8px;
  bottom: 0;
  width: 1px;
  background: var(--c-border);
}

.rel {
  position: relative;
  margin-bottom: 18px;
}

.rel-dot {
  position: absolute;
  left: -20px;
  top: 16px;
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--c-border-strong);
  box-shadow: 0 0 0 3px var(--c-bg);
}
.rel.is-current .rel-dot {
  background: var(--c-accent);
}

.rel-card {
  padding: 15px 17px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  background: var(--c-surface);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}
.rel.is-current .rel-card {
  border-color: var(--c-accent);
}

.rel-head {
  display: flex;
  align-items: center;
  gap: 9px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.rel-ver {
  font-size: 15px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.rel-date {
  margin-left: auto;
  font-size: 11.5px;
  color: var(--c-text-weak);
}

.rel-kind {
  display: flex;
  gap: 10px;
  margin-bottom: 10px;
}
.rel-kind:last-child {
  margin-bottom: 0;
}

.kind-tag {
  flex: none;
  font-size: 11px;
  padding: 1px 8px;
  border-radius: var(--r-full);
  height: 19px;
  display: inline-flex;
  align-items: center;
  font-weight: 500;
}
.k-new {
  background: color-mix(in srgb, var(--c-ok) 16%, transparent);
  color: var(--c-ok);
}
.k-opt {
  background: var(--c-accent-soft);
  color: var(--c-accent);
}
.k-fix {
  background: color-mix(in srgb, var(--c-warn) 18%, transparent);
  color: var(--c-warn);
}

.kind-list {
  flex: 1 1 auto;
  min-width: 0;
}
.kind-list li {
  position: relative;
  padding-left: 13px;
  font-size: 12.5px;
  line-height: 1.75;
  color: var(--c-text-dim);
}
.kind-list li::before {
  content: '';
  position: absolute;
  left: 3px;
  top: 9px;
  width: 4px;
  height: 4px;
  border-radius: 50%;
  background: var(--c-border-strong);
}
</style>
