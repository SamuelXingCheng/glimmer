<template>
  <div class="privacy-container">
    <div class="icon-wrapper">
      <span class="material-icons-round security-icon">verified_user</span>
    </div>
    
    <h2>隱私權與信任聲明</h2>
    <p class="subtitle">在開始靈魂煉成之前，請先確認您的權益。</p>

    <div class="policy-card">
      <div class="policy-item">
        <span class="material-icons-round item-icon">vpn_key</span>
        <div class="item-content">
          <h3>端對端加密儲存</h3>
          <p>您的所有對話紀錄與人設參數，皆透過 AES-256 高強度加密技術儲存於資料庫，即便系統管理員也無法直接查看明文。</p>
        </div>
      </div>

      <div class="policy-item">
        <span class="material-icons-round item-icon">fingerprint</span>
        <div class="item-content">
          <h3>資料去辨識化</h3>
          <p>系統僅使用 LINE User ID 進行去辨識化索引，不會索取您的真實姓名、電話或 LINE ID，確保您的數位身分安全。</p>
        </div>
      </div>

      <div class="policy-item">
        <span class="material-icons-round item-icon">psychology</span>
        <div class="item-content">
          <h3>AI 互動聲明</h3>
          <p>本服務使用 OpenAI 模型生成回應。您的對話僅用於與 AI 互動，不會用於廣告投放或第三方銷售。</p>
        </div>
      </div>
    </div>

    <div class="footer">
      <label class="checkbox-container">
        <input type="checkbox" v-model="agreed">
        <span class="checkmark"></span>
        我已詳細閱讀並同意上述隱私條款
      </label>
      
      <button class="btn" :disabled="!agreed" @click="startSetup">
        同意並開始煉成
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';

const agreed = ref(false);
const router = useRouter();

const startSetup = () => {
  router.push('/setup');
};
</script>

<style scoped>
.privacy-container {
  max-width: 480px;
  margin: 0 auto;
  padding: 30px 20px;
  min-height: 100vh;
  display: flex;
  flex-direction: column;
}

.icon-wrapper {
  text-align: center;
  margin-bottom: 20px;
}

.security-icon {
  font-size: 64px;
  color: var(--primary);
  background: rgba(44, 95, 72, 0.1);
  padding: 20px;
  border-radius: 50%;
}

h2 {
  text-align: center;
  font-family: 'Noto Serif TC', serif;
  color: var(--primary);
  margin: 0 0 10px 0;
}

.subtitle {
  text-align: center;
  color: #888;
  font-size: 14px;
  margin-bottom: 30px;
}

.policy-card {
  background: white;
  border-radius: 16px;
  padding: 25px;
  box-shadow: 0 4px 15px rgba(0,0,0,0.05);
  margin-bottom: 30px;
}

.policy-item {
  display: flex;
  margin-bottom: 25px;
}
.policy-item:last-child {
  margin-bottom: 0;
}

.item-icon {
  color: var(--accent);
  font-size: 24px;
  margin-right: 15px;
  margin-top: 2px;
}

.item-content h3 {
  margin: 0 0 5px 0;
  font-size: 16px;
  color: #333;
}

.item-content p {
  margin: 0;
  font-size: 13px;
  color: #666;
  line-height: 1.5;
  text-align: justify;
}

.footer {
  margin-top: auto;
}

/* 自訂 Checkbox 樣式 */
.checkbox-container {
  display: flex;
  align-items: center;
  margin-bottom: 20px;
  font-size: 14px;
  cursor: pointer;
  color: #555;
  justify-content: center;
}

.checkbox-container input {
  display: none;
}

.checkmark {
  width: 20px;
  height: 20px;
  border: 2px solid #ddd;
  border-radius: 4px;
  margin-right: 10px;
  position: relative;
  transition: 0.2s;
}

.checkbox-container input:checked ~ .checkmark {
  background: var(--primary);
  border-color: var(--primary);
}

.checkbox-container input:checked ~ .checkmark:after {
  content: "✔";
  position: absolute;
  color: white;
  font-size: 14px;
  top: -1px;
  left: 3px;
}
</style>