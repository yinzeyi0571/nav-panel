<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { usePanelStore } from '@/stores/panel'
import { applyAppearance } from '@/utils/theme'
import { err, ok } from '@/utils/ui'

const route = useRoute()
const router = useRouter()
const user = useUserStore()
const panel = usePanelStore()

const username = ref('')
const password = ref('')
const remember = ref(true)
const loading = ref(false)
const focusField = ref('username')

async function submit() {
  if (!username.value.trim()) {
    err('请输入用户名')
    return
  }
  if (!password.value) {
    err('请输入密码')
    return
  }

  loading.value = true
  try {
    await user.login(username.value.trim(), password.value, remember.value)
    ok('登录成功')
    await panel.load(true)

    const redirect = route.query.redirect
    router.replace(typeof redirect === 'string' && redirect ? redirect : '/')
  } catch (e) {
    err(e.message)
    password.value = ''
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  // 登录页不经过首页，主题/站点名不会有人帮它设置 —— 这里自己补上，
  // 否则在有缓存之前进来会是一整页没有颜色的白底。
  applyAppearance(panel.settings)
  if (!panel.loaded) panel.load()

  // 已经登录了就直接回去
  if (!user.loaded) await user.fetchMe()
  if (user.logged) {
    router.replace('/')
    return
  }
  const el = document.querySelector('input[type="text"]')
  if (el) el.focus()
})
</script>

<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-head">
        <div class="login-logo">{{ (panel.siteName || '导航站').slice(0, 1) }}</div>
        <h1>{{ panel.siteName || '导航站' }}</h1>
        <p class="text-weak">登录后使用自己的导航与外观（未登录时展示公共导航页）</p>
      </div>

      <form class="login-form" @submit.prevent="submit">
        <div class="field">
          <label class="field-label">用户名</label>
          <input
            v-model="username"
            class="input"
            type="text"
            autocomplete="username"
            placeholder="admin"
            @keyup.enter="focusField = 'password'"
          />
        </div>

        <div class="field">
          <label class="field-label">密码</label>
          <input
            v-model="password"
            class="input"
            type="password"
            autocomplete="current-password"
            placeholder="请输入密码"
          />
        </div>

        <label class="remember">
          <input v-model="remember" type="checkbox" />
          <span>记住我</span>
          <em class="text-weak">{{ remember ? '长期保持登录' : '关闭浏览器即退出' }}</em>
        </label>

        <button class="btn btn-primary btn-block" type="submit" :disabled="loading">
          {{ loading ? '登录中…' : '登录' }}
        </button>
      </form>

      <a class="login-back" href="#/">← 返回首页</a>
    </div>
  </div>
</template>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
}

.login-card {
  width: 100%;
  max-width: 348px;
  padding: 30px 26px 22px;
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-lg);
  box-shadow: var(--c-shadow);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
}

.login-head {
  text-align: center;
  margin-bottom: 22px;
}

.login-logo {
  width: 46px;
  height: 46px;
  margin: 0 auto 12px;
  border-radius: 14px;
  background: var(--c-accent);
  color: var(--c-accent-contrast);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 21px;
  font-weight: 600;
}

.login-head h1 {
  font-size: 17px;
  font-weight: 600;
  margin-bottom: 4px;
}

.login-head p {
  font-size: 12px;
}

.login-form {
  margin-bottom: 8px;
}

.remember {
  display: flex;
  align-items: center;
  gap: 7px;
  margin: 2px 0 16px;
  font-size: 12.5px;
  cursor: pointer;
  user-select: none;
}
.remember input {
  width: 14px;
  height: 14px;
  accent-color: var(--c-accent);
  cursor: pointer;
  margin: 0;
}
.remember em {
  font-style: normal;
  font-size: 11.5px;
  margin-left: auto;
}

.login-back {
  display: block;
  text-align: center;
  font-size: 12px;
  color: var(--c-text-weak);
  padding: 6px;
  transition: color var(--dur);
}
.login-back:hover {
  color: var(--c-accent);
}
</style>
