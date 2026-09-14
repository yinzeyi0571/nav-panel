<script setup>
import { onMounted, onUnmounted } from 'vue'
import { dialog, resolveDialog } from '@/utils/ui'

function onKeydown(e) {
  if (!dialog.open) return
  if (e.key === 'Escape') {
    e.preventDefault()
    resolveDialog(false)
  } else if (e.key === 'Enter') {
    e.preventDefault()
    resolveDialog(true)
  }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <Teleport to="body">
    <div v-if="dialog.open" class="modal-backdrop" @click.self="resolveDialog(false)">
      <div class="modal" style="max-width: 420px" role="dialog" aria-modal="true">
        <div class="modal-head">
          <h3>{{ dialog.title }}</h3>
        </div>
        <div class="modal-body">
          <p style="white-space: pre-wrap; line-height: 1.7">{{ dialog.text }}</p>
        </div>
        <div class="modal-foot">
          <button v-if="dialog.showCancel" class="btn" @click="resolveDialog(false)">
            {{ dialog.cancelText }}
          </button>
          <button
            class="btn"
            :class="dialog.danger ? 'btn-danger' : 'btn-primary'"
            @click="resolveDialog(true)"
          >
            {{ dialog.okText }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
