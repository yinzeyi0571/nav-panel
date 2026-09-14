import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { setUnauthorizedHandler } from '@/api'
import { useUserStore } from '@/stores/user'
import { err } from '@/utils/ui'

import './styles/base.css'
import './styles/themes.css'
import './styles/components.css'

const app = createApp(App)

app.use(createPinia())
app.use(router)

// 会话失效兜底：任何接口返回 401 都清掉登录态并跳登录页，带上回跳地址。
// 没有这层兜底时，会话过期只会弹一句错误，页面停在半登录状态（按钮在、点了就报错）。
setUnauthorizedHandler(() => {
  const current = router.currentRoute.value
  useUserStore().clear()
  if (current.name === 'login') return
  err('登录已过期，请重新登录')
  router.replace({
    name: 'login',
    query: current.fullPath && current.fullPath !== '/' ? { redirect: current.fullPath } : {}
  })
})

// 全局兜底：任何组件里抛出的异常都记到控制台，方便线上排查
app.config.errorHandler = (err, _vm, info) => {
  console.error('[nav] 组件异常:', info, err)
}

app.mount('#app')
