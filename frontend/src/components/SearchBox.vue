<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { usePanelStore } from '@/stores/panel'

const panel = usePanelStore()

const ENGINES = [
  { k: 'baidu', name: '百度', url: 'https://www.baidu.com/s?wd=' },
  { k: 'bing', name: 'Bing', url: 'https://www.bing.com/search?q=' },
  { k: 'google', name: 'Google', url: 'https://www.google.com/search?q=' },
  { k: 'github', name: 'GitHub', url: 'https://github.com/search?q=' },
  { k: 'sogou', name: '搜狗', url: 'https://www.sogou.com/web?query=' }
]

const kw = ref('')
const open = ref(false)
const engine = ref(panel.settings.search_engine || 'baidu')
const boxRef = ref(null)

watch(
  () => panel.settings.search_engine,
  (v) => {
    if (v) engine.value = v
  }
)

const current = computed(() => ENGINES.find((e) => e.k === engine.value) || ENGINES[0])

/** 站内匹配：边打边找自己的站点 */
const matches = computed(() => {
  const q = kw.value.trim().toLowerCase()
  if (!q) return []
  return panel.sites
    .filter((s) => {
      const hay = `${s.name} ${s.description || ''} ${s.url || ''} ${s.url_internal || ''}`.toLowerCase()
      return hay.includes(q)
    })
    .slice(0, 8)
})

function engineUrl(q) {
  const t = current.value
  // 百度用 wd，其余用 q，这里按引擎拼
  const key = t.k === 'baidu' ? 'wd' : t.k === 'sogou' ? 'query' : 'q'
  const base = t.url.split('?')[0]
  return `${base}?${key}=${encodeURIComponent(q)}`
}

function submit() {
  const q = kw.value.trim()
  if (!q) return
  window.open(engineUrl(q), '_blank', 'noopener')
  open.value = false
}

function pickSite(s) {
  panel.openSite(s)
  kw.value = ''
  open.value = false
}

function onFocus() {
  if (kw.value.trim()) open.value = true
}

function onInput() {
  open.value = !!kw.value.trim()
}

function onClickOutside(e) {
  if (boxRef.value && !boxRef.value.contains(e.target)) open.value = false
}

function onKeydown(e) {
  // 全局按 / 聚焦搜索框（对标常见的站内搜索快捷键）
  if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
    e.preventDefault()
    const input = boxRef.value && boxRef.value.querySelector('input')
    if (input) input.focus()
  }
}

onMounted(() => {
  document.addEventListener('click', onClickOutside)
  window.addEventListener('keydown', onKeydown)
})
onUnmounted(() => {
  document.removeEventListener('click', onClickOutside)
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <div ref="boxRef" class="search">
    <div class="search-bar">
      <svg class="search-ic" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
        <circle cx="7" cy="7" r="4.6" fill="none" stroke="currentColor" stroke-width="1.6" />
        <path d="M10.6 10.6L14 14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
      </svg>
      <input
        v-model="kw"
        type="text"
        placeholder="搜索站点，回车用搜索引擎"
        @focus="onFocus"
        @input="onInput"
        @keyup.enter="matches.length && !kw.startsWith(' ') ? pickSite(matches[0]) : submit()"
        @keyup.esc="open = false"
      />
      <select v-model="engine" class="search-engine" title="搜索引擎">
        <option v-for="e in ENGINES" :key="e.k" :value="e.k">{{ e.name }}</option>
      </select>
    </div>

    <div v-if="open && matches.length" class="search-drop">
      <button v-for="s in matches" :key="s.id" class="drop-item" @click="pickSite(s)">
        <span class="drop-name truncate">{{ s.name }}</span>
        <span class="drop-url truncate">{{ s.url || s.url_internal }}</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.search {
  position: relative;
  width: 100%;
  max-width: 320px;
}

.search-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 36px;
  padding: 0 6px 0 11px;
  border-radius: var(--r-full);
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  transition: border-color var(--dur), background var(--dur);
}
.search-bar:focus-within {
  border-color: var(--c-accent);
  background: var(--c-surface-2);
}

.search-ic {
  color: var(--c-text-weak);
  flex: none;
}

.search-bar input {
  flex: 1 1 auto;
  min-width: 0;
  border: none;
  background: transparent;
  outline: none;
  font-size: 13px;
}

.search-engine {
  flex: none;
  border: none;
  background: transparent;
  font-size: 11px;
  color: var(--c-text-dim);
  outline: none;
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 6px;
  max-width: 78px;
}
.search-engine:hover {
  color: var(--c-accent);
}
.search-engine option {
  background: var(--c-surface-solid);
  color: var(--c-text);
}

.search-drop {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: 300;
  background: var(--c-surface-solid);
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  box-shadow: var(--c-shadow);
  overflow: hidden;
  max-height: 320px;
  overflow-y: auto;
}

.drop-item {
  display: flex;
  align-items: baseline;
  gap: 8px;
  width: 100%;
  padding: 8px 12px;
  text-align: left;
  border-bottom: 1px solid var(--c-border);
  transition: background var(--dur);
}
.drop-item:last-child {
  border-bottom: none;
}
.drop-item:hover {
  background: var(--c-accent-soft);
}
.drop-name {
  font-size: 13px;
  font-weight: 500;
  flex: none;
  max-width: 46%;
}
.drop-url {
  font-size: 11px;
  color: var(--c-text-weak);
  flex: 1 1 auto;
  min-width: 0;
}

@media (max-width: 720px) {
  .search {
    max-width: none;
  }
}
</style>
