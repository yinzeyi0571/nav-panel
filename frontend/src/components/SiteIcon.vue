<script setup>
import { computed, ref, watch } from 'vue'
import { resolveIconType, iconifySrc, letterOf } from '@/utils/icon'

/**
 * 三态图标：
 *   1 文字（名称首字符） / 2 图片 / 3 在线图标（Iconify）
 *
 * 在线图标用 CSS mask 而不是 <img>，因为 mask 可以用 currentColor 着色，
 * 深色底上图标不会变成一团黑。图片加载失败时降级成文字，不留白块。
 */
const props = defineProps({
  name: { type: String, default: '' },
  icon: { type: String, default: '' },
  iconType: { type: [Number, String], default: 0 },
  bg: { type: String, default: '' },
  size: { type: Number, default: 42 },
  radius: { type: String, default: '12px' }
})

const imgFailed = ref(false)
watch(
  () => props.icon,
  () => {
    imgFailed.value = false
  }
)

const type = computed(() => resolveIconType(props.iconType, props.icon))
const letter = computed(() => letterOf(props.name))
const iconify = computed(() => iconifySrc(props.icon))

const showImage = computed(() => type.value === 2 && !!props.icon && !imgFailed.value)
const showIconify = computed(() => type.value === 3 && !!iconify.value)
const showLetter = computed(() => !showImage.value && !showIconify.value)

const hasBg = computed(() => !!String(props.bg || '').trim())

const boxStyle = computed(() => ({
  width: props.size + 'px',
  height: props.size + 'px',
  borderRadius: props.radius,
  background: hasBg.value ? props.bg : 'transparent',
  color: hasBg.value ? 'var(--nav-icon-text-color)' : 'inherit'
}))

const maskStyle = computed(() => {
  const url = `url("${iconify.value}")`
  return {
    width: Math.round(props.size * 0.58) + 'px',
    height: Math.round(props.size * 0.58) + 'px',
    WebkitMaskImage: url,
    maskImage: url,
    WebkitMaskSize: 'contain',
    maskSize: 'contain',
    WebkitMaskRepeat: 'no-repeat',
    maskRepeat: 'no-repeat',
    WebkitMaskPosition: 'center',
    maskPosition: 'center',
    backgroundColor: 'currentColor'
  }
})

const letterStyle = computed(() => ({
  fontSize: Math.round(props.size * 0.4) + 'px'
}))
</script>

<template>
  <div class="site-icon" :style="boxStyle">
    <img v-if="showImage" :src="icon" class="site-icon-img" alt="" @error="imgFailed = true" />
    <span v-else-if="showIconify" class="site-icon-mask" :style="maskStyle" />
    <span v-else class="site-icon-letter" :style="letterStyle">{{ letter }}</span>
  </div>
</template>

<style scoped>
.site-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  overflow: hidden;
  user-select: none;
}

.site-icon-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
  padding: 14%;
}

.site-icon-mask {
  display: block;
}

.site-icon-letter {
  font-weight: 600;
  line-height: 1;
  letter-spacing: .5px;
  opacity: .92;
}
</style>
