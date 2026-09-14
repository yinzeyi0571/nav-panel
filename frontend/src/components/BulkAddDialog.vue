<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { api } from '@/api'
import { err, ok } from '@/utils/ui'
import { usePanelStore } from '@/stores/panel'

/**
 * 批量添加（对标 Sun-Panel 的 addMultiple）。
 * 三种贴法都支持：一行一个网址 / 「名称|网址」/ 名称与网址分两栏对齐。
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  categories: { type: Array, default: () => [] },
  defaultCategory: { type: [String, Number], default: '' }
})

const emit = defineEmits(['close', 'saved'])

const panel = usePanelStore()

const mode = ref('single')
const text = ref('')
const names = ref('')
const urls = ref('')
const categoryId = ref('')
const saving = ref(false)
const result = ref(null)

/** 合法分组：首选传进来的，不合法就用第一个（站点必须有归属的分组） */
function pickCategory(prefer) {
  const list = props.categories || []
  const want = prefer == null ? '' : String(prefer)
  if (want && list.some((c) => String(c.id) === want)) return want
  return list.length ? String(list[0].id) : ''
}

watch(
  () => props.open,
  (v) => {
    if (v) {
      mode.value = 'single'
      text.value = ''
      names.value = ''
      urls.value = ''
      categoryId.value = pickCategory(props.defaultCategory)
      result.value = null
    }
  }
)

const lineCount = computed(() => {
  if (mode.value === 'single') {
    return text.value.split('\n').filter((l) => l.trim()).length
  }
  const a = names.value.split('\n').filter((l) => l.trim()).length
  const b = urls.value.split('\n').filter((l) => l.trim()).length
  return Math.max(a, b)
})

function toLines(s) {
  return String(s || '')
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
}

function close() {
  emit('close')
}

async function submit() {
  if (!lineCount.value) {
    err('请先粘贴要添加的内容')
    return
  }

  saving.value = true
  try {
    let payload
    if (mode.value === 'single') {
      payload = { category_id: categoryId.value, items: toLines(text.value) }
    } else {
      payload = {
        category_id: categoryId.value,
        names: toLines(names.value),
        urls: toLines(urls.value)
      }
    }

    const d = await api.sites.addMultiple(payload)
    result.value = d
    ok(`已添加 ${d.added} 个站点`)
    await panel.load(true)
    emit('saved')
  } catch (e) {
    err(e.message)
  } finally {
    saving.value = false
  }
}

function onKeydown(e) {
  if (!props.open) return
  if (e.key === 'Escape') {
    e.preventDefault()
    close()
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="modal-backdrop" @click.self="close">
      <div class="modal modal-lg" role="dialog" aria-modal="true">
        <div class="modal-head">
          <h3>批量添加站点</h3>
          <button class="btn btn-icon btn-ghost" @click="close">✕</button>
        </div>

        <div class="modal-body">
          <div class="row row-between" style="margin-bottom: 12px">
            <div class="tabs">
              <button :class="{ 'is-active': mode === 'single' }" @click="mode = 'single'">
                一行一个
              </button>
              <button :class="{ 'is-active': mode === 'pair' }" @click="mode = 'pair'">
                名称 + 网址
              </button>
            </div>
            <select v-model="categoryId" class="select" style="max-width: 180px">
              <option v-for="c in categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
          </div>

          <template v-if="mode === 'single'">
            <div class="field">
              <label class="field-label">每行一条</label>
              <textarea
                v-model="text"
                class="textarea"
                rows="9"
                placeholder="https://www.example.com&#10;群晖|192.168.1.10&#10;https://github.com"
              />
              <span class="field-hint">
                支持两种写法：只写网址（自动用域名当名称），或
                <code class="mono">名称|网址</code>。
                只填括号里的中文说明会被拦下来，不会建成垃圾站点。
              </span>
            </div>
          </template>

          <template v-else>
            <div class="grid-2">
              <div class="field">
                <label class="field-label">名称</label>
                <textarea v-model="names" class="textarea" rows="9" placeholder="群晖&#10;路由器&#10;博客" />
              </div>
              <div class="field">
                <label class="field-label">网址</label>
                <textarea
                  v-model="urls"
                  class="textarea"
                  rows="9"
                  placeholder="192.168.1.10&#10;192.168.1.1&#10;https://blog.example.com"
                />
              </div>
            </div>
            <p class="field-hint">两边按行号一一对应，行数不一致时以多的为准。</p>
          </template>

          <div v-if="result" class="result">
            <div class="result-line">
              成功 <b>{{ result.added }}</b> 个
              <template v-if="result.skipped && result.skipped.length">
                ，跳过 <b>{{ result.skipped.length }}</b> 个
              </template>
            </div>
            <ul v-if="result.skipped && result.skipped.length" class="result-list">
              <li v-for="(s, i) in result.skipped" :key="i">
                {{ s.name || '（无名称）' }} —— {{ s.reason }}
              </li>
            </ul>
          </div>

          <p class="field-hint" style="margin-top: 10px">
            目前共 {{ lineCount }} 条待添加。一次最多 200 条。
          </p>
        </div>

        <div class="modal-foot">
          <button class="btn" @click="close">关闭</button>
          <button class="btn btn-primary" :disabled="saving || !lineCount" @click="submit">
            {{ saving ? '添加中…' : '开始添加' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.result {
  margin-top: 12px;
  padding: 10px 12px;
  border-radius: var(--r-sm);
  background: var(--c-accent-soft);
  border: 1px solid var(--c-border);
  font-size: 12px;
}
.result-line {
  font-size: 12.5px;
}
.result-list {
  margin-top: 6px;
  padding-left: 4px;
  max-height: 120px;
  overflow-y: auto;
  color: var(--c-text-dim);
}
.result-list li {
  line-height: 1.7;
}

code {
  background: var(--c-surface-2);
  padding: 1px 5px;
  border-radius: 4px;
}
</style>
