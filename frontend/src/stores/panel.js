import { defineStore } from 'pinia'
import { api } from '@/api'
import { applyAppearance, cacheAppearance } from '@/utils/theme'
import { useUserStore } from './user'

const NET_KEY = 'nav.net'

export const usePanelStore = defineStore('panel', {
  state: () => ({
    settings: {},
    categories: [],
    sites: [],
    counts: {},
    version: '',
    releasedAt: '',
    siteName: '导航站',
    loading: false,
    loaded: false,
    /** 内外网切换：external / internal */
    net: localStorage.getItem(NET_KEY) === 'internal' ? 'internal' : 'external'
  }),

  getters: {
    /** 当前是否为编辑态（登录了就能编） */
    editable() {
      return useUserStore().editable
    },

    /** 按 settings.sort_mode 决定排序方式 */
    sortedSites() {
      const mode = this.settings.sort_mode || 'manual'
      const list = this.sites.slice()

      if (mode === 'name') {
        return list.sort((a, b) => String(a.name).localeCompare(String(b.name), 'zh-Hans-CN'))
      }
      if (mode === 'hits') {
        return list.sort((a, b) => Number(b.hits || 0) - Number(a.hits || 0))
      }
      if (mode === 'created') {
        return list.sort((a, b) => Number(b.created_at || 0) - Number(a.created_at || 0))
      }
      return list.sort((a, b) => Number(a.sort || 0) - Number(b.sort || 0))
    },

    /**
     * 全部分组（按 sort 顺序，**含空分组**），每个分组挂上自己的站点。
     *
     * 这里没有「未分类」这种东西 —— 站点永远属于某个真实分组。
     * 万一遇到脏数据（category_id 指向不存在的分组），就并到第一个分组里，
     * 保证前台不会凭空多出一块谁也没见过的区域。
     */
    allGroups() {
      const map = new Map()
      const order = []

      for (const c of this.categories) {
        const id = String(c.id)
        if (map.has(id)) continue // 去重：同一个分组最多渲染一次
        const g = {
          id,
          name: c.name,
          icon: c.icon || '',
          icon_bg: c.icon_bg || '',
          description: c.description || '',
          sites: []
        }
        map.set(id, g)
        order.push(g)
      }

      const home = order.length ? order[0] : null
      for (const s of this.sortedSites) {
        const g = map.get(String(s.category_id))
        if (g) g.sites.push(s)
        else if (home) home.sites.push(s)
      }
      return order
    },

    /** 只显示有站点的分组（前台非编辑态用） */
    groups() {
      return this.allGroups.filter((g) => g.sites.length > 0)
    },

    /** 所有站点合计 */
    totalSites() {
      return this.sites.length
    }
  },

  actions: {
    /* ---------------- 加载 ---------------- */

    async load(force = false) {
      if (this.loading) return
      if (this.loaded && !force) return

      this.loading = true
      try {
        const d = await api.home()
        const user = useUserStore()
        user.apply(d)

        this.settings = d.settings || {}
        this.categories = d.categories || []
        this.sites = d.sites || []
        this.version = d.version || '2.0.0'
        this.releasedAt = d.released_at || ''
        this.siteName = d.site_name || '导航站'

        this.applySettings()
        this.loaded = true
      } finally {
        this.loading = false
      }
    },

    /** 把设置里的外观部分落到 DOM 上 */
    applySettings() {
      applyAppearance(this.settings)
      cacheAppearance(this.settings)

      if (this.settings.logo_text) {
        document.title = this.settings.logo_text
      }
    },

    async saveSettings(patch) {
      const d = await api.settings.save(patch)
      if (d && d.settings) {
        this.settings = d.settings
      } else {
        this.settings = { ...this.settings, ...patch }
      }
      this.applySettings()
      return this.settings
    },

    setNet(mode) {
      this.net = mode === 'internal' ? 'internal' : 'external'
      localStorage.setItem(NET_KEY, this.net)
    },

    toggleNet() {
      this.setNet(this.net === 'internal' ? 'external' : 'internal')
    },

    /* ---------------- 站点 ---------------- */

    async addSite(payload) {
      const d = await api.sites.add(payload)
      await this.load(true)
      return d
    },

    async addSites(payload) {
      const d = await api.sites.addMultiple(payload)
      await this.load(true)
      return d
    },

    async editSite(payload) {
      const d = await api.sites.edit(payload)
      const i = this.sites.findIndex((s) => String(s.id) === String(payload.id))
      if (i >= 0 && d.site) this.sites.splice(i, 1, d.site)
      else await this.load(true)
      if (d.counts) this.counts = d.counts
      return d
    },

    async removeSite(id) {
      const d = await api.sites.remove(id)
      this.sites = this.sites.filter((s) => String(s.id) !== String(id))
      if (d.counts) this.counts = d.counts
      return d
    },

    async removeSites(ids) {
      const d = await api.sites.removeMany(ids)
      const set = new Set(ids.map(String))
      this.sites = this.sites.filter((s) => !set.has(String(s.id)))
      if (d.counts) this.counts = d.counts
      return d
    },

    /** 点击打开：先本地计数 +1，再异步上报，失败也不打扰用户 */
    hitSite(id) {
      const s = this.sites.find((x) => String(x.id) === String(id))
      if (s) s.hits = Number(s.hits || 0) + 1
      api.sites.hit(id).catch(() => {})
    },

    /**
     * 保存排序。
     * groups: { "分组id": ["站点id", ...] }，只提交被拖动的分组也安全，
     * 因为后端按提交内容重排对应分组的 sort。
     */
    async saveSort(groups) {
      const d = await api.sites.saveSort(groups)
      if (d && d.sites) {
        this.sites = d.sites
        this.counts = d.counts || this.counts
      }
      return d
    },

    /* ---------------- 分组 ---------------- */

    async addCategory(payload) {
      const d = await api.categories.add(payload)
      await this.load(true)
      return d
    },

    async editCategory(payload) {
      const d = await api.categories.edit(payload)
      const i = this.categories.findIndex((c) => String(c.id) === String(payload.id))
      if (i >= 0 && d.category) this.categories.splice(i, 1, d.category)
      return d
    },

    async removeCategory(id) {
      const d = await api.categories.remove(id)
      await this.load(true)
      return d
    },

    async saveCategorySort(ids) {
      const d = await api.categories.saveSort(ids)
      if (d && d.categories) this.categories = d.categories
      return d
    },

    /* ---------------- 打开站点 ---------------- */

    /** 按当前内外网模式取实际地址 */
    siteUrl(site) {
      if (this.net === 'internal' && site.url_internal) return site.url_internal
      return site.url || site.url_internal || ''
    },

    openSite(site) {
      const url = this.siteUrl(site)
      if (!url) return

      this.hitSite(site.id)

      const m = Number(site.open_method || 2)
      if (m === 1) {
        window.location.href = url
      } else if (m === 3) {
        // 当前页弹窗打开（对标 Sun-Panel 的 open_method = 3）
        const w = Math.min(window.innerWidth - 80, 1280)
        const h = Math.min(window.innerHeight - 80, 860)
        const left = Math.round((window.innerWidth - w) / 2)
        const top = Math.round((window.innerHeight - h) / 2)
        const win = window.open(
          url,
          'nav_popup',
          `width=${w},height=${h},left=${left},top=${top},resizable=yes,scrollbars=yes`
        )
        if (win) win.focus()
        else window.open(url, '_blank', 'noopener')
      } else {
        window.open(url, '_blank', 'noopener')
      }
    }
  }
})
