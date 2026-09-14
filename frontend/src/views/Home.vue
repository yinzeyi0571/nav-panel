<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue'
import Sortable from 'sortablejs'

import { usePanelStore } from '@/stores/panel'
import { useUserStore } from '@/stores/user'
import { err, ok, confirmDialog } from '@/utils/ui'

import SiteGroup from '@/components/SiteGroup.vue'
import SearchBox from '@/components/SearchBox.vue'
import ClockDisplay from '@/components/ClockDisplay.vue'
import MonitorBar from '@/components/MonitorBar.vue'
import SiteDialog from '@/components/SiteDialog.vue'
import GroupDialog from '@/components/GroupDialog.vue'
import BulkAddDialog from '@/components/BulkAddDialog.vue'
import ContextMenu from '@/components/ContextMenu.vue'

const panel = usePanelStore()
const user = useUserStore()

/* ==================== 弹窗状态 ==================== */

const siteOpen = ref(false)
const editingSite = ref(null)
const presetCategory = ref('')

const groupOpen = ref(false)
const editingGroup = ref(null)

const bulkOpen = ref(false)
const bulkCategory = ref('')

/* ==================== 右键菜单 ==================== */

const ctxOpen = ref(false)
const ctxX = ref(0)
const ctxY = ref(0)
const ctxSite = ref(null)

/* ==================== 批量选择 ==================== */

const selecting = ref(false)
const selectedIds = ref([])

/* ==================== 计算属性 ==================== */

const s = computed(() => panel.settings)

const logoVisible = computed(() => Number(s.value.logo_visible) === 1)
const logoText = computed(() => s.value.logo_text || '导航站')
const logoImage = computed(() => s.value.logo_image || '')
const clockVisible = computed(() => Number(s.value.clock_visible) === 1)
const clockSecond = computed(() => Number(s.value.clock_second) === 1)
const searchShow = computed(() => Number(s.value.search_box_show) === 1)
const netToggleShow = computed(() => Number(s.value.net_toggle_show) === 1)
const monitorShow = computed(() => Number(s.value.monitor_show) === 1)
const iconStyle = computed(() => s.value.icon_style || 'icon')

const hasFooter = computed(() => !!(s.value.footer && String(s.value.footer).trim()))
const footerIsHtml = computed(() => Number(s.value.footer_html) === 1)

/** 展示用的分组：编辑态连空分组一起显示（方便往里拖），非编辑态只显示有站点的 */
const displayGroups = computed(() => (user.editable ? panel.allGroups : panel.groups))

/** 新建内容时的默认分组 —— 就是排在第一个的那个 */
const firstCategoryId = computed(() =>
  panel.categories.length ? String(panel.categories[0].id) : ''
)

const empty = computed(() => !panel.loading && !panel.sites.length)

/* ==================== 拖拽：分组内 + 跨分组 ==================== */

/**
 * 处理站点拖拽结束。
 *
 * 关键点：SortableJS 直接改了真实 DOM，而 Vue 并不知道。
 * 如果就这么让它过去，Vue 下次 patch 时会按「旧顺序」去理解 DOM，导致元素错位。
 * 所以这里先把 DOM 还原成拖拽前的样子，再把新顺序写进数据，
 * 让 Vue 自己按数据把节点挪到正确位置 —— 两边就永远是一致的。
 */
function onSiteResort(evt) {
  const item = evt.item
  const siteId = item && item.dataset ? item.dataset.siteId : null
  if (!siteId) return

  const fromEl = evt.from
  const oldIndex = evt.oldIndex

  // 1) 记录拖拽后的真实顺序
  const order = {}
  document.querySelectorAll('.site-grid').forEach((el) => {
    const gid = String(el.dataset.groupId)
    order[gid] = Array.from(el.children)
      .map((c) => c.dataset.siteId)
      .filter(Boolean)
  })

  const toGid = String(evt.to.dataset.groupId)
  if (order[toGid] && !order[toGid].includes(String(siteId))) {
    order[toGid].push(String(siteId))
  }

  // 2) 还原 DOM，交给 Vue 重排
  if (item.parentNode) item.parentNode.removeChild(item)
  const refNode = fromEl.children[oldIndex] || null
  fromEl.insertBefore(item, refNode)

  // 3) 同步到 store
  let movedAcross = false
  for (const gid of Object.keys(order)) {
    order[gid].forEach((id, i) => {
      const site = panel.sites.find((x) => String(x.id) === String(id))
      if (!site) return
      if (String(site.category_id) !== gid) {
        site.category_id = gid
        movedAcross = true
      }
      site.sort = i
    })
  }

  // 4) 提交
  panel
    .saveSort(order)
    .then(() => {
      if (movedAcross) ok('已移动到新分组')
    })
    .catch((e) => {
      err('排序保存失败：' + e.message)
      panel.load(true)
    })
}

/* ==================== 拖拽：分组本身排序 ==================== */

const groupsRef = ref(null)
let groupSortable = null

function initGroupSortable() {
  if (!user.editable || !groupsRef.value || groupSortable) return

  groupSortable = Sortable.create(groupsRef.value, {
    animation: 160,
    handle: '.group-head',
    draggable: '.group',
    filter: '.group-ops, .is-default',
    preventOnFilter: false,
    ghostClass: 'sortable-ghost',

    onEnd(evt) {
      const wrap = groupsRef.value
      const item = evt.item
      const oldIndex = evt.oldIndex

      const ids = Array.from(wrap.children)
        .map((c) => c.dataset.groupId)
        .filter((id) => id && id !== '0')

      // 还原 DOM，让 Vue 按新数据重排
      if (item.parentNode) item.parentNode.removeChild(item)
      wrap.insertBefore(item, wrap.children[oldIndex] || null)

      panel.saveCategorySort(ids).catch((e) => {
        err('分组排序保存失败：' + e.message)
        panel.load(true)
      })
    }
  })
}

function destroyGroupSortable() {
  if (groupSortable) {
    groupSortable.destroy()
    groupSortable = null
  }
}

/* ==================== 站点操作 ==================== */

function openAdd(categoryId) {
  editingSite.value = null
  const want = categoryId == null || categoryId === '' ? '' : String(categoryId)
  const exists = want && panel.categories.some((c) => String(c.id) === want)
  presetCategory.value = exists ? want : firstCategoryId.value
  siteOpen.value = true
}

function openEdit(site) {
  editingSite.value = site
  presetCategory.value = String(site.category_id || firstCategoryId.value)
  siteOpen.value = true
}

async function removeSite(site) {
  const yes = await confirmDialog(`确定删除站点「${site.name}」吗？`, {
    title: '删除站点',
    okText: '删除',
    danger: true
  })
  if (!yes) return
  try {
    await panel.removeSite(site.id)
    ok('站点已删除')
    selectedIds.value = selectedIds.value.filter((x) => String(x) !== String(site.id))
  } catch (e) {
    err(e.message)
  }
}

function onMenu({ event, site }) {
  ctxSite.value = site
  ctxX.value = event.clientX
  ctxY.value = event.clientY
  ctxOpen.value = true
}

const ctxItems = computed(() => {
  const site = ctxSite.value
  if (!site) return []

  const items = [
    { id: 'open', label: '打开（' + (panel.net === 'internal' ? '内网' : '外网') + '）' }
  ]

  if (site.url && site.url_internal) {
    items.push({
      id: 'open-other',
      label: '打开（' + (panel.net === 'internal' ? '外网' : '内网') + '）'
    })
  }
  items.push({
    id: 'newtab',
    label: '在新标签页打开',
    disabled: !(site.url || site.url_internal)
  })
  items.push({ divider: true })
  items.push({ id: 'copy', label: '复制链接', disabled: !(site.url || site.url_internal) })
  items.push({ id: 'hit', label: '点击次数：' + (site.hits || 0), disabled: true })

  if (user.editable) {
    items.push({ divider: true })
    items.push({ id: 'edit', label: '编辑' })
    items.push({ id: 'remove', label: '删除', danger: true })
  }
  return items
})

function onCtxPick(item) {
  const site = ctxSite.value
  if (!site) return

  if (item.id === 'open') {
    panel.openSite(site)
  } else if (item.id === 'open-other') {
    const url = panel.net === 'internal' ? site.url : site.url_internal
    if (url) {
      panel.hitSite(site.id)
      window.open(url, '_blank', 'noopener')
    }
  } else if (item.id === 'newtab') {
    const url = site.url || site.url_internal
    if (url) {
      panel.hitSite(site.id)
      window.open(url, '_blank', 'noopener')
    }
  } else if (item.id === 'copy') {
    copyText(panel.siteUrl(site) || site.url || site.url_internal)
  } else if (item.id === 'edit') {
    openEdit(site)
  } else if (item.id === 'remove') {
    removeSite(site)
  }
}

function copyText(text) {
  if (!text) return
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(
      () => ok('已复制'),
      () => fallbackCopy(text)
    )
  } else {
    fallbackCopy(text)
  }
}

function fallbackCopy(text) {
  const ta = document.createElement('textarea')
  ta.value = text
  ta.style.position = 'fixed'
  ta.style.opacity = '0'
  document.body.appendChild(ta)
  ta.select()
  try {
    document.execCommand('copy')
    ok('已复制')
  } catch (e) {
    err('复制失败')
  }
  document.body.removeChild(ta)
}

/* ==================== 批量选择 ==================== */

function toggleSelecting() {
  selecting.value = !selecting.value
  if (!selecting.value) selectedIds.value = []
}

function toggleSelect(id) {
  const key = String(id)
  const i = selectedIds.value.findIndex((x) => String(x) === key)
  if (i >= 0) selectedIds.value.splice(i, 1)
  else selectedIds.value.push(key)
}

const allIds = computed(() => panel.sites.map((x) => String(x.id)))

function selectAll() {
  selectedIds.value = selectedIds.value.length === allIds.value.length ? [] : allIds.value.slice()
}

async function removeSelected() {
  const n = selectedIds.value.length
  if (!n) return

  const yes = await confirmDialog(`确定删除选中的 ${n} 个站点吗？`, {
    title: '批量删除',
    okText: '删除',
    danger: true
  })
  if (!yes) return

  try {
    await panel.removeSites(selectedIds.value.slice())
    ok(`已删除 ${n} 个站点`)
    selectedIds.value = []
    selecting.value = false
  } catch (e) {
    err(e.message)
  }
}

/* ==================== 分组操作 ==================== */

function openAddGroup() {
  editingGroup.value = null
  groupOpen.value = true
}

function openEditGroup(group) {
  editingGroup.value = group
  groupOpen.value = true
}

async function removeGroup(group) {
  const n = group.sites.length
  // 站点删不掉，只会搬到剩下的第一个分组
  const rest = panel.categories.filter((c) => String(c.id) !== String(group.id))
  const to = rest.length ? rest[0].name : '我的收藏'

  const yes = await confirmDialog(
    n
      ? `分组「${group.name}」里有 ${n} 个站点，删除后这些站点会移到「${to}」。确定删除分组吗？`
      : `确定删除分组「${group.name}」吗？`,
    { title: '删除分组', okText: '删除', danger: true }
  )
  if (!yes) return

  try {
    await panel.removeCategory(group.id)
    ok('分组已删除')
  } catch (e) {
    err(e.message)
  }
}

/* ==================== 外观快捷操作 ==================== */

const ICON_STYLES = [
  { k: 'icon', name: '大图标' },
  { k: 'small', name: '小图标' },
  { k: 'detail', name: '详情' }
]

async function setIconStyle(k) {
  if (iconStyle.value === k) return
  try {
    await panel.saveSettings({ icon_style: k })
  } catch (e) {
    err(e.message)
  }
}

/* ==================== 编辑工具菜单（收进一个小图标） ==================== */

const toolsOpen = ref(false)
const toolsRef = ref(null)

function runTool(k) {
  toolsOpen.value = false

  if (k === 'add') openAdd(firstCategoryId.value)
  else if (k === 'bulk') {
    bulkCategory.value = firstCategoryId.value
    bulkOpen.value = true
  } else if (k === 'group') openAddGroup()
  else if (k === 'select') toggleSelecting()
  else setIconStyle(k)
}

function onDocPointerDown(e) {
  if (!toolsOpen.value) return
  if (toolsRef.value && !toolsRef.value.contains(e.target)) toolsOpen.value = false
}

function onEsc(e) {
  if (e.key === 'Escape') toolsOpen.value = false
}

/* ==================== 主题 ==================== */

const nextThemeMode = computed(() => {
  const m = s.value.theme_mode || 'auto'
  if (m === 'auto') return 'dark'
  if (m === 'dark') return 'light'
  return 'auto'
})

const themeModeLabel = computed(() => {
  const m = s.value.theme_mode || 'auto'
  if (m === 'dark') return '深色'
  if (m === 'light') return '浅色'
  return '跟随'
})

async function cycleTheme() {
  try {
    await panel.saveSettings({ theme_mode: nextThemeMode.value })
  } catch (e) {
    err(e.message)
  }
}

async function doLogout() {
  const yes = await confirmDialog('确定退出登录吗？', { title: '退出登录', okText: '退出', danger: true })
  if (!yes) return
  await user.logout()
  panel.load(true)
  ok('已退出登录')
}

/* ==================== 生命周期 ==================== */

watch(
  () => user.editable,
  (v) => {
    if (v) nextTick(initGroupSortable)
    else destroyGroupSortable()
  }
)

onMounted(async () => {
  try {
    await panel.load()
  } catch (e) {
    err('加载失败：' + e.message)
  }
  await nextTick()
  initGroupSortable()

  document.addEventListener('mousedown', onDocPointerDown)
  document.addEventListener('keydown', onEsc)
})

onUnmounted(() => {
  destroyGroupSortable()
  document.removeEventListener('mousedown', onDocPointerDown)
  document.removeEventListener('keydown', onEsc)
})
</script>

<template>
  <div class="nav-page">
    <!-- ==================== 顶栏 ==================== -->
    <header class="top">
      <div class="nav-wrap top-inner">
        <a class="brand" href="#/" @click.prevent="panel.load(true)">
          <img v-if="logoImage" :src="logoImage" class="brand-img" alt="" />
          <span v-if="logoVisible" class="brand-text">{{ logoText }}</span>
        </a>

        <div class="top-tools">
          <SearchBox v-if="searchShow" class="top-search" />

          <button
            v-if="netToggleShow"
            class="btn btn-sm"
            :title="'当前：' + (panel.net === 'internal' ? '内网地址' : '外网地址') + '，点击切换'"
            @click="panel.toggleNet()"
          >
            {{ panel.net === 'internal' ? '内网' : '外网' }}
          </button>

          <button class="btn btn-sm" :title="'主题：' + themeModeLabel" @click="cycleTheme">
            {{ themeModeLabel }}
          </button>

          <!-- 编辑工具：全部收进这一个小图标里 -->
          <div v-if="user.editable" ref="toolsRef" class="tools-wrap">
            <button
              class="btn btn-sm icon-btn"
              :class="{ 'is-open': toolsOpen }"
              title="编辑工具"
              @click="toolsOpen = !toolsOpen"
            >
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor"
                   stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3.2" />
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
              </svg>
            </button>

            <div v-if="toolsOpen" class="tools-menu">
              <button class="tools-item" @click="runTool('add')">
                <span class="tools-ico">＋</span>添加站点
              </button>
              <button class="tools-item" @click="runTool('bulk')">
                <span class="tools-ico">≡</span>批量添加
              </button>
              <button class="tools-item" @click="runTool('group')">
                <span class="tools-ico">▤</span>新建分组
              </button>

              <div class="tools-sep" />

              <button class="tools-item" :class="{ 'is-active': selecting }" @click="runTool('select')">
                <span class="tools-ico">{{ selecting ? '✕' : '☑' }}</span>
                {{ selecting ? '退出选择' : '批量选择' }}
              </button>

              <div class="tools-sep" />

              <div class="tools-label">图标样式</div>
              <div class="tools-tabs">
                <button
                  v-for="it in ICON_STYLES"
                  :key="it.k"
                  :class="{ 'is-active': iconStyle === it.k }"
                  @click="runTool(it.k)"
                >
                  {{ it.name }}
                </button>
              </div>
            </div>
          </div>

          <template v-if="user.logged">
            <a class="btn btn-sm" href="#/admin">管理</a>
            <button class="btn btn-sm btn-ghost user-btn" :title="user.displayName" @click="doLogout">
              {{ user.displayName }}
            </button>
          </template>
          <a v-else class="btn btn-sm btn-primary" href="#/login">登录</a>
        </div>
      </div>
    </header>

    <!-- ==================== 主体 ==================== -->
    <main class="nav-wrap nav-main">
      <!-- 批量选择操作条 -->
      <div v-if="selecting" class="sel-bar">
        <span>已选 <b>{{ selectedIds.length }}</b> / {{ panel.totalSites }}</span>
        <button class="btn btn-sm" @click="selectAll">
          {{ selectedIds.length === allIds.length ? '取消全选' : '全选' }}
        </button>
        <button class="btn btn-sm btn-danger" :disabled="!selectedIds.length" @click="removeSelected">
          删除选中
        </button>
      </div>

      <!-- 时钟 -->
      <ClockDisplay v-if="clockVisible" :show-second="clockSecond" />

      <!-- 加载中 -->
      <div v-if="panel.loading && !panel.loaded" class="loading-row">
        <span class="spinner" />
        <span>正在加载…</span>
      </div>

      <!-- 空状态 -->
      <div v-else-if="empty" class="empty">
        <div class="empty-title">还没有任何站点</div>
        <p v-if="user.editable">点上面的「+ 添加站点」开始吧。</p>
        <p v-else>请先登录后再添加站点。</p>
      </div>

      <!-- 分组列表（可拖动排序） -->
      <div v-else ref="groupsRef" class="groups">
        <SiteGroup
          v-for="g in displayGroups"
          :key="g.id"
          :data-group-id="g.id"
          :group="g"
          :style="iconStyle"
          :editable="user.editable"
          :selecting="selecting"
          :selected-ids="selectedIds"
          @open="panel.openSite($event)"
          @edit="openEdit"
          @remove="removeSite"
          @menu="onMenu"
          @toggle-select="toggleSelect"
          @add="openAdd"
          @add-multiple="(id) => { bulkCategory = id; bulkOpen = true }"
          @edit-group="openEditGroup"
          @remove-group="removeGroup"
          @resort="onSiteResort"
        />
      </div>

      <!-- 系统监控 -->
      <MonitorBar v-if="monitorShow" :show-title="Number(panel.settings.monitor_title) === 1" />
    </main>

    <!-- ==================== 页脚 ==================== -->
    <footer class="foot">
      <div class="nav-wrap foot-inner">
        <div v-if="hasFooter" class="foot-text">
          <span v-if="footerIsHtml" v-html="s.footer" />
          <span v-else>{{ s.footer }}</span>
        </div>

        <a
          class="foot-ver"
          href="#/changelog"
          :title="panel.releasedAt ? '更新于 ' + panel.releasedAt : '查看更新记录'"
        >
          v{{ panel.version }}
        </a>
      </div>
    </footer>

    <!-- ==================== 弹窗 ==================== -->
    <SiteDialog
      :open="siteOpen"
      :site="editingSite"
      :categories="panel.categories"
      :default-category="presetCategory"
      @close="siteOpen = false"
    />

    <GroupDialog
      :open="groupOpen"
      :group="editingGroup"
      @close="groupOpen = false"
    />

    <BulkAddDialog
      :open="bulkOpen"
      :categories="panel.categories"
      :default-category="bulkCategory"
      @close="bulkOpen = false"
    />

    <ContextMenu
      :open="ctxOpen"
      :x="ctxX"
      :y="ctxY"
      :items="ctxItems"
      @close="ctxOpen = false"
      @pick="onCtxPick"
    />
  </div>
</template>

<style scoped>
/* ---- 顶栏 ---- */
.top {
  position: sticky;
  top: 0;
  z-index: 500;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  background: color-mix(in srgb, var(--c-bg) 72%, transparent);
  border-bottom: 1px solid var(--c-border);
}

.top-inner {
  display: flex;
  align-items: center;
  gap: 14px;
  height: 54px;
}

.brand {
  display: flex;
  align-items: center;
  gap: 9px;
  flex: none;
  min-width: 0;
}

.brand-img {
  height: 26px;
  width: auto;
  max-width: 120px;
  object-fit: contain;
}

.brand-text {
  font-size: 15px;
  font-weight: 600;
  letter-spacing: .3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.top-tools {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.top-search {
  flex: 1 1 auto;
  min-width: 0;
}

.user-btn {
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ---- 批量选择操作条 ---- */
.sel-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 10px 12px;
  margin: 12px 0 16px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  background: var(--c-surface);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  font-size: 12.5px;
}

.sel-bar b {
  color: var(--c-accent);
}

/* ---- 顶栏编辑工具：全部收进一个小图标 ---- */
.tools-wrap {
  position: relative;
}

.icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 5px 8px;
}

.icon-btn.is-open {
  color: var(--c-accent);
  border-color: var(--c-accent);
  background: var(--c-accent-soft);
}

.tools-menu {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  z-index: 60;
  min-width: 188px;
  padding: 6px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  background: var(--c-surface-solid);
  box-shadow: var(--c-shadow);
  animation: tools-in .12s ease-out;
}

@keyframes tools-in {
  from {
    opacity: 0;
    transform: translateY(-4px);
  }
  to {
    opacity: 1;
    transform: none;
  }
}

.tools-item {
  display: flex;
  align-items: center;
  gap: 9px;
  width: 100%;
  padding: 8px 10px;
  border: 0;
  border-radius: var(--r-sm);
  background: transparent;
  color: var(--c-text);
  font-size: 13px;
  font-family: inherit;
  text-align: left;
  cursor: pointer;
  transition: background var(--dur), color var(--dur);
}

.tools-item:hover {
  background: var(--c-surface-2);
  color: var(--c-accent);
}

.tools-item.is-active {
  color: var(--c-accent);
}

.tools-ico {
  width: 16px;
  text-align: center;
  font-size: 12.5px;
  opacity: .75;
}

.tools-sep {
  height: 1px;
  margin: 5px 8px;
  background: var(--c-border);
}

.tools-label {
  padding: 6px 10px 4px;
  font-size: 11.5px;
  color: var(--c-text-weak);
}

.tools-tabs {
  display: flex;
  gap: 4px;
  padding: 0 6px 4px;
}

.tools-tabs button {
  flex: 1;
  padding: 6px 2px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-sm);
  background: transparent;
  color: var(--c-text-weak);
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
  transition: color var(--dur), border-color var(--dur), background var(--dur);
}

.tools-tabs button:hover {
  color: var(--c-text);
}

.tools-tabs button.is-active {
  color: var(--c-accent);
  border-color: var(--c-accent);
  background: var(--c-accent-soft);
}

/* ---- 分组列表 ---- */
.groups {
  padding-top: 4px;
}

.groups :deep(.group.sortable-ghost) {
  opacity: .35;
}

/* ---- 页脚 ---- */
.foot {
  margin-top: auto;
  padding: 20px 0 22px;
  flex: none;
}

.foot-inner {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  flex-wrap: wrap;
  text-align: center;
}

.foot-text {
  font-size: 12px;
  color: var(--c-text-weak);
  max-width: 100%;
}

.foot-ver {
  font-size: 11.5px;
  color: var(--c-text-weak);
  padding: 2px 9px;
  border-radius: var(--r-full);
  border: 1px solid var(--c-border);
  transition: color var(--dur), border-color var(--dur);
}
.foot-ver:hover {
  color: var(--c-accent);
  border-color: var(--c-accent);
}

/* ---- 响应式 ---- */
@media (max-width: 860px) {
  .top-inner {
    height: auto;
    padding-top: 10px;
    padding-bottom: 10px;
    flex-wrap: wrap;
  }
  .top-tools {
    width: 100%;
    margin-left: 0;
  }
  .top-search {
    order: 3;
    width: 100%;
    max-width: none;
    margin-top: 8px;
  }
  .brand {
    margin-right: auto;
  }
}
</style>
