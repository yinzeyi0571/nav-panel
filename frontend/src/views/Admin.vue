<script setup>
import { computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { usePanelStore } from '@/stores/panel'

const route = useRoute()
const user = useUserStore()
const panel = usePanelStore()

const MENU = [
  { name: 'admin-sites', path: '/admin/sites', label: '站点管理', admin: false },
  { name: 'admin-categories', path: '/admin/categories', label: '分组管理', admin: false },
  { name: 'admin-appearance', path: '/admin/appearance', label: '外观设置', admin: false },
  { name: 'admin-account', path: '/admin/account', label: '我的账号', admin: false },
  { name: 'admin-users', path: '/admin/users', label: '用户管理', admin: true },
  { name: 'admin-backup', path: '/admin/backup', label: '导入导出', admin: false },
  { name: 'admin-system', path: '/admin/system', label: '系统状态', admin: false },
  { name: 'admin-releases', path: '/admin/releases', label: '版本管理', admin: true }
]

const menu = computed(() => MENU.filter((m) => !m.admin || user.isAdmin))

const currentTitle = computed(() => {
  const hit = MENU.find((m) => m.name === route.name)
  return hit ? hit.label : '管理后台'
})

onMounted(async () => {
  // 后台某些页面（外观、版本）需要设置数据
  if (!panel.loaded) {
    try {
      await panel.load()
    } catch (e) {
      /* 交给各页面自己提示 */
    }
  }
})
</script>

<template>
  <div class="nav-page admin">
    <header class="top">
      <div class="nav-wrap top-inner">
        <a class="back" href="#/">
          <span class="arrow">←</span>
          <span>返回前台</span>
        </a>
        <h1 class="title">{{ currentTitle }}</h1>
        <div class="who">
          <span class="chip">{{ user.isAdmin ? '管理员' : '普通用户' }}</span>
          <span class="name truncate">{{ user.displayName }}</span>
        </div>
      </div>
    </header>

    <div class="nav-wrap admin-body">
      <aside class="side">
        <a
          v-for="m in menu"
          :key="m.name"
          class="side-item"
          :class="{ 'is-active': route.name === m.name }"
          :href="'#' + m.path"
        >
          {{ m.label }}
        </a>
      </aside>

      <main class="content">
        <router-view v-slot="{ Component }">
          <transition name="fade" mode="out-in">
            <component :is="Component" />
          </transition>
        </router-view>
      </main>
    </div>
  </div>
</template>

<style scoped>
.top {
  position: sticky;
  top: 0;
  z-index: 500;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  background: color-mix(in srgb, var(--c-bg) 74%, transparent);
  border-bottom: 1px solid var(--c-border);
}

.top-inner {
  display: flex;
  align-items: center;
  gap: 12px;
  height: 54px;
}

.back {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--c-text-dim);
  flex: none;
  transition: color var(--dur);
}
.back:hover {
  color: var(--c-accent);
}
.arrow {
  font-size: 15px;
}

.title {
  font-size: 15px;
  font-weight: 600;
  margin-left: 4px;
}

.who {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}
.who .name {
  font-size: 12.5px;
  color: var(--c-text-dim);
  max-width: 130px;
}

.admin-body {
  display: flex;
  gap: 22px;
  padding-top: 20px;
  padding-bottom: 48px;
  align-items: flex-start;
  flex: 1 1 auto;
  width: 100%;
}

.side {
  width: 168px;
  flex: none;
  position: sticky;
  top: 74px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.side-item {
  padding: 8px 12px;
  border-radius: var(--r-sm);
  font-size: 13px;
  color: var(--c-text-dim);
  transition: background var(--dur), color var(--dur);
}
.side-item:hover {
  background: var(--c-surface);
  color: var(--c-text);
}
.side-item.is-active {
  background: var(--c-accent-soft);
  color: var(--c-accent);
  font-weight: 500;
}

.content {
  flex: 1 1 auto;
  min-width: 0;
}

@media (max-width: 820px) {
  .admin-body {
    flex-direction: column;
    gap: 14px;
  }
  .side {
    width: 100%;
    position: static;
    flex-direction: row;
    overflow-x: auto;
    gap: 6px;
    padding-bottom: 4px;
  }
  .side-item {
    white-space: nowrap;
    flex: none;
  }
  .who .name {
    display: none;
  }
}
</style>
