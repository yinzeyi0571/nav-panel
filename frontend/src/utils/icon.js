/**
 * 图标三态（对标 Sun-Panel 的 icon_json.itemType）
 *   1 = 纯文字：显示名称首字符
 *   2 = 图片：本站上传路径 / 完整 URL / data:
 *   3 = 在线图标：Iconify 名，形如 mdi:home
 */

/** 判断一个 icon 值是不是图片类 */
export function isImageIcon(icon) {
  const v = String(icon || '').trim()
  if (!v) return false
  if (/^(https?:)?\/\//i.test(v)) return true
  if (v.startsWith('/')) return true
  if (v.startsWith('data:')) return true
  return /\.(png|jpe?g|gif|webp|ico|svg)$/i.test(v)
}

/**
 * 由后端存的 icon_type + icon 值决定实际怎么渲染。
 * 后端有时会把 icon_type 存成 2 但值其实是在线图标名，这里做一次兜底纠正。
 */
export function resolveIconType(iconType, icon) {
  const t = Number(iconType) || 0
  const v = String(icon || '').trim()

  if (!v) return 1
  if (t === 1) return 1
  if (t === 3) return isImageIcon(v) ? 2 : 3
  if (t === 2) return isIconifyName(v) ? 3 : 2
  return isImageIcon(v) ? 2 : isIconifyName(v) ? 3 : 1
}

/** 形如 mdi:home 的 Iconify 名 */
export function isIconifyName(v) {
  const s = String(v || '').trim()
  if (!s || isImageIcon(s)) return false
  return /^[a-z0-9][a-z0-9\-_]*:[a-z0-9][a-z0-9\-_]*$/i.test(s)
}

/**
 * Iconify 名 → 可直接放进 <img src> 的地址。
 *
 * 说明：api.iconify.design 对非浏览器 UA 会返回 403，
 * 但浏览器请求（含 <img> 和 CSS mask）是正常 200 的，
 * 所以这里不需要走本站代理，跟 Sun-Panel 的做法一致。
 */
export function iconifySrc(name) {
  const v = String(name || '').trim()
  if (!v) return ''
  if (/^https?:\/\//i.test(v)) return v

  const i = v.indexOf(':')
  if (i <= 0) return ''
  const prefix = v.slice(0, i)
  const icon = v.slice(i + 1)
  if (!/^[a-z0-9\-_]+$/i.test(prefix) || !/^[a-z0-9\-_]+$/i.test(icon)) return ''
  return `https://api.iconify.design/${prefix}/${icon}.svg`
}

/** 取名称首字符做文字图标：中文取第一个字，英文大写 */
export function letterOf(name) {
  const v = String(name || '').trim()
  if (!v) return '?'
  const ch = v[0]
  return /[\u4e00-\u9fa5]/.test(ch) ? ch : ch.toUpperCase()
}

/** 预设底色（沿用 Sun-Panel 的色板） */
export const PRESET_BG = [
  '',
  '#00000000',
  '#000000',
  '#ffffff',
  '#18A058',
  '#2080F0',
  '#F0A020',
  'rgba(208,48,80,1)',
  '#C418D1FF'
]
