import { defineStore } from 'pinia'
import { api } from '@/api'

export const useUserStore = defineStore('user', {
  state: () => ({
    user: null,
    isAdmin: false,
    editable: false,
    needPwdChange: false,
    loaded: false
  }),

  getters: {
    logged: (s) => !!s.user,
    displayName: (s) => (s.user ? s.user.nickname || s.user.username : '访客')
  },

  actions: {
    /** home.index 或 auth.me 返回的用户信息都能喂进来 */
    apply(data) {
      if (!data) return
      this.user = data.user || null
      if (typeof data.editable === 'boolean') this.editable = data.editable
      if (typeof data.is_admin === 'boolean') this.isAdmin = data.is_admin
      if (typeof data.need_pwd_change === 'boolean') this.needPwdChange = data.need_pwd_change
      this.loaded = true
    },

    async fetchMe() {
      try {
        const d = await api.auth.me()
        this.apply(d)
      } catch (e) {
        // 未登录是正常状态，不当作错误
        this.user = null
        this.isAdmin = false
        this.editable = false
        this.loaded = true
      }
      return this.user
    },

    async login(username, password, remember = true) {
      const d = await api.auth.login(username, password, remember)
      this.user = d.user
      this.editable = true
      this.isAdmin = d.user && d.user.role === 'admin'
      this.loaded = true
      return d
    },

    /** 会话失效时清空登录态（401 兜底用，不调接口） */
    clear() {
      this.user = null
      this.isAdmin = false
      this.editable = false
    },

    async logout() {
      try {
        await api.auth.logout()
      } catch (e) {
        /* 会话本来就过期了，忽略 */
      }
      this.user = null
      this.isAdmin = false
      this.editable = false
    }
  }
})
