/**
 * 接口层
 *
 * 后端是「单一入口 + action 分发」：
 *   POST /api/index.php   body: {"action":"sites.add", ...}
 *   GET  /api/index.php?action=releases.list
 *
 * 约定：
 *  - 普通请求 POST，参数走 JSON body（后端 nav_input() 会解析 php://input）
 *  - 纯读接口用 GET，参数走 query
 *  - 文件上传用 FormData（multipart），此时 action 只能放 query
 *  - 统一凭 cookie 维持会话（credentials: same-origin）
 */

const BASE = '/api/index.php'

/** 后端返回 code !== 0 时抛出，带上 code 和 data 供调用方判断 */
export class ApiError extends Error {
  constructor(code, msg, data) {
    super(msg || '请求失败')
    this.name = 'ApiError'
    this.code = code
    this.data = data
  }
}

/** 未登录/无权限时置位，App 层监听它跳登录 */
let onUnauthorized = null
export function setUnauthorizedHandler(fn) {
  onUnauthorized = fn
}

async function call(action, params = {}, opts = {}) {
  const method = (opts.method || 'POST').toUpperCase()
  const url = new URL(BASE, window.location.origin)
  url.searchParams.set('action', action)

  const init = {
    method,
    credentials: 'same-origin',
    headers: {}
  }

  if (opts.form) {
    // 上传：交给浏览器自己设 Content-Type（带 boundary）
    init.body = opts.form
  } else if (method === 'GET') {
    for (const [k, v] of Object.entries(params)) {
      if (v === undefined || v === null || v === '') continue
      url.searchParams.set(k, typeof v === 'object' ? JSON.stringify(v) : String(v))
    }
  } else {
    init.headers['Content-Type'] = 'application/json'
    init.body = JSON.stringify({ action, ...params })
  }

  let res
  try {
    res = await fetch(url.toString(), init)
  } catch (e) {
    throw new ApiError(-1, '网络连接失败，请检查服务是否正常')
  }

  let json
  try {
    json = await res.json()
  } catch (e) {
    throw new ApiError(-2, `服务返回了非 JSON 内容（HTTP ${res.status}）`)
  }

  if (json.code === 401) {
    if (onUnauthorized) onUnauthorized()
    throw new ApiError(401, json.msg || '请先登录', json.data)
  }
  if (json.code !== 0) {
    throw new ApiError(json.code, json.msg, json.data)
  }
  return json.data
}

export const api = {
  /* ---------------- 首页 ---------------- */
  home: () => call('home.index', {}, { method: 'GET' }),

  /* ---------------- 认证 ---------------- */
  auth: {
    /**
     * remember = true  → 长期登录（默认 10 年 + 每次访问滑动续期），关浏览器也不掉线
     * remember = false → 仅本次浏览器会话，关掉浏览器就失效
     */
    login: (username, password, remember = true) =>
      call('auth.login', { username, password, remember: remember ? 1 : 0 }),
    logout: () => call('auth.logout'),
    me: () => call('auth.me', {}, { method: 'GET' }),
    changePassword: (oldPassword, newPassword) =>
      call('auth.changePassword', { old_password: oldPassword, new_password: newPassword }),
    updateProfile: (data) => call('auth.updateProfile', data)
  },

  /* ---------------- 分组 ---------------- */
  categories: {
    list: () => call('categories.list', {}, { method: 'GET' }),
    add: (data) => call('categories.add', data),
    edit: (data) => call('categories.edit', data),
    remove: (id) => call('categories.delete', { id }),
    removeMany: (ids) => call('categories.deletes', { ids }),
    saveSort: (ids) => call('categories.saveSort', { ids })
  },

  /* ---------------- 站点 ---------------- */
  sites: {
    list: (categoryId) =>
      call('sites.list', categoryId == null ? {} : { category_id: categoryId }, { method: 'GET' }),
    add: (data) => call('sites.add', data),
    addMultiple: (data) => call('sites.addMultiple', data),
    edit: (data) => call('sites.edit', data),
    remove: (id) => call('sites.delete', { id }),
    removeMany: (ids) => call('sites.deletes', { ids }),
    /** groups: { "分组id": ["站点id", ...] } */
    saveSort: (groups) => call('sites.saveSort', { groups }),
    hit: (id) => call('sites.hit', { id })
  },

  /* ---------------- 图标 ---------------- */
  icons: {
    upload(file) {
      const fd = new FormData()
      fd.append('file', file)
      return call('icons.upload', {}, { form: fd })
    },
    fetch: (url, force) => call('icons.fetch', { url, force: force ? 1 : 0 }),
    search: (query, limit) => call('icons.search', { query, limit: limit || 64 }),
    collections: () => call('icons.collections', {}, { method: 'GET' }),
    listFiles: (method) =>
      call('icons.listFiles', method == null ? {} : { method }, { method: 'GET' }),
    deleteFile: (id, force) => call('icons.deleteFile', { id, force: force ? 1 : 0 })
  },

  /* ---------------- 设置 ---------------- */
  settings: {
    get: () => call('settings.get', {}, { method: 'GET' }),
    save: (data) => call('settings.save', data),
    reset: () => call('settings.reset')
  },

  /* ---------------- 用户 ---------------- */
  users: {
    list: () => call('users.list', {}, { method: 'GET' }),
    create: (data) => call('users.create', data),
    update: (data) => call('users.update', data),
    removeMany: (ids) => call('users.deletes', { ids }),
    getPublicVisit: () => call('users.getPublicVisit', {}, { method: 'GET' }),
    setPublicVisit: (userId) => call('users.setPublicVisit', { user_id: userId })
  },

  /* ---------------- 系统 ---------------- */
  system: {
    status: () => call('system.status', {}, { method: 'GET' }),
    disks: () => call('system.disks', {}, { method: 'GET' }),
    storage: () => call('system.storage', {}, { method: 'GET' })
  },

  /* ---------------- 备份 ---------------- */
  backup: {
    export: () => call('backup.export', {}, { method: 'GET' }),
    import: (data, mode, applySettings) =>
      call('backup.import', { data, mode, apply_settings: applySettings ? 1 : 0 })
  },

  /* ---------------- 版本 ---------------- */
  releases: {
    list: () => call('releases.list', {}, { method: 'GET' }),
    add: (data) => call('releases.add', data)
  }
}

export default api
