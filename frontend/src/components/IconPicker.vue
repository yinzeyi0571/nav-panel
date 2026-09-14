<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { api } from '@/api'
import { ok, err } from '@/utils/ui'
import { iconifySrc, PRESET_BG } from '@/utils/icon'
import SiteIcon from './SiteIcon.vue'

/**
 * 图标三态选择器（对标 Sun-Panel 的图标设置面板）
 *   文字 / 图片（上传·外链·自动抓取）/ 在线图标（Iconify 搜索）
 */
const props = defineProps({
  icon: { type: String, default: '' },
  iconType: { type: [Number, String], default: 1 },
  bg: { type: String, default: '' },
  name: { type: String, default: '' },
  siteUrl: { type: String, default: '' }
})

const emit = defineEmits(['update:icon', 'update:iconType', 'update:bg'])

const tab = ref(Number(props.iconType) || 1)
const keyword = ref('')
const searching = ref(false)
const results = ref([])
const searched = ref(false)
const myFiles = ref([])
const filesLoaded = ref(false)
const uploading = ref(false)
const fetching = ref(false)
const urlInput = ref('')

/** 外部改了类型（比如点了「自动识别」）时同步 tab */
watch(
  () => props.iconType,
  (v) => {
    const n = Number(v) || 1
    if (n !== tab.value) tab.value = n
  }
)

const HOT = ['home', 'folder', 'link', 'star', 'cloud', 'server', 'video', 'music',
  'image', 'book', 'code', 'database', 'mail', 'settings', 'user', 'globe']

const previewType = computed(() => Number(props.iconType) || 1)

function setTab(t) {
  tab.value = t
  emit('update:iconType', t)
  if (t === 2) loadFiles()
}

function maskStyle(name) {
  const url = `url("${iconifySrc(name)}")`
  return {
    WebkitMaskImage: url,
    maskImage: url,
    WebkitMaskSize: 'contain',
    maskSize: 'contain',
    WebkitMaskRepeat: 'no-repeat',
    maskRepeat: 'no-repeat',
    WebkitMaskPosition: 'center',
    maskPosition: 'center',
    backgroundColor: 'currentColor'
  }
}

async function doSearch(q) {
  const kw = (typeof q === 'string' ? q : keyword.value).trim()
  if (!kw) return
  keyword.value = kw
  searching.value = true
  searched.value = true
  try {
    const d = await api.icons.search(kw, 96)
    results.value = d.icons || []
  } catch (e) {
    results.value = []
    err(e.message)
  } finally {
    searching.value = false
  }
}

function pickIcon(name) {
  emit('update:icon', name)
  emit('update:iconType', 3)
  ok('已选择 ' + name)
}

function pickFile(src) {
  emit('update:icon', src)
  emit('update:iconType', 2)
}

async function onFile(e) {
  const f = e.target.files && e.target.files[0]
  if (!f) return
  uploading.value = true
  try {
    const d = await api.icons.upload(f)
    emit('update:icon', d.icon)
    emit('update:iconType', 2)
    ok('上传成功')
    await loadFiles()
  } catch (e2) {
    err(e2.message)
  } finally {
    uploading.value = false
    e.target.value = ''
  }
}

async function doFetch() {
  const u = (urlInput.value || props.siteUrl || '').trim()
  if (!u) {
    err('请先填写网址')
    return
  }
  fetching.value = true
  try {
    const d = await api.icons.fetch(u, true)
    emit('update:icon', d.icon)
    emit('update:iconType', 2)
    ok(d.cached ? '已使用缓存图标' : '图标抓取成功')
    await loadFiles()
  } catch (e) {
    err(e.message)
  } finally {
    fetching.value = false
  }
}

async function loadFiles() {
  try {
    const d = await api.icons.listFiles()
    myFiles.value = d.files || []
    filesLoaded.value = true
  } catch (e) {
    /* 未登录等情况静默处理 */
  }
}

function clearIcon() {
  emit('update:icon', '')
  emit('update:iconType', 1)
}

onMounted(() => {
  if (tab.value === 2) loadFiles()
})
</script>

<template>
  <div class="icon-picker">
    <!-- 预览 -->
    <div class="preview">
      <SiteIcon :name="name" :icon="icon" :icon-type="previewType" :bg="bg" :size="52" radius="14px" />
      <div class="preview-meta">
        <div class="preview-name truncate">{{ name || '未命名' }}</div>
        <div class="preview-val truncate mono">
          {{ icon || (previewType === 1 ? '（使用名称首字符）' : '未设置') }}
        </div>
      </div>
      <button v-if="icon" class="btn btn-sm btn-ghost" title="清空图标" @click="clearIcon">清空</button>
    </div>

    <!-- 类型切换 -->
    <div class="tabs" style="margin-bottom: 12px">
      <button :class="{ 'is-active': tab === 1 }" @click="setTab(1)">文字</button>
      <button :class="{ 'is-active': tab === 2 }" @click="setTab(2)">图片</button>
      <button :class="{ 'is-active': tab === 3 }" @click="setTab(3)">在线图标</button>
    </div>

    <!-- 文字 -->
    <div v-if="tab === 1" class="pane">
      <p class="field-hint">
        不设置图标，直接显示名称的第一个字。中文取首字，英文取首字母。
      </p>
    </div>

    <!-- 图片 -->
    <div v-else-if="tab === 2" class="pane">
      <div class="row row-wrap" style="margin-bottom: 10px">
        <label class="btn btn-sm" :class="{ 'is-disabled': uploading }">
          <input type="file" accept="image/*" hidden @change="onFile" />
          {{ uploading ? '上传中…' : '上传图片' }}
        </label>
        <button class="btn btn-sm" :disabled="fetching" @click="doFetch">
          {{ fetching ? '抓取中…' : '自动抓取图标' }}
        </button>
      </div>

      <div class="field">
        <label class="field-label">图片地址</label>
        <input
          v-model="urlInput"
          class="input"
          type="text"
          :placeholder="siteUrl || 'https://example.com 或 /uploads/...'"
          @keyup.enter="emit('update:icon', urlInput.trim()); emit('update:iconType', 2)"
        />
        <span class="field-hint">
          留空时抓取按钮会用站点的地址。支持本站上传路径、外链、以及粘贴任意图片地址。
        </span>
        <button
          v-if="urlInput.trim()"
          class="btn btn-sm"
          style="align-self: flex-start"
          @click="emit('update:icon', urlInput.trim()); emit('update:iconType', 2); ok('已应用')"
        >
          用这个地址
        </button>
      </div>

      <div v-if="filesLoaded && myFiles.length" class="field">
        <label class="field-label">我的图标（{{ myFiles.length }}）</label>
        <div class="file-grid">
          <button
            v-for="f in myFiles"
            :key="f.id"
            class="file-cell"
            :class="{ on: icon === f.src }"
            :title="f.file_name"
            @click="pickFile(f.src)"
          >
            <img :src="f.src" alt="" loading="lazy" />
          </button>
        </div>
      </div>
      <p v-else-if="filesLoaded" class="field-hint">还没有上传过图标。</p>
    </div>

    <!-- 在线图标 -->
    <div v-else class="pane">
      <div class="row" style="margin-bottom: 10px">
        <input
          v-model="keyword"
          class="input grow"
          type="text"
          placeholder="搜索图标，如 home / server / 云"
          @keyup.enter="doSearch()"
        />
        <button class="btn btn-sm btn-primary" :disabled="searching" @click="doSearch()">
          {{ searching ? '搜索中…' : '搜索' }}
        </button>
      </div>

      <div class="hot-row">
        <button v-for="h in HOT" :key="h" class="chip" @click="doSearch(h)">{{ h }}</button>
      </div>

      <div v-if="searching" class="loading-row">
        <span class="spinner" />
      </div>

      <template v-else>
        <div v-if="results.length" class="icon-grid">
          <button
            v-for="n in results"
            :key="n"
            class="icon-cell"
            :class="{ on: icon === n }"
            :title="n"
            @click="pickIcon(n)"
          >
            <span class="icon-mask" :style="maskStyle(n)" />
          </button>
        </div>
        <p v-else-if="searched" class="field-hint">没有找到匹配的图标，换个关键词试试。</p>
        <p v-else class="field-hint">
          图标来自 Iconify 在线图标库（20 万+ 图标）。搜索后点选即可，图标名会存成
          <code class="mono">集合:名称</code> 的形式。
        </p>
      </template>
    </div>

    <!-- 底色 -->
    <div class="field" style="margin-top: 14px; margin-bottom: 0">
      <label class="field-label">图标底色</label>
      <div class="row row-wrap">
        <button
          v-for="c in PRESET_BG"
          :key="c || 'none'"
          class="bg-dot"
          :class="{ on: (bg || '') === c }"
          :style="{ background: c || 'transparent' }"
          :title="c || '透明'"
          @click="emit('update:bg', c)"
        />
        <label class="bg-dot bg-custom" title="自定义颜色">
          <input type="color" :value="bg || '#2a2a2a'" @input="emit('update:bg', $event.target.value)" />
        </label>
      </div>
    </div>
  </div>
</template>

<style scoped>
.preview {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  background: var(--c-surface);
  margin-bottom: 12px;
}
.preview-meta {
  flex: 1 1 auto;
  min-width: 0;
}
.preview-name {
  font-size: 13px;
  font-weight: 600;
}
.preview-val {
  color: var(--c-text-weak);
  margin-top: 2px;
}

.pane {
  min-height: 60px;
}

.hot-row {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-bottom: 10px;
}

.icon-grid,
.file-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(46px, 1fr));
  gap: 6px;
  max-height: 240px;
  overflow-y: auto;
  padding: 2px;
}

.icon-cell,
.file-cell {
  aspect-ratio: 1 / 1;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--c-border);
  border-radius: var(--r-sm);
  background: var(--c-surface);
  color: var(--c-text);
  transition: border-color var(--dur), background var(--dur), color var(--dur);
  padding: 9px;
}
.icon-cell:hover,
.file-cell:hover {
  border-color: var(--c-accent);
  color: var(--c-accent);
  background: var(--c-accent-soft);
}
.icon-cell.on,
.file-cell.on {
  border-color: var(--c-accent);
  background: var(--c-accent-soft);
  color: var(--c-accent);
}

.icon-mask {
  width: 100%;
  height: 100%;
  display: block;
}

.file-cell img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.bg-dot {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid var(--c-border);
  position: relative;
  overflow: hidden;
  flex: none;
}
.bg-dot:first-child {
  background-image: linear-gradient(45deg, #999 25%, transparent 25%, transparent 75%, #999 75%),
                    linear-gradient(45deg, #999 25%, transparent 25%, transparent 75%, #999 75%);
  background-size: 8px 8px;
  background-position: 0 0, 4px 4px;
  background-color: transparent;
}
.bg-dot.on {
  border-color: var(--c-accent);
  box-shadow: 0 0 0 2px var(--c-accent-soft);
}
.bg-custom {
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  background: conic-gradient(red, yellow, lime, aqua, blue, magenta, red);
}
.bg-custom input {
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
  border: none;
  padding: 0;
}

code {
  background: var(--c-surface-2);
  padding: 1px 5px;
  border-radius: 4px;
}
</style>
