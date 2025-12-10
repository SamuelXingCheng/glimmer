// src/router.js
import { createRouter, createWebHashHistory } from 'vue-router'
import PrivacyView from './views/PrivacyView.vue'
import SetupView from './views/SetupView.vue'

const routes = [
  { path: '/', component: PrivacyView },
  { path: '/setup', component: SetupView }
]

const router = createRouter({
  // 使用 Hash 模式 (/#/) 可以避免 cPanel 的路由重寫問題，部署最簡單
  history: createWebHashHistory(),
  routes,
})

export default router