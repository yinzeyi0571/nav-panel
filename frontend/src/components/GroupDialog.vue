<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import { err, ok } from '@/utils/ui'
import { usePanelStore } from '@/stores/panel'
import { resolveIconType } from '@/utils/icon'
import IconPicker from './IconPicker.vue'

/** 分组新增 / 编辑（对标 Sun-Panel item_icon_group 的 icon / description 字段） */
const props = defineProps({
  open: { type: Boolean, default: false },
  group: { type: Object, default: null }
})

const emit = defineEmits(['close', 'saved'])

const panel = usePanelStore()
const saving = ref(false)

const form = reactive({
  id: '',
  name: '',
  description: '',
  icon: '',
  icon_bg: '',
  icon_type: 1
})

const isEdit = computed(() => !!form.id)

watch(
  () => props.open,
  (v) => {
    if (!v) return
    const g = props.group
    if (g) {
      Object.assign(form, {
        id: g.id,
        name: g.name || '',
        description: g.description || '',
        icon: g.icon || '',
        icon_bg: g.icon_bg || '',
        icon_type: resolveIconType(0, g.icon || '')
      })
    } else {
      Object.assign(form, { id: '', name: '', description: '', icon: '', icon_bg: '', icon_type: 1 })
    }
  },
  { immediate: true }
)

function close() {
  emit('close')
}

async function submit() {
  const name = form.name.trim()
  if (!name) {
    err('分组名称不能为空')
    return
  }

  saving.value = true
  try {
    const payload = {
      name,
      description: form.description.trim(),
      icon: form.icon.trim(),
      icon_bg: form.icon_bg || ''
    }
    if (isEdit.value) {
      await panel.editCategory({ id: form.id, ...payload })
      ok('分组已更新')
    } else {
      await panel.addCategory(payload)
      ok('分组已创建')
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
      <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-head">
          <h3>{{ isEdit ? '编辑分组' : '新建分组' }}</h3>
          <button class="btn btn-icon btn-ghost" @click="close">✕</button>
        </div>

        <div class="modal-body">
          <div class="field">
            <label class="field-label">分组名称</label>
            <input
              v-model="form.name"
              class="input"
              type="text"
              maxlength="20"
              placeholder="如：常用工具"
            />
          </div>

          <div class="field">
            <label class="field-label">描述</label>
            <input
              v-model="form.description"
              class="input"
              type="text"
              maxlength="200"
              placeholder="可选"
            />
          </div>

          <div class="field" style="margin-bottom: 0">
            <label class="field-label">分组图标</label>
            <IconPicker
              v-model:icon="form.icon"
              v-model:icon-type="form.icon_type"
              v-model:bg="form.icon_bg"
              :name="form.name || '分组'"
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
