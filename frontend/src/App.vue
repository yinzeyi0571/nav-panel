<script setup>
import { onMounted, onUnmounted } from 'vue'
import { toasts, dismissToast } from '@/utils/ui'
import { usePanelStore } from '@/stores/panel'
import { applyAppearance, watchSystemTheme } from '@/utils/theme'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const panel = usePanelStore()
let stopSystemWatch = null

onMounted(() => {
  // 数据到了就撤掉首屏 loading 层
  const boot = document.getElementById('nav-boot')
  if (boot) {
    boot.classList.add('done')
    setTimeout(() => boot.remove(), 400)
  }

  // auto 模式下跟随系统明暗切换
  stopSystemWatch = watchSystemTheme(() => {
    if ((panel.settings.theme_mode || 'auto') === 'auto') {
      applyAppearance(panel.settings)
    }
  })
})

onUnmounted(() => {
  if (stopSystemWatch) stopSystemWatch()
})
</script>

<template>
  <router-view v-slot="{ Component }">
    <transition name="fade" mode="out-in">
      <component :is="Component" />
    </transition>
  </router-view>

  <div class="toast-wrap">
    <div
      v-for="t in toasts"
      :key="t.id"
      class="toast"
      :class="'toast-' + t.type"
      @click="dismissToast(t.id)"
    >
      {{ t.msg }}
    </div>
  </div>

  <ConfirmDialog />
</template>
