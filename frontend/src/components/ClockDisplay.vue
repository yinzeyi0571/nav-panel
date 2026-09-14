<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  showSecond: { type: [Boolean, Number, String], default: false }
})

const now = ref(new Date())
let timer = null

const WEEK = ['周日', '周一', '周二', '周三', '周四', '周五', '周六']

function pad(n) {
  return n < 10 ? '0' + n : String(n)
}

const time = computed(() => {
  const d = now.value
  const base = `${pad(d.getHours())}:${pad(d.getMinutes())}`
  return props.showSecond ? `${base}:${pad(d.getSeconds())}` : base
})

const date = computed(() => {
  const d = now.value
  return `${d.getFullYear()} 年 ${d.getMonth() + 1} 月 ${d.getDate()} 日 ${WEEK[d.getDay()]}`
})

function tick() {
  now.value = new Date()
}

onMounted(() => {
  tick()
  // 显示秒时每秒刷，否则每 20 秒刷一次就够（跨分钟最多差 20 秒，靠下面的对齐处理）
  const interval = props.showSecond ? 1000 : 20000
  timer = setInterval(tick, interval)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div class="clock">
    <div class="clock-time">{{ time }}</div>
    <div class="clock-date">{{ date }}</div>
  </div>
</template>

<style scoped>
.clock {
  text-align: center;
  padding: 18px 0 26px;
  user-select: none;
}

.clock-time {
  font-size: clamp(34px, 6vw, 54px);
  font-weight: 200;
  letter-spacing: 2px;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
  color: var(--c-text);
  text-shadow: 0 2px 18px rgba(0, 0, 0, .18);
}

.clock-date {
  margin-top: 6px;
  font-size: 13px;
  color: var(--c-text-dim);
  letter-spacing: .5px;
}
</style>
