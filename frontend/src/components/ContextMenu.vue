<script setup>
import { ref, watch, onMounted, onUnmounted, nextTick } from 'vue'

/**
 * 通用右键菜单。
 * 位置做了边界收敛：靠右/靠下时会自动往回收，不会跑出视口。
 */
const props = defineProps({
  open: { type: Boolean, default: false },
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
  items: { type: Array, default: () => [] }
})

const emit = defineEmits(['close', 'pick'])

const el = ref(null)
const pos = ref({ left: 0, top: 0 })

async function place() {
  await nextTick()
  const node = el.value
  if (!node) return

  const w = node.offsetWidth
  const h = node.offsetHeight
  const vw = window.innerWidth
  const vh = window.innerHeight

  let left = props.x
  let top = props.y

  if (left + w > vw - 8) left = Math.max(8, vw - w - 8)
  if (top + h > vh - 8) top = Math.max(8, vh - h - 8)

  pos.value = { left, top }
}

watch(
  () => [props.open, props.x, props.y],
  () => {
    if (props.open) place()
  }
)

function onDocClick() {
  if (props.open) emit('close')
}

function onKey(e) {
  if (props.open && e.key === 'Escape') emit('close')
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('contextmenu', onDocClick)
  window.addEventListener('keydown', onKey)
  window.addEventListener('resize', onDocClick)
  window.addEventListener('scroll', onDocClick, true)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('contextmenu', onDocClick)
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('resize', onDocClick)
  window.removeEventListener('scroll', onDocClick, true)
})

function pick(item) {
  if (item.disabled) return
  emit('pick', item)
  emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      ref="el"
      class="ctx-menu"
      :style="{ left: pos.left + 'px', top: pos.top + 'px' }"
      @click.stop
      @contextmenu.prevent.stop
    >
      <template v-for="(it, i) in items" :key="i">
        <div v-if="it.divider" class="ctx-divider" />
        <button
          v-else
          class="ctx-item"
          :class="{ 'is-danger': it.danger, 'is-disabled': it.disabled }"
          :disabled="it.disabled"
          @click="pick(it)"
        >
          <span class="ctx-label">{{ it.label }}</span>
          <span v-if="it.hint" class="ctx-hint">{{ it.hint }}</span>
        </button>
      </template>
    </div>
  </Teleport>
</template>

<style scoped>
.ctx-menu {
  position: fixed;
  z-index: 2500;
  min-width: 176px;
  padding: 5px;
  background: var(--c-surface-solid);
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  box-shadow: var(--c-shadow);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  animation: ctx-in .12s ease;
}

@keyframes ctx-in {
  from { opacity: 0; transform: scale(.97); }
  to   { opacity: 1; transform: none; }
}

.ctx-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  padding: 7px 10px;
  border-radius: 7px;
  font-size: 13px;
  text-align: left;
  color: var(--c-text);
  transition: background var(--dur), color var(--dur);
}
.ctx-item:hover:not(.is-disabled) {
  background: var(--c-accent-soft);
  color: var(--c-accent);
}
.ctx-item.is-danger:hover:not(.is-disabled) {
  background: color-mix(in srgb, var(--c-danger) 14%, transparent);
  color: var(--c-danger);
}
.ctx-item.is-disabled {
  opacity: .42;
  cursor: not-allowed;
}

.ctx-hint {
  font-size: 11px;
  color: var(--c-text-weak);
}

.ctx-divider {
  height: 1px;
  margin: 4px 6px;
  background: var(--c-border);
}
</style>
