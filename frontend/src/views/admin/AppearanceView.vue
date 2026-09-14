<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { api } from '@/api'
import { usePanelStore } from '@/stores/panel'
import { err, ok, confirmDialog } from '@/utils/ui'
import { THEME_PRESETS, applyAppearance } from '@/utils/theme'
import { PRESET_BG } from '@/utils/icon'

/** 外观设置：把后端 settings 表的全部外观键都暴露出来（对标 Sun-Panel 的 panel_json） */
const panel = usePanelStore()

const saving = ref(false)
const uploading = ref(false)
const form = reactive({})

const ICON_STYLES = [
  { k: 'icon', name: '大图标', hint: '方块磁贴，最接近 Sun-Panel 默认样式' },
  { k: 'small', name: '小图标', hint: '横向紧凑，一屏放得下更多' },
  { k: 'detail', name: '详情', hint: '显示名称 + 描述两行' }
]

const SEARCH_ENGINES = [
  { k: 'baidu', name: '百度' },
  { k: 'bing', name: 'Bing' },
  { k: 'google', name: 'Google' },
  { k: 'github', name: 'GitHub' },
  { k: 'sogou', name: '搜狗' }
]

const SORT_MODES = [
  { k: 'manual', name: '手动排序（拖动）' },
  { k: 'name', name: '按名称' },
  { k: 'hits', name: '按点击次数' },
  { k: 'created', name: '按添加时间' }
]

function syncFrom() {
  const s = panel.settings || {}
  const keys = [
    'logo_text', 'logo_visible', 'logo_image',
    'theme', 'theme_mode', 'wallpaper', 'bg_blur', 'bg_mask',
    'clock_visible', 'clock_second',
    'search_box_show', 'search_engine',
    'net_toggle_show',
    'icon_style', 'icon_text_color',
    'open_mode',
    'max_width', 'margin_top', 'margin_bottom', 'margin_x',
    'monitor_show', 'monitor_title',
    'sort_mode', 'footer', 'footer_html'
  ]
  for (const k of keys) form[k] = s[k]
}

onMounted(() => {
  syncFrom()
  applyAppearance(form)
})

/** 改动即时预览（不落库），保存时再提交 */
function preview() {
  applyAppearance(form)
}

const dirtyPreviewFields = ['theme', 'theme_mode', 'wallpaper', 'bg_blur', 'bg_mask',
  'max_width', 'margin_top', 'margin_bottom', 'margin_x', 'icon_style', 'icon_text_color']

async function uploadWallpaper(e) {
  const f = e.target.files && e.target.files[0]
  if (!f) return
  uploading.value = true
  try {
    const d = await api.icons.upload(f)
    form.wallpaper = d.icon
    preview()
    ok('壁纸已上传，记得点保存')
  } catch (e2) {
    err(e2.message)
  } finally {
    uploading.value = false
    e.target.value = ''
  }
}

function pickPresetBg(c) {
  form.icon_text_color = c || '#ffffff'
}

async function save() {
  saving.value = true
  try {
    const payload = {}
    for (const k of Object.keys(form)) {
      if (form[k] !== undefined) payload[k] = form[k]
    }
    await panel.saveSettings(payload)
    ok('设置已保存')
  } catch (e) {
    err(e.message)
  } finally {
    saving.value = false
  }
}

function reset() {
  syncFrom()
  preview()
}

async function restoreDefault() {
  const yes = await confirmDialog('恢复默认外观会重置主题、背景、布局和图标设置（不会动你的站点数据）。确定继续吗？', {
    title: '恢复默认外观',
    okText: '恢复',
    danger: true
  })
  if (!yes) return
  try {
    const d = await api.settings.reset()
    if (d && d.settings) panel.settings = d.settings
    panel.applySettings()
    syncFrom()
    ok('已恢复默认外观')
  } catch (e) {
    err(e.message)
  }
}
</script>

<template>
  <div class="pane">
    <div class="pane-head">
      <div>
        <h2>外观设置</h2>
        <p class="text-dim">改动会实时预览，点「保存」后才会写入数据库。</p>
      </div>
      <div class="row">
        <button class="btn btn-sm" @click="reset">还原改动</button>
        <button class="btn btn-sm btn-primary" :disabled="saving" @click="save">
          {{ saving ? '保存中…' : '保存' }}
        </button>
      </div>
    </div>

    <!-- 品牌 -->
    <section class="card">
      <div class="card-title">品牌与页脚</div>

      <div class="field">
        <label class="field-label">站点标题</label>
        <input v-model="form.logo_text" class="input" type="text" maxlength="50" @input="preview" />
      </div>

      <div class="switch-row">
        <div class="switch-row-text">
          <span class="switch-row-title">显示标题</span>
          <span class="switch-row-hint">关掉后只显示 Logo 图或什么都不显示</span>
        </div>
        <button
          class="switch"
          :class="{ 'is-on': Number(form.logo_visible) === 1 }"
          @click="form.logo_visible = Number(form.logo_visible) === 1 ? 0 : 1"
        />
      </div>

      <div class="field" style="margin-top: 14px">
        <label class="field-label">Logo 图片地址</label>
        <input
          v-model="form.logo_image"
          class="input"
          type="text"
          placeholder="留空则不显示图片，只显示标题"
        />
      </div>

      <div class="field" style="margin-bottom: 0">
        <label class="field-label">页脚内容</label>
        <textarea
          v-model="form.footer"
          class="textarea"
          rows="2"
          maxlength="2000"
          placeholder="如：© 2026 我的导航站"
        />
        <div class="switch-row" style="border: none; padding: 6px 0 0">
          <div class="switch-row-text">
            <span class="switch-row-title">把页脚当 HTML 渲染</span>
            <span class="switch-row-hint">开启后可以写 &lt;a&gt; 链接等标签</span>
          </div>
          <button
            class="switch"
            :class="{ 'is-on': Number(form.footer_html) === 1 }"
            @click="form.footer_html = Number(form.footer_html) === 1 ? 0 : 1"
          />
        </div>
      </div>
    </section>

    <!-- 主题 -->
    <section class="card">
      <div class="card-title">主题与背景</div>

      <div class="field">
        <label class="field-label">明暗模式</label>
        <div class="tabs" style="align-self: flex-start">
          <button
            v-for="m in [{ k: 'auto', n: '跟随系统' }, { k: 'dark', n: '深色' }, { k: 'light', n: '浅色' }]"
            :key="m.k"
            :class="{ 'is-active': form.theme_mode === m.k }"
            @click="form.theme_mode = m.k; preview()"
          >
            {{ m.n }}
          </button>
        </div>
      </div>

      <div class="field">
        <label class="field-label">配色方案</label>
        <div class="theme-grid">
          <button
            v-for="t in THEME_PRESETS"
            :key="t.key"
            class="theme-item"
            :class="{ on: form.theme === t.key }"
            @click="form.theme = t.key; preview()"
          >
            <span class="theme-name">{{ t.name }}</span>
            <span class="theme-hint">{{ t.hint }}</span>
          </button>
        </div>
      </div>

      <div class="field">
        <label class="field-label">背景壁纸</label>
        <div class="row row-wrap">
          <label class="btn btn-sm">
            <input type="file" accept="image/*" hidden @change="uploadWallpaper" />
            {{ uploading ? '上传中…' : '上传图片' }}
          </label>
          <button v-if="form.wallpaper" class="btn btn-sm btn-danger" @click="form.wallpaper = ''; preview()">
            清除壁纸
          </button>
        </div>
        <input
          v-model="form.wallpaper"
          class="input"
          type="text"
          style="margin-top: 8px"
          placeholder="或直接粘贴图片地址 https://..."
          @input="preview"
        />
        <span class="field-hint">支持本站上传的图片或 http(s) 外链。</span>
      </div>

      <div class="field">
        <label class="field-label">背景模糊：{{ form.bg_blur }}（0 最清晰，100 最模糊）</label>
        <input v-model.number="form.bg_blur" class="range" type="range" min="0" max="100" step="1" @input="preview" />
      </div>

      <div class="field" style="margin-bottom: 0">
        <label class="field-label">背景遮罩：{{ form.bg_mask }}%（数值越大，壁纸越暗、文字越清楚）</label>
        <input v-model.number="form.bg_mask" class="range" type="range" min="0" max="100" step="1" @input="preview" />
      </div>
    </section>

    <!-- 布局 -->
    <section class="card">
      <div class="card-title">布局</div>

      <div class="grid-2">
        <div class="field">
          <label class="field-label">内容最大宽度：{{ form.max_width }}px</label>
          <input v-model.number="form.max_width" class="range" type="range" min="480" max="3840" step="20" @input="preview" />
        </div>
        <div class="field">
          <label class="field-label">左右边距：{{ form.margin_x }}px</label>
          <input v-model.number="form.margin_x" class="range" type="range" min="0" max="200" step="1" @input="preview" />
        </div>
        <div class="field">
          <label class="field-label">顶部边距：{{ form.margin_top }}px</label>
          <input v-model.number="form.margin_top" class="range" type="range" min="0" max="60" step="1" @input="preview" />
        </div>
        <div class="field">
          <label class="field-label">底部边距：{{ form.margin_bottom }}px</label>
          <input v-model.number="form.margin_bottom" class="range" type="range" min="0" max="60" step="1" @input="preview" />
        </div>
      </div>
    </section>

    <!-- 图标 -->
    <section class="card">
      <div class="card-title">图标</div>

      <div class="field">
        <label class="field-label">图标风格</label>
        <div class="style-grid">
          <button
            v-for="it in ICON_STYLES"
            :key="it.k"
            class="style-item"
            :class="{ on: form.icon_style === it.k }"
            @click="form.icon_style = it.k; preview()"
          >
            <span class="style-name">{{ it.name }}</span>
            <span class="style-hint">{{ it.hint }}</span>
          </button>
        </div>
      </div>

      <div class="field" style="margin-bottom: 0">
        <label class="field-label">图标文字颜色</label>
        <div class="row row-wrap">
          <button
            v-for="c in PRESET_BG"
            :key="c || 'none'"
            class="bg-dot"
            :class="{ on: (form.icon_text_color || '') === c }"
            :style="{ background: c || 'transparent' }"
            :title="c || '默认'"
            @click="pickPresetBg(c); preview()"
          />
          <label class="bg-dot bg-custom" title="自定义颜色">
            <input
              type="color"
              :value="form.icon_text_color || '#ffffff'"
              @input="form.icon_text_color = $event.target.value; preview()"
            />
          </label>
          <span class="mono text-weak" style="margin-left: 6px">{{ form.icon_text_color || '#ffffff' }}</span>
        </div>
        <span class="field-hint">用在「纯文字图标」和设置了底色的图标上。</span>
      </div>
    </section>

    <!-- 功能开关 -->
    <section class="card">
      <div class="card-title">功能与显示</div>

      <div class="switch-row">
        <div class="switch-row-text">
          <span class="switch-row-title">显示时钟</span>
        </div>
        <button class="switch" :class="{ 'is-on': Number(form.clock_visible) === 1 }"
                @click="form.clock_visible = Number(form.clock_visible) === 1 ? 0 : 1" />
      </div>

      <div class="switch-row">
        <div class="switch-row-text">
          <span class="switch-row-title">时钟显示秒</span>
        </div>
        <button class="switch" :class="{ 'is-on': Number(form.clock_second) === 1 }"
                @click="form.clock_second = Number(form.clock_second) === 1 ? 0 : 1" />
      </div>

      <div class="switch-row">
        <div class="switch-row-text">
          <span class="switch-row-title">显示搜索框</span>
        </div>
        <button class="switch" :class="{ 'is-on': Number(form.search_box_show) === 1 }"
                @click="form.search_box_show = Number(form.search_box_show) === 1 ? 0 : 1" />
      </div>

      <div class="switch-row">
        <div class="switch-row-text">
          <span class="switch-row-title">显示「内网 / 外网」切换按钮</span>
          <span class="switch-row-hint">站点填了内网地址时才有意义</span>
        </div>
        <button class="switch" :class="{ 'is-on': Number(form.net_toggle_show) === 1 }"
                @click="form.net_toggle_show = Number(form.net_toggle_show) === 1 ? 0 : 1" />
      </div>

      <div class="switch-row">
        <div class="switch-row-text">
          <span class="switch-row-title">显示系统监控</span>
          <span class="switch-row-hint">CPU / 内存 / 磁盘占用，仅 Linux 有效</span>
        </div>
        <button class="switch" :class="{ 'is-on': Number(form.monitor_show) === 1 }"
                @click="form.monitor_show = Number(form.monitor_show) === 1 ? 0 : 1" />
      </div>

      <div class="field" style="margin-top: 16px">
        <label class="field-label">默认搜索引擎</label>
        <select v-model="form.search_engine" class="select">
          <option v-for="e in SEARCH_ENGINES" :key="e.k" :value="e.k">{{ e.name }}</option>
        </select>
      </div>

      <div class="field" style="margin-bottom: 0">
        <label class="field-label">站点排序方式</label>
        <select v-model="form.sort_mode" class="select">
          <option v-for="s in SORT_MODES" :key="s.k" :value="s.k">{{ s.name }}</option>
        </select>
        <span class="field-hint">
          选「手动排序」时以拖动结果为准；其它方式只影响显示顺序，不会改动你拖出来的排序值。
        </span>
      </div>
    </section>

    <div class="foot-actions">
      <button class="btn btn-sm btn-danger" @click="restoreDefault">恢复默认外观</button>
      <span class="grow" />
      <button class="btn" @click="reset">还原改动</button>
      <button class="btn btn-primary" :disabled="saving" @click="save">
        {{ saving ? '保存中…' : '保存设置' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.pane-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.pane-head h2 {
  font-size: 17px;
  font-weight: 600;
  margin-bottom: 3px;
}
.pane-head p {
  font-size: 12.5px;
}

.card {
  margin-bottom: 14px;
}

.theme-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 8px;
}

.theme-item,
.style-item {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 10px 12px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-sm);
  background: var(--c-surface);
  text-align: left;
  transition: border-color var(--dur), background var(--dur);
}
.theme-item:hover,
.style-item:hover {
  border-color: var(--c-accent);
}
.theme-item.on,
.style-item.on {
  border-color: var(--c-accent);
  background: var(--c-accent-soft);
}

.theme-name,
.style-name {
  font-size: 12.5px;
  font-weight: 600;
}
.theme-hint,
.style-hint {
  font-size: 11px;
  color: var(--c-text-weak);
  line-height: 1.4;
}

.style-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 8px;
}

.bg-dot {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  border: 2px solid var(--c-border);
  flex: none;
  position: relative;
  overflow: hidden;
}
.bg-dot:first-child {
  background-image: linear-gradient(45deg, #999 25%, transparent 25%, transparent 75%, #999 75%),
                    linear-gradient(45deg, #999 25%, transparent 25%, transparent 75%, #999 75%);
  background-size: 8px 8px;
  background-position: 0 0, 4px 4px;
  background-color: transparent;
}
.bg-dot.on {
  border-color: var(--c-accent);
  box-shadow: 0 0 0 2px var(--c-accent-soft);
}
.bg-custom {
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  background: conic-gradient(red, yellow, lime, aqua, blue, magenta, red);
}
.bg-custom input {
  opacity: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
  border: none;
  padding: 0;
}

.foot-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 0 6px;
  flex-wrap: wrap;
}
.foot-actions .grow {
  flex: 1 1 auto;
}
</style>
