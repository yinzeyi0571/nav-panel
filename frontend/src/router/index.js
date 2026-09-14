import { createRouter, createWebHashHistory } from 'vue-router'
import { useUserStore } from '@/stores/user'

/**
 * Hash 模式（URL 形如 /#/admin）。
 * 选它的原因：不需要在宝塔的 nginx 里加 try_files 重写规则，
 * 构建产物直接丢进站点根就能跑，刷新任何子路由都不会 404。
 */
const routes = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/Home.vue'),
    meta: { title: '首页' }
  },
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/Login.vue'),
    meta: { title: '登录' }
  },
  {
    path: '/changelog',
    name: 'changelog',
    component: () => import('@/views/Changelog.vue'),
    meta: { title: '更新记录' }
  },
  {
    path: '/admin',
    component: () => import('@/views/Admin.vue'),
    meta: { auth: true },
    children: [
      { path: '', redirect: { name: 'admin-sites' } },
      {
        path: 'sites',
        name: 'admin-sites',
        component: () => import('@/views/admin/SitesView.vue'),
        meta: { title: '站点管理', auth: true }
      },
      {
        path: 'categories',
        name: 'admin-categories',
        component: () => import('@/views/admin/CategoriesView.vue'),
        meta: { title: '分组管理', auth: true }
      },
      {
        path: 'appearance',
        name: 'admin-appearance',
        component: () => import('@/views/admin/AppearanceView.vue'),
        meta: { title: '外观设置', auth: true }
      },
      {
        path: 'account',
        name: 'admin-account',
        component: () => import('@/views/admin/AccountView.vue'),
        meta: { title: '我的账号', auth: true }
      },
      {
        path: 'users',
        name: 'admin-users',
        component: () => import('@/views/admin/UsersView.vue'),
        meta: { title: '用户管理', auth: true, admin: true }
      },
      {
        path: 'backup',
        name: 'admin-backup',
        component: () => import('@/views/admin/BackupView.vue'),
        meta: { title: '导入导出', auth: true }
      },
      {
        path: 'system',
        name: 'admin-system',
        component: () => import('@/views/admin/SystemView.vue'),
        meta: { title: '系统状态', auth: true }
      },
      {
        path: 'releases',
        name: 'admin-releases',
        component: () => import('@/views/admin/ReleasesView.vue'),
        meta: { title: '版本管理', auth: true, admin: true }
      }
    ]
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFound.vue')
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 })
})

/**
 * 权限守卫。
 * 这里只做「路由级」拦截；真正的权限判断在后端每个接口里各做一次，
 * 前端拦截是为了用户体验（别让用户点到一半才发现要登录）。
 */
router.beforeEach(async (to) => {
  if (!to.meta.auth) return true

  const user = useUserStore()
  if (!user.loaded) await user.fetchMe()

  if (!user.logged) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.meta.admin && !user.isAdmin) {
    return { name: 'home' }
  }
  return true
})

router.afterEach((to) => {
  const base = '导航站'
  if (to.meta && to.meta.title) document.title = `${to.meta.title} · ${base}`
})

export default router
