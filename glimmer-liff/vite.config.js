import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  // 模仿 Finance Bot 的單純性，但為了保險加上 ./
  base: './', 
  build: {
    outDir: 'dist'
  }
})