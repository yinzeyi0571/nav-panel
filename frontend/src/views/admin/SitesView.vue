<script setup>
import { ref, computed } from 'vue'
import { usePanelStore } from '@/stores/panel'
import { err, ok, confirmDialog } from '@/utils/ui'
import SiteIcon from '@/components/SiteIcon.vue'
import SiteDialog from '@/components/SiteDialog.vue'
import BulkAddDialog from '@/components/BulkAddDialog.vue'

const panel = usePanelStore()

const keyword = ref('')
const filterCat = ref('all')

const siteOpen = ref(false)
const editingSite = ref(null)
const presetCategory = ref('0')
const bulkOpen = ref(false)

const selected = ref([])

const OPEN_LABEL = { 1: '当前页', 2: '新窗口', 3: '弹窗' }

function catName(id) {
  const c = panel.categories.find((x) => String(x.id) === String(id))
  return c ? c.name : '—'
}

/** 新建站点时默认落在第一个分组 */
const firstCategoryId = computed(() =>
  panel.categories.length ? String(panel.categories[0].id) : ''
)

const rows = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  return panel.sites
    .filter((s) => {
      if (filterCat.value !== 'all' && String(s.category_id || '') !== filterCat.value) {
        return false
      }
      if (!kw) return true
      const hay = `${s.name} ${s.description || ''} ${s.url || ''} ${s.url_internal || ''}`.toLowerCase()
      return hay.includes(kw)
    })
    .sort((a, b) => Number(a.sort || 0) - Number(b.sort || 0))
})

function openAdd() {
  editingSite.value = null
  presetCategory.value = firstCategoryId.value
  siteOpen.value = true
}

function openEdit(site) {
  editingSite.value = site
  presetCategory.value = String(site.category_id || firstCategoryId.value)
  siteOpen.value = true
}

async function remove(site) {
  const yes = await confirmDialog(`确定删除站点「${site.name}」吗？`, {
    title: '删除站点',
    okText: '删除',
    danger: true
  })
  if (!yes) return
  try {
    await panel.removeSite(site.id)
    ok('已删除')
  } catch (e) {
    err(e.message)
  }
}

function toggle(id) {
  const k = String(id)
  const i = selected.value.findIndex((x) => String(x) === k)
  if (i >= 0) selected.value.splice(i, 1)
  else selected.value.push(k)
}

function isSel(id) {
  return selected.value.some((x) => String(x) === String(id))
}

const allChecked = computed(
  () => rows.value.length > 0 && rows.value.every((r) => isSel(r.id))
)

function toggleAll() {
  if (allChecked.value) {
    selected.value = []
  } else {
    selected.value = rows.value.map((r) => String(r.id))
  }
}

async function removeSelected() {
  const n = selected.value.length
  if (!n) return
  const yes = await confirmDialog(`确定删除选中的 ${n} 个站点吗？`, {
    title: '批量删除',
    okText: '删除',
    danger: true
  })
  if (!yes) return
  try {
    await panel.removeSites(selected.value.slice())
    ok(`已删除 ${n} 个`)
    selected.value = []
  } catch (e) {
    err(e.message)
  }
}
</script>

<template>
  <div class="pane">
    <div class="pane-head">
      <div>
        <h2>站点管理</h2>
        <p class="text-dim">共 {{ panel.sites.length }} 个站点，当前筛选出 {{ rows.length }} 个。</p>
      </div>
      <div class="row">
        <button class="btn btn-sm" @click="bulkOpen = true">批量添加</button>
        <button class="btn btn-sm btn-primary" @click="openAdd">+ 添加站点</button>
      </div>
    </div>

    <div class="toolbar">
      <input v-model="keyword" class="input" type="text" placeholder="搜索名称或地址" />
      <select v-model="filterCat" class="select">
        <option value="all">全部分组</option>
        <option v-for="c in panel.categories" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
      </select>
      <button v-if="selected.length" class="btn btn-sm btn-danger" @click="removeSelected">
        删除选中（{{ selected.length }}）
      </button>
    </div>

    <div v-if="!rows.length" class="empty">
      <div class="empty-title">没有匹配的站点</div>
      <p>换个关键词，或先添加几个站点。</p>
    </div>

    <div v-else class="table">
      <div class="thead">
        <label class="ck">
          <input type="checkbox" :checked="allChecked" @change="toggleAll" />
        </label>
        <span class="c-name">名称</span>
        <span class="c-cat">分组</span>
        <span class="c-url">地址</span>
        <span class="c-open">打开</span>
        <span class="c-hits">点击</span>
        <span class="c-ops">操作</span>
      </div>

      <div v-for="s in rows" :key="s.id" class="trow" :class="{ 'is-sel': isSel(s.id) }">
        <label class="ck">
          <input type="checkbox" :checked="isSel(s.id)" @change="toggle(s.id)" />
        </label>

        <span class="c-name">
          <SiteIcon
            :name="s.name"
            :icon="s.icon"
            :icon-type="s.icon_type"
            :bg="s.icon_bg"
            :size="28"
            radius="8px"
          />
          <span class="nm">
            <span class="nm-t truncate">{{ s.name }}</span>
            <span v-if="s.description" class="nm-d truncate">{{ s.description }}</span>
          </span>
        </span>

        <span class="c-cat"><span class="chip">{{ catName(s.category_id) }}</span></span>

        <span class="c-url">
          <span class="u truncate">{{ s.url || s.url_internal || '—' }}</span>
          <span v-if="s.url && s.url_internal" class="u-in truncate">内网：{{ s.url_internal }}</span>
        </span>

        <span class="c-open">{{ OPEN_LABEL[Number(s.open_method)] || '新窗口' }}</span>

        <span class="c-hits mono">{{ s.hits || 0 }}</span>

        <span class="c-ops">
          <button class="btn btn-sm btn-ghost" @click="openEdit(s)">编辑</button>
          <button class="btn btn-sm btn-ghost danger" @click="remove(s)">删除</button>
        </span>
      </div>
    </div>

    <SiteDialog
      :open="siteOpen"
      :site="editingSite"
      :categories="panel.categories"
      :default-category="presetCategory"
      @close="siteOpen = false"
    />
    <BulkAddDialog
      :open="bulkOpen"
      :categories="panel.categories"
      default-category="0"
      @close="bulkOpen = false"
    />
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

.toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}
.toolbar .input {
  flex: 1 1 200px;
}
.toolbar .select {
  flex: 0 0 auto;
  width: 140px;
}

.table {
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  overflow: hidden;
  background: var(--c-surface);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

.thead,
.trow {
  display: grid;
  grid-template-columns: 34px minmax(160px, 1.5fr) 100px minmax(160px, 2fr) 68px 52px 120px;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
}

.thead {
  font-size: 11.5px;
  color: var(--c-text-weak);
  border-bottom: 1px solid var(--c-border);
  background: var(--c-surface-2);
}

.trow {
  border-bottom: 1px solid var(--c-border);
  font-size: 12.5px;
  transition: background var(--dur);
}
.trow:last-child {
  border-bottom: none;
}
.trow:hover {
  background: var(--c-surface-2);
}
.trow.is-sel {
  background: var(--c-accent-soft);
}

.ck {
  display: flex;
  align-items: center;
  justify-content: center;
}
.ck input {
  cursor: pointer;
  accent-color: var(--c-accent);
}

.c-name {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
}
.nm {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.nm-t {
  font-weight: 500;
}
.nm-d {
  font-size: 11px;
  color: var(--c-text-weak);
}

.c-url {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.u {
  color: var(--c-text-dim);
}
.u-in {
  font-size: 11px;
  color: var(--c-text-weak);
}

.c-hits {
  color: var(--c-text-dim);
}

.c-ops {
  display: flex;
  gap: 2px;
  justify-content: flex-end;
}
.c-ops .danger {
  color: var(--c-danger);
}

@media (max-width: 900px) {
  .thead {
    display: none;
  }
  .trow {
    grid-template-columns: 34px 1fr;
    gap: 6px 10px;
    padding: 12px;
  }
  .c-name { grid-column: 2; }
  .c-cat, .c-url, .c-open, .c-hits { grid-column: 2; }
  .c-ops { grid-column: 2; justify-content: flex-start; }
}
</style>
