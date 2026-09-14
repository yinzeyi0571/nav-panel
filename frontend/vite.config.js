import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

/**
 * 构建产物输出到项目根的 ../dist/，不直接写进站点根目录。
 *
 * 为什么不直接输出到站点根：站点根下还有 api/、config/、uploads/、test/
 * 这些目录，Vite 的 emptyOutDir 会把它们一起清掉。所以产物先进 dist/，
 * 部署时再把 dist/ 里的东西复制到站点根覆盖 index.html。
 */
export default defineConfig({
  plugins: [vue()],

  // 用相对路径。Hash 路由下 hash 不会改变文档路径，所以 ./ 在任何位置都安全
  base: './',

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url))
    }
  },

  build: {
    outDir: '../dist',
    emptyOutDir: true,
    assetsDir: 'assets',
    // 站点里要保留可读的报错信息，不然线上出问题没法查
    sourcemap: false,
    chunkSizeWarningLimit: 1500
  },

  server: {
    host: '0.0.0.0',
    port: 5173,
    // 开发时把 /api 转发到测试容器，接口地址在前端代码里统一写 /api/index.php
    proxy: {
      '/api': {
        target: 'http://192.168.0.4:8080',
        changeOrigin: true
      }
    }
  }
})
