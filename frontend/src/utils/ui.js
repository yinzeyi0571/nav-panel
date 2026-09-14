import { reactive } from 'vue'

/* ==================== 轻提示 ==================== */

export const toasts = reactive([])
let seq = 0

export function toast(msg, type = 'info', duration = 2400) {
  const id = ++seq
  toasts.push({ id, msg, type })
  if (duration > 0) {
    setTimeout(() => dismissToast(id), duration)
  }
  return id
}

export function dismissToast(id) {
  const i = toasts.findIndex((t) => t.id === id)
  if (i >= 0) toasts.splice(i, 1)
}

export const ok = (msg) => toast(msg, 'ok')
export const err = (msg) => toast(msg, 'err', 3600)
export const warn = (msg) => toast(msg, 'warn', 3000)

/* ==================== 确认框 ==================== */

export const dialog = reactive({
  open: false,
  title: '',
  text: '',
  okText: '确认',
  cancelText: '取消',
  danger: false,
  showCancel: true,
  _resolve: null
})

/**
 * 用法：if (!(await confirmDialog('确定删除？', { danger: true }))) return
 */
export function confirmDialog(text, opts = {}) {
  dialog.title = opts.title || '请确认'
  dialog.text = text
  dialog.okText = opts.okText || '确认'
  dialog.cancelText = opts.cancelText || '取消'
  dialog.danger = !!opts.danger
  dialog.showCancel = opts.showCancel !== false
  dialog.open = true

  return new Promise((resolve) => {
    dialog._resolve = resolve
  })
}

export function resolveDialog(value) {
  dialog.open = false
  if (dialog._resolve) {
    dialog._resolve(value)
    dialog._resolve = null
  }
}
