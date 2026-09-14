<script setup>
import { ref, computed } from 'vue'
import { api } from '@/api'
import { usePanelStore } from '@/stores/panel'
import { err, ok, warn, confirmDialog } from '@/utils/ui'

const panel = usePanelStore()

const importing = ref(false)
const exporting = ref(false)
const mode = ref('merge')
const applySettings = ref(false)
const pasted = ref('')
const fileName = ref('')
const payload = ref(null)
const result = ref(null)
// 解析失败的原因。按钮被禁用时把它显示出来，否则用户只看到按钮是灰的、不知道哪里错了
const parseError = ref('')

const parsedCount = computed(() => {
  const d = payload.value
  if (!d) return null
  return {
    categories: Array.isArray(d.categories) ? d.categories.length : 0,
    sites: Array.isArray(d.sites) ? d.sites.length : 0
  }
})

function stamp() {
  const d = new Date()
  const p = (x) => (x < 10 ? '0' + x : x)
  return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`
}

async function doExport() {
  exporting.value = true
  try {
    const r = await api.backup.export()

    // ⚠️ 接口返回的是 { file, data }，备份本体在 data 里。
    // 之前直接把整个返回值序列化下载，文件顶层就只有 file / data，
    // 再导入时校验不到 categories / sites → 按钮一直是灰的。
    const d = unwrap(r)
    const text = JSON.stringify(d, null, 2)

    const blob = new Blob([text], { type: 'application/json;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = (r && r.file) || `nav-backup-${stamp()}.json`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    setTimeout(() => URL.revokeObjectURL(url), 2000)
    ok('备份已下载')
  } catch (e) {
    err(e.message)
  } finally {
    exporting.value = false
  }
}

/**
 * 把各种形态的备份内容归一成 { categories, sites, settings }
 *
 * 兼容三种：
 *   1. 干净的备份对象（本项目的标准格式）
 *   2. 接口外壳 { file, data: {...} } —— v2.1.0 及更早导出的文件长这样
 *   3. 手工包了一层的 { data: {...} }
 */
function unwrap(raw) {
  let d = raw
  for (let i = 0; i < 3; i++) {
    if (!d || typeof d !== 'object' || Array.isArray(d)) break
    if (Array.isArray(d.categories) || Array.isArray(d.sites)) break
    if (d.data && typeof d.data === 'object' && !Array.isArray(d.data)) d = d.data
    else break
  }
  return d
}

function parseText(text, silent = false) {
  if (!text || !text.trim()) {
    parseError.value = '请先选择文件或粘贴备份内容'
    if (!silent) warn(parseError.value)
    return false
  }
  try {
    // 去掉 UTF-8 BOM —— 用记事本另存过的文件会带，JSON.parse 会直接报错
    const raw = JSON.parse(text.replace(/^\uFEFF/, ''))
    const d = unwrap(raw)
    if (!d || typeof d !== 'object' || Array.isArray(d)) throw new Error('内容不是备份对象')
    if (!Array.isArray(d.categories) && !Array.isArray(d.sites)) {
      throw new Error('备份里既没有 categories 也没有 sites')
    }
    payload.value = d
    parseError.value = ''
    if (!silent) ok('已解析备份文件')
    return true
  } catch (e) {
    payload.value = null
    parseError.value = e.message
    if (!silent) err('解析失败：' + e.message)
    return false
  }
}

function onFile(e) {
  const f = e.target.files && e.target.files[0]
  if (!f) return
  fileName.value = f.name
  const reader = new FileReader()
  reader.onload = () => {
    pasted.value = String(reader.result || '')
    parseText(pasted.value)
  }
  reader.onerror = () => err('读取文件失败')
  reader.readAsText(f, 'utf-8')
  e.target.value = ''
}

function onPasteInput() {
  if (pasted.value.trim()) parseText(pasted.value)
  else {
    payload.value = null
    parseError.value = ''
  }
}

async function doImport() {
  // 粘贴完直接点按钮时 textarea 的 change 事件可能还没触发，这里补解析一次
  if (!payload.value && pasted.value.trim()) parseText(pasted.value, true)

  if (!payload.value) {
    err(parseError.value ? '解析失败：' + parseError.value : '请先选择备份文件或粘贴备份内容')
    return
  }

  const msg = mode.value === 'replace'
    ? `覆盖导入会用备份内容替换当前的全部 ${panel.categories.length} 个分组和 ${panel.sites.length} 个站点。确定继续吗？`
    : `合并导入会把备份里的内容追加进来，不会删除你现在的站点。确定继续吗？`

  const yes = await confirmDialog(msg, {
    title: '导入确认',
    okText: '开始导入',
    danger: mode.value === 'replace'
  })
  if (!yes) return

  importing.value = true
  try {
    const d = await api.backup.import(payload.value, mode.value, applySettings.value)
    result.value = d
    ok(d.sites !== undefined ? `导入完成：新增 ${d.categories} 个分组、${d.sites} 个站点` : '导入完成')
    await panel.load(true)
  } catch (e) {
    err(e.message)
  } finally {
    importing.value = false
  }
}

function clear() {
  pasted.value = ''
  fileName.value = ''
  payload.value = null
  result.value = null
  parseError.value = ''
}
</script>

<template>
  <div class="pane">
    <div class="pane-head">
      <div>
        <h2>导入 / 导出</h2>
        <p class="text-dim">导出结果为 JSON，含分组、站点与外观设置，可直接当备份留存。</p>
      </div>
    </div>

    <section class="card">
      <div class="card-title">
        <span>导出备份</span>
        <button class="btn btn-sm btn-primary" :disabled="exporting" @click="doExport">
          {{ exporting ? '导出中…' : '下载备份文件' }}
        </button>
      </div>
      <p class="field-hint">
        当前有 {{ panel.categories.length }} 个分组、{{ panel.sites.length }} 个站点。
        导出的文件里包含外观设置。
      </p>
    </section>

    <section class="card">
      <div class="card-title">导入备份</div>

      <div class="field">
        <label class="field-label">选择文件</label>
        <div class="row row-wrap">
          <label class="btn btn-sm">
            <input type="file" accept=".json,application/json" hidden @change="onFile" />
            选择 JSON 文件
          </label>
          <span v-if="fileName" class="chip">{{ fileName }}</span>
          <button v-if="pasted" class="btn btn-sm btn-ghost" @click="clear">清空</button>
        </div>
      </div>

      <div class="field">
        <label class="field-label">或直接粘贴内容</label>
        <textarea
          v-model="pasted"
          class="textarea mono"
          rows="6"
          placeholder='{"categories": [...], "sites": [...]}'
          @change="onPasteInput"
        />
      </div>

      <div v-if="parsedCount" class="parsed">
        已解析：<b>{{ parsedCount.categories }}</b> 个分组、<b>{{ parsedCount.sites }}</b> 个站点
      </div>

      <div v-if="parseError" class="parse-err">解析失败：{{ parseError }}</div>

      <div class="field">
        <label class="field-label">导入方式</label>
        <div class="tabs" style="align-self: flex-start">
          <button :class="{ 'is-active': mode === 'merge' }" @click="mode = 'merge'">合并（推荐）</button>
          <button :class="{ 'is-active': mode === 'replace' }" @click="mode = 'replace'">覆盖</button>
        </div>
        <span class="field-hint">
          <template v-if="mode === 'merge'">
            同名分组会复用，不重复创建；已有站点全部保留。适合把另一份备份并进来。
          </template>
          <template v-else>
            会先清空当前全部分组和站点，再用备份内容重建。不可撤销，请先导出一次备份。
          </template>
        </span>
      </div>

      <div class="switch-row" style="border: none; padding-bottom: 0">
        <div class="switch-row-text">
          <span class="switch-row-title">同时导入外观设置</span>
          <span class="switch-row-hint">主题、壁纸、布局参数也会被备份里的值覆盖</span>
        </div>
        <button class="switch" :class="{ 'is-on': applySettings }" @click="applySettings = !applySettings" />
      </div>

      <div class="actions">
        <button class="btn btn-primary btn-sm" :disabled="importing || !payload" @click="doImport">
          {{ importing ? '导入中…' : '开始导入' }}
        </button>
        <span v-if="!payload" class="field-hint" style="align-self: center">
          先选择文件或粘贴内容，解析成功后按钮才可点
        </span>
      </div>

      <div v-if="result" class="result">
        <div>
          新增 <b>{{ result.categories }}</b> 个分组、<b>{{ result.sites }}</b> 个站点
          <template v-if="result.skipped && result.skipped.length">
            ，跳过 {{ result.skipped.length }} 个
          </template>
        </div>
        <div class="text-weak" style="margin-top: 3px">
          导入后总计 {{ result.total_categories }} 个分组、{{ result.total_sites }} 个站点。
        </div>
        <ul v-if="result.skipped && result.skipped.length" class="skip-list">
          <li v-for="(s, i) in result.skipped" :key="i">{{ s.name || '（无名称）' }} —— {{ s.reason }}</li>
        </ul>
      </div>
    </section>
  </div>
</template>

<style scoped>
.pane-head {
  margin-bottom: 16px;
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

.parsed {
  margin: -4px 0 14px;
  font-size: 12.5px;
  color: var(--c-text-dim);
}
.parsed b {
  color: var(--c-accent);
}

.parse-err {
  margin: -4px 0 14px;
  font-size: 12.5px;
  color: #e5484d;
  line-height: 1.6;
}

.actions {
  display: flex;
  gap: 8px;
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--c-border);
}

.result {
  margin-top: 14px;
  padding: 11px 13px;
  border-radius: var(--r-sm);
  background: var(--c-accent-soft);
  border: 1px solid var(--c-border);
  font-size: 12.5px;
}
.result b {
  color: var(--c-accent);
}

.skip-list {
  margin-top: 6px;
  max-height: 140px;
  overflow-y: auto;
  color: var(--c-text-dim);
  font-size: 12px;
}
.skip-list li {
  line-height: 1.7;
}
</style>
