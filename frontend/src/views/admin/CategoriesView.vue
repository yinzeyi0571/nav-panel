<script setup>
import { ref, computed } from 'vue'
import { usePanelStore } from '@/stores/panel'
import { err, ok, confirmDialog } from '@/utils/ui'
import { resolveIconType } from '@/utils/icon'
import SiteIcon from '@/components/SiteIcon.vue'
import GroupDialog from '@/components/GroupDialog.vue'

const panel = usePanelStore()

const groupOpen = ref(false)
const editingGroup = ref(null)

const rows = computed(() =>
  panel.categories.map((c) => ({
    ...c,
    siteCount: panel.sites.filter((s) => String(s.category_id) === String(c.id)).length
  }))
)

/** 没有归宿的站点数 —— 正常情况下是 0（后端每次请求都会自愈） */
const orphanCount = computed(
  () => panel.sites.filter((s) => !panel.categories.some((c) => String(c.id) === String(s.category_id))).length
)

function openAdd() {
  editingGroup.value = null
  groupOpen.value = true
}

function openEdit(g) {
  editingGroup.value = g
  groupOpen.value = true
}

/** 删掉这个分组后，组内站点会搬到哪 —— 剩下的第一个分组 */
function nextHomeFor(g) {
  const rest = panel.categories.filter((c) => String(c.id) !== String(g.id))
  return rest.length ? rest[0].name : '我的收藏'
}

async function remove(g) {
  const n = g.siteCount
  const to = nextHomeFor(g)

  const yes = await confirmDialog(
    n
      ? `分组「${g.name}」下有 ${n} 个站点，删除后这些站点会移到「${to}」。确定删除吗？`
      : `确定删除分组「${g.name}」吗？`,
    { title: '删除分组', okText: '删除', danger: true }
  )
  if (!yes) return
  try {
    await panel.removeCategory(g.id)
    ok('分组已删除')
  } catch (e) {
    err(e.message)
  }
}

async function move(index, delta) {
  const ids = panel.categories.map((c) => String(c.id))
  const target = index + delta
  if (target < 0 || target >= ids.length) return

  const tmp = ids[index]
  ids[index] = ids[target]
  ids[target] = tmp

  try {
    await panel.saveCategorySort(ids)
  } catch (e) {
    err(e.message)
  }
}
</script>

<template>
  <div class="pane">
    <div class="pane-head">
      <div>
        <h2>分组管理</h2>
        <p class="text-dim">
          共 {{ rows.length }} 个分组。分组都能改名、挪位置、删除，站点永远待在某个分组里。
        </p>
        <p v-if="orphanCount" class="text-dim">
          有 {{ orphanCount }} 个站点暂时没有分组，下次刷新会自动归到第一个分组。
        </p>
      </div>
      <button class="btn btn-sm btn-primary" @click="openAdd">新建分组</button>
    </div>

    <div v-if="!rows.length" class="empty">
      <div class="empty-title">还没有分组</div>
      <p>建一个分组吧，站点总要有个去处。</p>
    </div>

    <ul v-else class="list">
      <li v-for="(g, i) in rows" :key="g.id" class="item">
        <SiteIcon
          :name="g.name"
          :icon="g.icon"
          :icon-type="resolveIconType(0, g.icon)"
          :bg="g.icon_bg"
          :size="34"
          radius="10px"
        />

        <div class="info">
          <div class="name">{{ g.name }}</div>
          <div class="sub truncate">
            {{ g.description || '（无描述）' }} · {{ g.siteCount }} 个站点
          </div>
        </div>

        <div class="ops">
          <button class="btn btn-sm btn-icon btn-ghost" title="上移" :disabled="i === 0" @click="move(i, -1)">↑</button>
          <button
            class="btn btn-sm btn-icon btn-ghost"
            title="下移"
            :disabled="i === rows.length - 1"
            @click="move(i, 1)"
          >
            ↓
          </button>
          <button class="btn btn-sm" @click="openEdit(g)">编辑</button>
          <button class="btn btn-sm btn-danger" @click="remove(g)">删除</button>
        </div>
      </li>
    </ul>

    <GroupDialog :open="groupOpen" :group="editingGroup" @close="groupOpen = false" />
  </div>
</template>

<style scoped>
.pane-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 18px;
}
.pane-head h2 {
  font-size: 17px;
  font-weight: 600;
  margin-bottom: 3px;
}
.pane-head p {
  font-size: 12.5px;
}

.list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 14px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  background: var(--c-surface);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

.info {
  flex: 1 1 auto;
  min-width: 0;
}

.name {
  font-size: 13.5px;
  font-weight: 500;
}

.sub {
  font-size: 11.5px;
  color: var(--c-text-weak);
  margin-top: 2px;
}

.ops {
  display: flex;
  gap: 4px;
  flex: none;
}

@media (max-width: 640px) {
  .item {
    flex-wrap: wrap;
  }
  .ops {
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
