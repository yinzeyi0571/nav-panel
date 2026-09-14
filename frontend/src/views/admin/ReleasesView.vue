<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { api } from '@/api'
import { usePanelStore } from '@/stores/panel'
import { err, ok, confirmDialog } from '@/utils/ui'

const panel = usePanelStore()

const list = ref([])
const loading = ref(true)
const saving = ref(false)
const showForm = ref(false)

const KINDS = [
  { key: '新增', field: 'added', hint: '一行一条' },
  { key: '优化', field: 'optimized', hint: '一行一条' },
  { key: '修复', field: 'fixed', hint: '一行一条' }
]

const form = reactive({
  version: '',
  released_at: '',
  added: '',
  optimized: '',
  fixed: ''
})

/** v2.0.0 的初始内容：把已经做完的事情如实记下来 */
const V2_NOTES = {
  新增: [
    '前台直接添加 / 编辑 / 删除站点，不用再进后台',
    '前台拖动排序：分组内拖动、跨分组拖动，松手即保存',
    '分组整体拖动排序',
    '图标三态：纯文字 / 图片 / 在线图标（Iconify 图标库）',
    '图标自动获取：填上网址就能抓到站点 favicon 并缓存到本地',
    '批量添加站点：支持「名称|网址」一行一条，或名称与网址分两栏',
    '批量删除站点与分组',
    '打开方式三选：当前页 / 新窗口 / 当前页弹窗',
    '背景模糊度与遮罩浓度可调',
    '布局参数可调：内容最大宽度、左右边距、上下边距',
    '分组支持图标和描述',
    '版本更新页（就是当前这个页面），前台底部显示版本号并可点击进入',
    '深浅色三态：深色 / 浅色 / 跟随系统',
    '右键菜单：打开、新标签页打开、复制链接、编辑、删除',
    '系统监控：CPU / 内存 / 磁盘'
  ],
  优化: [
    '整体重构为前端 SPA + 后端模块化接口，前后端彻底分离',
    '数据库从站点根目录移到站点的上一级目录，避免被 HTTP 直接下载',
    '所有内部 PHP 文件加了防直接访问守卫',
    '分组不再有「未分类」这个特殊概念：系统自带一个普通分组，改名、删除都随意；删除分组时组内站点会自动搬到剩下的第一个分组，站点永远不会无处可去',
    '顶部那排编辑工具（添加站点 / 批量添加 / 新建分组 / 批量选择 / 图标样式）收进一个小图标里，页面更清爽',
    '站点排序在删除后会自动压实，不会留下空档',
    '导入备份时同名分组会自动复用，不再重复创建',
    '粘贴网址时自动补全 http:// 并用域名当名称',
    '搜索框支持站内实时匹配 + 回车调用搜索引擎'
  ],
  修复: [
    '修复批量添加时会把中文说明文字当成网址建成站点的问题',
    '修复删除图标文件后文件 ID 漂移导致再次删除失败的问题',
    '修复同一个分组在前台被重复渲染成两块的问题',
    '修复合并导入时按名称复用分组失效的问题'
  ]
}

function toLines(s) {
  return String(s || '')
    .split('\n')
    .map((x) => x.trim())
    .filter(Boolean)
}

const previewCount = computed(
  () => toLines(form.added).length + toLines(form.optimized).length + toLines(form.fixed).length
)

async function load() {
  loading.value = true
  try {
    const d = await api.releases.list()
    list.value = d.releases || []
  } catch (e) {
    err(e.message)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openForm(presetVersion) {
  form.version = presetVersion || panel.version || ''
  form.released_at = new Date().toISOString().slice(0, 10)
  form.added = ''
  form.optimized = ''
  form.fixed = ''
  showForm.value = true
}

function fillV2() {
  form.added = V2_NOTES['新增'].join('\n')
  form.optimized = V2_NOTES['优化'].join('\n')
  form.fixed = V2_NOTES['修复'].join('\n')
  ok('已填入 v2.0.0 的更新内容，可直接保存或修改')
}

async function submit() {
  if (!form.version.trim()) {
    err('请填写版本号')
    return
  }
  if (!previewCount.value) {
    err('至少要写一条更新内容')
    return
  }

  saving.value = true
  try {
    const notes = {}
    const a = toLines(form.added)
    const o = toLines(form.optimized)
    const f = toLines(form.fixed)
    if (a.length) notes['新增'] = a
    if (o.length) notes['优化'] = o
    if (f.length) notes['修复'] = f

    const d = await api.releases.add({
      version: form.version.trim(),
      released_at: form.released_at,
      notes
    })
    list.value = d.releases || []
    showForm.value = false
    ok('版本记录已保存')
  } catch (e) {
    err(e.message)
  } finally {
    saving.value = false
  }
}

function kindsOf(notes) {
  if (!notes) return []
  return KINDS.filter((k) => Array.isArray(notes[k.key]) && notes[k.key].length)
}

const hasCurrent = computed(() => list.value.some((r) => r.version === panel.version))
</script>

<template>
  <div class="pane">
    <div class="pane-head">
      <div>
        <h2>版本管理</h2>
        <p class="text-dim">
          前台底部显示当前版本 <b>v{{ panel.version }}</b>，点击会跳到更新页。
        </p>
      </div>
      <div class="row">
        <button v-if="!hasCurrent" class="btn btn-sm" @click="openForm(panel.version)">
          补录 v{{ panel.version }}
        </button>
        <button class="btn btn-sm btn-primary" @click="openForm()">新增版本记录</button>
      </div>
    </div>

    <!-- 新增表单 -->
    <section v-if="showForm" class="card">
      <div class="card-title">
        <span>新增版本记录</span>
        <button class="btn btn-sm btn-ghost" @click="showForm = false">收起</button>
      </div>

      <div class="grid-2">
        <div class="field">
          <label class="field-label">版本号</label>
          <input v-model="form.version" class="input" type="text" placeholder="如 2.0.1" />
        </div>
        <div class="field">
          <label class="field-label">发布日期</label>
          <input v-model="form.released_at" class="input" type="date" />
        </div>
      </div>

      <div class="field">
        <label class="field-label">新增（每行一条）</label>
        <textarea v-model="form.added" class="textarea" rows="5" placeholder="前台直接添加站点" />
      </div>

      <div class="field">
        <label class="field-label">优化（每行一条）</label>
        <textarea v-model="form.optimized" class="textarea" rows="4" />
      </div>

      <div class="field">
        <label class="field-label">修复（每行一条）</label>
        <textarea v-model="form.fixed" class="textarea" rows="4" />
      </div>

      <div class="actions">
        <button class="btn btn-sm" @click="fillV2">填入 v2.0.0 内容</button>
        <span class="grow" />
        <span class="text-weak" style="font-size: 12px; align-self: center">
          共 {{ previewCount }} 条
        </span>
        <button class="btn btn-primary btn-sm" :disabled="saving" @click="submit">
          {{ saving ? '保存中…' : '保存' }}
        </button>
      </div>
    </section>

    <!-- 列表 -->
    <div v-if="loading" class="loading-row">
      <span class="spinner" />
    </div>

    <div v-else-if="!list.length" class="empty">
      <div class="empty-title">还没有版本记录</div>
      <p>点右上角「补录 v{{ panel.version }}」开始记录。</p>
    </div>

    <ul v-else class="rel-list">
      <li v-for="r in list" :key="r.version" class="rel">
        <div class="rel-head">
          <span class="ver">v{{ r.version }}</span>
          <span v-if="r.version === panel.version" class="chip chip-accent">当前版本</span>
          <span class="date">{{ r.released_at }}</span>
        </div>

        <div v-if="kindsOf(r.notes).length" class="rel-body">
          <div v-for="k in kindsOf(r.notes)" :key="k.key" class="kind">
            <span class="tag" :class="'t-' + k.field">{{ k.key }}</span>
            <ul>
              <li v-for="(t, i) in r.notes[k.key]" :key="i">{{ t }}</li>
            </ul>
          </div>
        </div>
      </li>
    </ul>
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
.pane-head b {
  color: var(--c-accent);
}

.card {
  margin-bottom: 16px;
}

.actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid var(--c-border);
  flex-wrap: wrap;
}
.actions .grow {
  flex: 1 1 auto;
}

.rel-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.rel {
  padding: 14px 16px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  background: var(--c-surface);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

.rel-head {
  display: flex;
  align-items: center;
  gap: 9px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.ver {
  font-size: 14.5px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.date {
  margin-left: auto;
  font-size: 11.5px;
  color: var(--c-text-weak);
}

.kind {
  display: flex;
  gap: 10px;
  margin-bottom: 9px;
}
.kind:last-child {
  margin-bottom: 0;
}

.tag {
  flex: none;
  font-size: 11px;
  padding: 1px 8px;
  border-radius: var(--r-full);
  height: 19px;
  display: inline-flex;
  align-items: center;
}
.t-added {
  background: color-mix(in srgb, var(--c-ok) 16%, transparent);
  color: var(--c-ok);
}
.t-optimized {
  background: var(--c-accent-soft);
  color: var(--c-accent);
}
.t-fixed {
  background: color-mix(in srgb, var(--c-warn) 18%, transparent);
  color: var(--c-warn);
}

.kind ul {
  flex: 1 1 auto;
  min-width: 0;
}
.kind li {
  position: relative;
  padding-left: 13px;
  font-size: 12.5px;
  line-height: 1.75;
  color: var(--c-text-dim);
}
.kind li::before {
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
