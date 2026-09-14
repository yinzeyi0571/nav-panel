<script setup>
import { computed } from 'vue'
import SiteIcon from './SiteIcon.vue'

const props = defineProps({
  site: { type: Object, required: true },
  style: { type: String, default: 'icon' },
  editable: { type: Boolean, default: false },
  selecting: { type: Boolean, default: false },
  selected: { type: Boolean, default: false },
  url: { type: String, default: '' }
})

const emit = defineEmits(['open', 'edit', 'remove', 'menu', 'toggle-select'])

const showDesc = computed(() => props.style === 'detail')
const desc = computed(() => String(props.site.description || '').trim())

function onClick(e) {
  if (props.selecting) {
    emit('toggle-select', props.site.id)
    return
  }
  if (props.editable && (e.ctrlKey || e.metaKey)) {
    // 编辑态下 Ctrl/Cmd + 点击 = 直接编辑，省一次右键
    emit('edit', props.site)
    return
  }
  emit('open', props.site)
}

function onContext(e) {
  emit('menu', { event: e, site: props.site })
}
</script>

<template>
  <div
    class="site-card"
    :data-style="style"
    :class="{ 'is-selected': selected, 'is-broken': !url }"
    :title="url || '未填写地址'"
    @click="onClick"
    @contextmenu.prevent="onContext"
  >
    <span v-if="selecting" class="tick" :class="{ on: selected }">
      <svg v-if="selected" viewBox="0 0 16 16" width="11" height="11" aria-hidden="true">
        <path d="M2 8.5l3.5 3.5L14 3.5" fill="none" stroke="currentColor" stroke-width="2.4"
              stroke-linecap="round" stroke-linejoin="round" />
      </svg>
    </span>

    <SiteIcon
      :name="site.name"
      :icon="site.icon"
      :icon-type="site.icon_type"
      :bg="site.icon_bg"
      :size="style === 'icon' ? 46 : style === 'small' ? 30 : 38"
      :radius="style === 'icon' ? '13px' : '10px'"
    />

    <div class="meta">
      <div class="name truncate">{{ site.name }}</div>
      <div v-if="showDesc" class="desc">
        {{ desc || url }}
      </div>
    </div>

    <div v-if="editable && !selecting" class="ops">
      <button class="op" title="编辑" @click.stop="emit('edit', site)">
        <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
          <path d="M11.5 1.9l2.6 2.6L5.3 13.3l-3.2.6.6-3.2z" fill="none" stroke="currentColor"
                stroke-width="1.5" stroke-linejoin="round" />
        </svg>
      </button>
      <button class="op op-danger" title="删除" @click.stop="emit('remove', site)">
        <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
          <path d="M3 4h10M6.5 4V2.8h3V4M4.3 4l.6 9h6.2l.6-9" fill="none" stroke="currentColor"
                stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
      </button>
    </div>
  </div>
</template>

<style scoped>
.site-card {
  position: relative;
  display: flex;
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  cursor: pointer;
  transition: background var(--dur), border-color var(--dur), transform var(--dur), box-shadow var(--dur);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  min-width: 0;
}

.site-card:hover {
  background: var(--c-surface-2);
  border-color: var(--c-accent);
  transform: translateY(-2px);
  box-shadow: var(--c-shadow-sm);
}

.site-card.is-selected {
  border-color: var(--c-accent);
  background: var(--c-accent-soft);
}

.site-card.is-broken {
  opacity: .55;
}

/* ---- 大图标风格：竖排方块 ---- */
.site-card[data-style='icon'] {
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 18px 10px;
  text-align: center;
  min-height: 108px;
}
.site-card[data-style='icon'] .meta {
  width: 100%;
  min-width: 0;
}
.site-card[data-style='icon'] .name {
  font-size: 13px;
  font-weight: 500;
}

/* ---- 小图标风格：横排紧凑 ---- */
.site-card[data-style='small'] {
  flex-direction: row;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
}
.site-card[data-style='small'] .meta {
  min-width: 0;
  flex: 1 1 auto;
}
.site-card[data-style='small'] .name {
  font-size: 13px;
  font-weight: 500;
}

/* ---- 详情风格：横排两行 ---- */
.site-card[data-style='detail'] {
  flex-direction: row;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
}
.site-card[data-style='detail'] .meta {
  min-width: 0;
  flex: 1 1 auto;
}
.site-card[data-style='detail'] .name {
  font-size: 14px;
  font-weight: 600;
  margin-bottom: 2px;
}
.site-card[data-style='detail'] .desc {
  font-size: 11.5px;
  color: var(--c-text-weak);
  line-height: 1.45;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-all;
}

.meta {
  overflow: hidden;
}

/* ---- 操作按钮 ---- */
.ops {
  position: absolute;
  top: 5px;
  right: 5px;
  display: flex;
  gap: 3px;
  opacity: 0;
  transition: opacity var(--dur);
}
.site-card:hover .ops {
  opacity: 1;
}

.op {
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 6px;
  background: var(--c-surface-solid);
  border: 1px solid var(--c-border);
  color: var(--c-text-dim);
  transition: color var(--dur), border-color var(--dur);
}
.op:hover {
  color: var(--c-accent);
  border-color: var(--c-accent);
}
.op-danger:hover {
  color: var(--c-danger);
  border-color: var(--c-danger);
}

/* ---- 选择态勾选框 ---- */
.tick {
  position: absolute;
  top: 6px;
  left: 6px;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  border: 1.5px solid var(--c-border-strong);
  background: var(--c-surface-solid);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  z-index: 2;
}
.tick.on {
  background: var(--c-accent);
  border-color: var(--c-accent);
  color: var(--c-accent-contrast);
}

/* ---- 拖拽中的占位元素 ---- */
.site-card.sortable-ghost {
  opacity: .35;
  border-style: dashed;
  border-color: var(--c-accent);
  background: var(--c-accent-soft);
}
.site-card.sortable-chosen {
  cursor: grabbing;
}
</style>
