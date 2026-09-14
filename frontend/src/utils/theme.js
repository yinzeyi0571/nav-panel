/**
 * 主题与外观
 *
 * 两个维度：
 *   theme_mode — dark / light / auto，决定明暗
 *   theme      — 配色方案名，决定强调色（对标 Sun-Panel 的 panel_json.theme）
 * 加上一组布局参数（maxWidth / margin* / 背景模糊 / 遮罩），全部落成 CSS 变量。
 */

export const THEME_PRESETS = [
  { key: 'aurora', name: '极光', hint: '深蓝底 + 亮蓝强调' },
  { key: 'night', name: '暗夜', hint: '纯黑底 + 紫强调' },
  { key: 'ocean', name: '海洋', hint: '深青底 + 青色强调' },
  { key: 'sunset', name: '日落', hint: '暖棕底 + 橙色强调' },
  { key: 'minimal', name: '极简', hint: '浅灰底，适合亮色模式' }
]

const ALIAS = {
  'theme-dark': 'night',
  'theme-aurora': 'aurora',
  'theme-ocean': 'ocean',
  'theme-sunset': 'sunset',
  'theme-minimal': 'minimal',
  'theme-light': 'minimal',
  dark: 'night',
  light: 'minimal'
}

export function normalizeTheme(v) {
  const s = String(v || '').trim()
  if (!s) return 'aurora'
  if (ALIAS[s]) return ALIAS[s]
  return THEME_PRESETS.some((t) => t.key === s) ? s : 'aurora'
}

/** auto 模式要跟随系统，这里给它一个统一的判断入口 */
export function isDarkNow(mode) {
  const m = String(mode || 'auto')
  if (m === 'dark') return true
  if (m === 'light') return false
  return window.matchMedia('(prefers-color-scheme: dark)').matches
}

function clamp(v, min, max, dflt) {
  const n = Number(v)
  if (!isFinite(n)) return dflt
  return Math.min(max, Math.max(min, n))
}

/**
 * 把设置落到 CSS 变量上。
 * settings 为 null 时只做兜底（比如首屏还没拉到数据）。
 */
export function applyAppearance(settings) {
  const el = document.documentElement
  const s = settings || {}

  el.setAttribute('data-theme', isDarkNow(s.theme_mode) ? 'dark' : 'light')
  el.setAttribute('data-theme-name', normalizeTheme(s.theme))
  el.setAttribute('data-icon-style', s.icon_style || 'icon')

  el.style.setProperty('--nav-max-width', clamp(s.max_width, 480, 3840, 1200) + 'px')
  el.style.setProperty('--nav-margin-top', clamp(s.margin_top, 0, 60, 4) + 'px')
  el.style.setProperty('--nav-margin-bottom', clamp(s.margin_bottom, 0, 60, 7) + 'px')
  el.style.setProperty('--nav-margin-x', clamp(s.margin_x, 0, 200, 15) + 'px')

  const blur = clamp(s.bg_blur, 0, 100, 0)
  el.style.setProperty('--nav-bg-blur', blur / 10 + 'px')
  el.style.setProperty('--nav-bg-mask', String(clamp(s.bg_mask, 0, 100, 0) / 100))

  if (s.icon_text_color) {
    el.style.setProperty('--nav-icon-text-color', s.icon_text_color)
  }

  const body = document.body
  if (s.wallpaper) {
    body.style.setProperty('--nav-wallpaper', `url("${String(s.wallpaper).replace(/"/g, '\\"')}")`)
    body.classList.add('has-wallpaper')
  } else {
    body.style.removeProperty('--nav-wallpaper')
    body.classList.remove('has-wallpaper')
  }
}

/** 首屏缓存：下一次打开页面时，在接口返回之前先把外观刷上，避免白闪 */
export function cacheAppearance(settings) {
  try {
    const s = settings || {}
    localStorage.setItem(
      'nav.cache',
      JSON.stringify({
        theme: s.theme,
        theme_mode: s.theme_mode,
        wallpaper: s.wallpaper
      })
    )
  } catch (e) {
    /* 隐私模式下 localStorage 可能不可用，忽略 */
  }
}

/** 监听系统主题变化，auto 模式下自动切换 */
export function watchSystemTheme(cb) {
  const mq = window.matchMedia('(prefers-color-scheme: dark)')
  const handler = () => cb()
  if (mq.addEventListener) mq.addEventListener('change', handler)
  else if (mq.addListener) mq.addListener(handler)
  return () => {
    if (mq.removeEventListener) mq.removeEventListener('change', handler)
    else if (mq.removeListener) mq.removeListener(handler)
  }
}
