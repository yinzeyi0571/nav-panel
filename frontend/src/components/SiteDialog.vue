<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { err, ok } from '@/utils/ui'
import { usePanelStore } from '@/stores/panel'
import IconPicker from './IconPicker.vue'

/**
 * 站点新增 / 编辑弹窗。
 * 前台和后台共用同一个组件——项目要求「前台直接添加网站」，
 * 所以这个弹窗在前台必须以同样的能力出现，不能只放在 admin 里。
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  site: { type: Object, default: null },
  categories: { type: Array, default: () => [] },
  defaultCategory: { type: [String, Number], default: '' }
})

const emit = defineEmits(['close', 'saved'])

const panel = usePanelStore()
const saving = ref(false)

const form = reactive({
  id: '',
  name: '',
  url: '',
  url_internal: '',
  description: '',
  icon: '',
  icon_type: 1,
  icon_bg: '',
  open_method: 2,
  category_id: ''
})

const isEdit = computed(() => !!form.id)

/**
 * 选一个合法的分组：首选传进来的那个，不合法就用第一个。
 * 站点必须落在某个真实分组上 —— 没有「未分类」这种去处。
 */
function pickCategory(prefer) {
  const list = props.categories || []
  const want = prefer == null ? '' : String(prefer)
  if (want && list.some((c) => String(c.id) === want)) return want
  return list.length ? String(list[0].id) : ''
}

const OPEN_METHODS = [
  { v: 1, label: '当前页打开' },
  { v: 2, label: '新窗口打开' },
  { v: 3, label: '当前页弹窗打开' }
]

function hostOf(u) {
  const s = String(u || '').trim()
  if (!s) return ''
  try {
    const withProto = /^[a-z][a-z0-9+.-]*:\/\//i.test(s) ? s : 'http://' + s
    return new URL(withProto).hostname.replace(/^www\./i, '')
  } catch (e) {
    return ''
  }
}

function reset() {
  const s = props.site
  if (s) {
    Object.assign(form, {
      id: s.id,
      name: s.name || '',
      url: s.url || '',
      url_internal: s.url_internal || '',
      description: s.description || '',
      icon: s.icon || '',
      icon_type: Number(s.icon_type) || 1,
      icon_bg: s.icon_bg || '',
      open_method: Number(s.open_method) || 2,
      category_id: pickCategory(s.category_id)
    })
  } else {
    Object.assign(form, {
      id: '',
      name: '',
      url: '',
      url_internal: '',
      description: '',
      icon: '',
      icon_type: 1,
      icon_bg: '',
      open_method: 2,
      category_id: pickCategory(props.defaultCategory)
    })
  }
}

watch(
  () => props.open,
  (v) => {
    if (v) reset()
  },
  { immediate: true }
)

/** 地址填完、名称还空着时，自动补一个名称 */
function fillName() {
  if (form.name.trim()) return
  const h = hostOf(form.url || form.url_internal)
  if (h) form.name = h
}

function close() {
  emit('close')
}

async function submit() {
  const name = form.name.trim()
  const url = form.url.trim()
  const urlIn = form.url_internal.trim()

  if (!name && !url && !urlIn) {
    err('名称和地址至少要填一个')
    return
  }

  saving.value = true
  try {
    const payload = {
      name,
      url,
      url_internal: urlIn,
      description: form.description.trim(),
      icon: form.icon.trim(),
      icon_type: Number(form.icon_type) || 1,
      icon_bg: form.icon_bg || '',
      open_method: Number(form.open_method) || 2,
      category_id: String(form.category_id || '0')
    }

    if (isEdit.value) {
      await panel.editSite({ id: form.id, ...payload })
      ok('站点已更新')
    } else {
      await panel.addSite(payload)
      ok('站点已添加')
    }
    emit('saved')
    close()
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
          <h3>{{ isEdit ? '编辑站点' : '添加站点' }}</h3>
          <button class="btn btn-icon btn-ghost" title="关闭" @click="close">✕</button>
        </div>

        <div class="modal-body">
          <div class="grid-2">
            <div class="field">
              <label class="field-label">名称</label>
              <input
                v-model="form.name"
                class="input"
                type="text"
                maxlength="60"
                placeholder="如：群晖 NAS"
              />
            </div>
            <div class="field">
              <label class="field-label">分组</label>
              <select v-model="form.category_id" class="select">
                <option v-for="c in categories" :key="c.id" :value="String(c.id)">
                  {{ c.name }}
                </option>
              </select>
            </div>
          </div>

          <div class="field">
            <label class="field-label">外网地址（公网访问）</label>
            <input
              v-model="form.url"
              class="input"
              type="text"
              placeholder="https://example.com 或 example.com"
              @blur="fillName"
            />
          </div>

          <div class="field">
            <label class="field-label">
              内网地址
              <span class="text-weak" style="font-weight: 400">可选</span>
            </label>
            <input
              v-model="form.url_internal"
              class="input"
              type="text"
              placeholder="http://192.168.0.116:8080"
              @blur="fillName"
            />
            <span class="field-hint">
              填了内网地址后，前台可以用「内网 / 外网」按钮一键切换访问地址。
            </span>
          </div>

          <div class="field">
            <label class="field-label">描述</label>
            <input
              v-model="form.description"
              class="input"
              type="text"
              maxlength="200"
              placeholder="可选，详情风格下会显示"
            />
          </div>

          <div class="field">
            <label class="field-label">打开方式</label>
            <div class="tabs" style="align-self: flex-start">
              <button
                v-for="m in OPEN_METHODS"
                :key="m.v"
                :class="{ 'is-active': Number(form.open_method) === m.v }"
                @click="form.open_method = m.v"
              >
                {{ m.label }}
              </button>
            </div>
          </div>

          <div class="field">
            <label class="field-label">图标</label>
            <IconPicker
              v-model:icon="form.icon"
              v-model:icon-type="form.icon_type"
              v-model:bg="form.icon_bg"
              :name="form.name || form.url"
              :site-url="form.url || form.url_internal"
            />
          </div>
        </div>

        <div class="modal-foot">
          <button class="btn" @click="close">取消</button>
          <button class="btn btn-primary" :disabled="saving" @click="submit">
            {{ saving ? '保存中…' : '保存' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
