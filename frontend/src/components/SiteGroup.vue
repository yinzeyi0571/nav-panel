<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import Sortable from 'sortablejs'
import SiteIcon from './SiteIcon.vue'
import SiteCard from './SiteCard.vue'

/**
 * 一个分组及其站点网格。
 *
 * 拖拽由本组件自己持有 Sortable 实例（而不是集中放在父组件里），
 * 原因是分组会动态增删，父组件维护「容器元素 → 实例」的映射很容易失配；
 * 让每个网格自己管自己，创建和销毁都跟着组件生命周期走，最稳。
 *
 * 跨分组拖动靠 group.name 相同实现（所有网格用同一个名字 nav-sites）。
 * 松手后把原始事件抛给父组件，由父组件统一改数据 + 还原 DOM + 提交。
 */
const props = defineProps({
  group: { type: Object, required: true },
  style: { type: String, default: 'icon' },
  editable: { type: Boolean, default: false },
  selecting: { type: Boolean, default: false },
  selectedIds: { type: Array, default: () => [] },
  showGroupIcon: { type: Boolean, default: true }
})

const emit = defineEmits([
  'open', 'edit', 'remove', 'menu', 'toggle-select',
  'add', 'add-multiple', 'edit-group', 'remove-group', 'resort'
])

const gridRef = ref(null)
let sortable = null

const count = computed(() => props.group.sites.length)

function isSelected(id) {
  return props.selectedIds.some((x) => String(x) === String(id))
}

function clearDropOver() {
  document.querySelectorAll('.site-grid.is-drop-over').forEach((el) => {
    el.classList.remove('is-drop-over')
  })
}

function createSortable() {
  if (!props.editable || !gridRef.value || sortable) return

  sortable = Sortable.create(gridRef.value, {
    group: { name: 'nav-sites', pull: true, put: true },
    animation: 160,
    // 操作按钮不参与拖拽，但仍然可以作为落点
    filter: '.op, .tick',
    preventOnFilter: false,
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    dragClass: 'sortable-drag',
    // 触屏设备上留一点容差，避免滑动手势误触发拖动
    touchStartThreshold: 4,

    onStart() {
      document.body.classList.add('is-dragging')
    },

    onMove(evt) {
      clearDropOver()
      if (evt.to && evt.to !== evt.from) evt.to.classList.add('is-drop-over')
      return true
    },

    onEnd(evt) {
      document.body.classList.remove('is-dragging')
      clearDropOver()
      emit('resort', evt)
    }
  })
}

function destroySortable() {
  if (sortable) {
    sortable.destroy()
    sortable = null
  }
}

onMounted(() => nextTick(createSortable))
onUnmounted(destroySortable)

watch(
  () => props.editable,
  (v) => {
    if (v) nextTick(createSortable)
    else destroySortable()
  }
)

/** 选中/编辑态下禁用拖拽，避免和点选冲突 */
watch(
  () => props.selecting,
  (v) => {
    if (!sortable) return
    sortable.option('disabled', v)
  }
)
</script>

<template>
  <section class="group">
    <header class="group-head">
      <SiteIcon
        v-if="showGroupIcon && group.icon"
        :name="group.name"
        :icon="group.icon"
        :icon-type="0"
        :bg="group.icon_bg"
        :size="24"
        radius="7px"
        class="group-icon"
      />
      <h2 class="group-name">{{ group.name }}</h2>
      <span class="group-count">{{ count }}</span>

      <div v-if="editable" class="group-ops">
        <button class="btn btn-sm btn-ghost" title="在此分组添加站点" @click="emit('add', group.id)">
          + 站点
        </button>
        <button class="btn btn-sm btn-ghost" title="批量添加" @click="emit('add-multiple', group.id)">
          批量
        </button>
        <template v-if="!group.isDefault">
          <button class="btn btn-sm btn-ghost" title="编辑分组" @click="emit('edit-group', group)">
            编辑
          </button>
          <button class="btn btn-sm btn-ghost" title="删除分组" @click="emit('remove-group', group)">
            删除
          </button>
        </template>
      </div>
    </header>

    <div
      ref="gridRef"
      class="site-grid"
      :class="{ 'is-empty': !count }"
      :data-style="style"
      :data-group-id="group.id"
    >
      <SiteCard
        v-for="s in group.sites"
        :key="s.id"
        :data-site-id="s.id"
        :site="s"
        :style="style"
        :editable="editable"
        :selecting="selecting"
        :selected="isSelected(s.id)"
        :url="s.url || s.url_internal"
        @open="emit('open', $event)"
        @edit="emit('edit', $event)"
        @remove="emit('remove', $event)"
        @menu="emit('menu', $event)"
        @toggle-select="emit('toggle-select', $event)"
      />

      <div v-if="!count" class="ghost-add" @click="emit('add', group.id)">
        <span>拖动站点到这里，或点击添加</span>
      </div>
    </div>
  </section>
</template>

<style scoped>
.group {
  margin-bottom: 26px;
}

.group-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 11px;
  padding: 0 2px;
  min-height: 26px;
}

.group-icon {
  flex: none;
}

.group-name {
  font-size: 14px;
  font-weight: 600;
  letter-spacing: .2px;
}

.group-count {
  font-size: 11px;
  color: var(--c-text-weak);
  background: var(--c-surface-2);
  border: 1px solid var(--c-border);
  border-radius: var(--r-full);
  padding: 0 7px;
  line-height: 17px;
  height: 18px;
  display: inline-flex;
  align-items: center;
}

.group-ops {
  margin-left: auto;
  display: flex;
  gap: 2px;
  opacity: 0;
  transition: opacity var(--dur);
}
.group:hover .group-ops {
  opacity: 1;
}

/* ---- 网格：随图标风格变化列宽 ---- */
.site-grid {
  display: grid;
  gap: 10px;
  min-height: 52px;
  transition: background var(--dur), box-shadow var(--dur);
}

.site-grid[data-style='icon'] {
  grid-template-columns: repeat(auto-fill, minmax(104px, 1fr));
}
.site-grid[data-style='small'] {
  grid-template-columns: repeat(auto-fill, minmax(168px, 1fr));
}
.site-grid[data-style='detail'] {
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
}

/* 空分组：只留一个虚线占位当落点 */
.site-grid.is-empty {
  grid-template-columns: 1fr;
}

.ghost-add {
  border: 1px dashed var(--c-border-strong);
  border-radius: var(--r-md);
  padding: 16px;
  text-align: center;
  font-size: 12px;
  color: var(--c-text-weak);
  cursor: pointer;
  transition: border-color var(--dur), color var(--dur), background var(--dur);
}
.ghost-add:hover {
  border-color: var(--c-accent);
  color: var(--c-accent);
  background: var(--c-accent-soft);
}

/* 拖动经过时的落点提示 */
.site-grid.is-drop-over {
  background: var(--c-accent-soft);
  border-radius: var(--r-md);
  box-shadow: inset 0 0 0 1px var(--c-accent);
}

@media (max-width: 640px) {
  .site-grid[data-style='icon'] {
    grid-template-columns: repeat(auto-fill, minmax(88px, 1fr));
  }
  .site-grid[data-style='small'] {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  }
  .site-grid[data-style='detail'] {
    grid-template-columns: 1fr;
  }
  .group-ops {
    opacity: 1;
  }
}
</style>
