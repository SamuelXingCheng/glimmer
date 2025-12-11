// src/router.js
import { createRouter, createWebHashHistory } from 'vue-router'
import SetupView from './views/SetupView.vue'
import PrivacyView from './views/PrivacyView.vue'

const router = createRouter({
  // 2. 修改這裡：改用 Hash 模式
  // 這樣網址會變成 https://.../index.html#/setup，手機一定讀得到
  history: createWebHashHistory(), 
  
  routes: [
    { path: '/', component: PrivacyView },
    { path: '/setup', component: SetupView }
  ]
})

export default router