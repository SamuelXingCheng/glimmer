<template>
  <div class="page-container">
    
    <div class="main-card">
      
      <div class="brand-header">
        <img src="/logo.png" alt="Logo" class="brand-logo" />
      </div>

      <div>
        <div class="progress-wrapper">
          <div class="progress-bar">
            <div class="fill" :style="{ width: (step / 6) * 100 + '%' }"></div>
          </div>
        </div>

        <div class="step-header">
          <span class="step-tag">STEP {{ step }} / 6</span>
          <h2 class="title">{{ currentTitle }}</h2>
          <p class="subtitle">{{ currentDesc }}</p>
        </div>

        <div class="content-body">
          <transition name="fade" mode="out-in">
            
            <div v-if="step === 1" class="step-basic" key="step1">
              <div class="input-group">
                <label>喚醒真名</label>
                <input type="text" v-model="form.name" class="text-input" placeholder="例如：Luna">
              </div>
              
              <div class="input-group" style="margin-top: 25px;">
                <label>設定年齡: <span class="age-display">{{ form.age }} 歲</span></label>
                <input type="range" v-model.number="form.age" min="16" max="40" step="1" class="age-slider">
                <div class="slider-labels">
                  <span>16</span><span>40</span>
                </div>
              </div>
            </div>
            
            <div v-else-if="step >= 2 && step <= 5" class="options-grid" :key="step">
              <div 
                v-for="opt in currentOptions" 
                :key="opt.val"
                class="option-item"
                :class="{ active: isSelected(opt.val) }"
                @click="selectOption(opt.val)"
              >
                <div class="icon-box">
                  <span class="material-icons-round">{{ opt.icon }}</span>
                </div>
                <div class="text-box">
                  <span class="opt-label">{{ opt.label }}</span>
                  <span class="opt-desc">{{ opt.desc }}</span>
                </div>
                <div class="check-mark" v-if="isSelected(opt.val)">
                  <span class="material-icons-round">check_circle</span>
                </div>
              </div>
            </div>

            <div v-else class="final-step-confirm" key="step6">
              <div class="summary-card final-summary">
                <div class="summary-title">
                  <span class="material-icons-round">auto_awesome</span> 靈魂契約摘要
                </div>
                <ul class="summary-list">
                  <li><strong>名字：</strong>{{ form.name }} ({{ form.age }}歲)</li>
                  <li><strong>本質：</strong>{{ form.archetype }}</li>
                  <li><strong>特質：</strong>{{ form.traits.join('、') }}</li>
                  <li><strong>關係：</strong>{{ form.relationship }}</li>
                  <li><strong>外觀：</strong>{{ form.appearance.join('、') || '未選擇' }}</li>
                </ul>
              </div>
               <p class="final-hint">確認無誤後，將開始注入意識並啟用此角色設定。</p>
            </div>

          </transition>
        </div>

        <div class="card-footer">
          <button class="btn-prev" v-if="step > 1" @click="step--">
            <span class="material-icons-round">arrow_back</span>
          </button>
          <div v-else></div>
          
          <button class="btn-next" @click="nextStep" :disabled="!canProceed || submitting">
            <template v-if="submitting">
              <span class="material-icons-round rotating">hourglass_top</span> 正在注入意識...
            </template>
            <template v-else-if="step === 6">
              完成召喚 <span class="material-icons-round">fingerprint</span>
            </template>
            <template v-else>
              下一步 <span class="material-icons-round">arrow_forward</span>
            </template>
          </button>
        </div>
      </div>
      
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import liff from '@line/liff';

const LIFF_ID = "2008670429-XlQ1dMMK"; 

const step = ref(1);
const submitting = ref(false);

const form = ref({ 
  name: '', 
  age: 22,
  archetype: '', 
  traits: [], 
  relationship: '', 
  appearance: [] 
});

// 資料定義 (修正為 6 個步驟)
const steps = {
  1: { title: '基本資料', desc: '賦予名字與年齡', type: 'input' },
  2: { title: '靈魂基底', desc: '決定核心本質', type: 'single', options: [
    { val: '溫柔療癒系', label: '治癒者', icon: 'spa', desc: '無條件包容' },
    { val: '理性智慧系', label: '智者', icon: 'menu_book', desc: '冷靜分析' },
    { val: '活潑開朗系', label: '小太陽', icon: 'wb_sunny', desc: '充滿活力' },
    { val: '高冷傲嬌系', label: '貓系', icon: 'pets', desc: '嘴硬心軟' }
  ]},
  3: { title: '性格調味', desc: '複選 1~3 個特質', type: 'multi', options: [
    { val: '愛撒嬌', label: '愛撒嬌', icon: 'favorite' },
    { val: '毒舌', label: '毒舌', icon: 'bolt' },
    { val: '天然呆', label: '天然呆', icon: 'bubble_chart' },
    { val: '知性', label: '知性', icon: 'school' },
    { val: '幽默', label: '幽默', icon: 'sentiment_very_satisfied' },
    { val: '文青', label: '文青', icon: 'local_cafe' }
  ]},
  4: { title: '羈絆定義', desc: '你們的關係是？', type: 'single', options: [ // ❗ Step 4 恢復為羈絆定義
    { val: '熱戀伴侶', label: '靈魂伴侶', icon: 'favorite_border', desc: '親密無間' },
    { val: '知心好友', label: '知心好友', icon: 'people_outline', desc: '輕鬆自在' },
    { val: '貼身管家', label: '專屬管家', icon: 'manage_accounts', desc: '忠誠可靠' },
    { val: '曖昧對象', label: '曖昧中', icon: 'volunteer_activism', desc: '友達以上' }
  ]},
  5: { title: '外觀印象', desc: '最後一步：選擇外觀 (必選)', type: 'multi', options: [ // ❗ Step 5 變成外觀
    { val: '長髮', label: '長髮', icon: 'face' },
    { val: '短髮', label: '短髮', icon: 'face_retouching_natural' },
    { val: '眼鏡', label: '眼鏡', icon: 'visibility' },
    { val: '運動風', label: '運動風', icon: 'fitness_center' },
    { val: '日系穿搭', label: '日系', icon: 'checkroom' },
    { val: '韓系穿搭', label: '韓系', icon: 'styler' },
    { val: '簡約風', label: '簡約', icon: 'style' }
  ]},
  6: { title: '最終確認', desc: '確認契約內容', type: 'final', options: [] } // ❗ Step 6 變成最終確認
};

const currentTitle = computed(() => steps[step.value].title);
const currentDesc = computed(() => steps[step.value].desc);
const currentOptions = computed(() => steps[step.value].options);
const currentType = computed(() => steps[step.value].type);

const isSelected = (val) => {
  if (step.value === 3 || step.value === 5) { // 複選：性格和外觀
    return form.value.traits.includes(val) || form.value.appearance.includes(val);
  }
  // 單選：基底和關係
  return form.value.archetype === val || form.value.relationship === val;
};

const selectOption = (val) => {
  if (step.value === 2) form.value.archetype = val; // Step 2 (單選)
  else if (step.value === 4) form.value.relationship = val; // Step 4 (單選)
  else if (step.value === 3) { // Step 3 (複選 traits)
    const i = form.value.traits.indexOf(val);
    if (i > -1) form.value.traits.splice(i, 1);
    else if (form.value.traits.length < 3) form.value.traits.push(val);
  } else if (step.value === 5) { // ❗ Step 5 (複選 appearance)
    const i = form.value.appearance.indexOf(val);
    if (i > -1) form.value.appearance.splice(i, 1);
    else form.value.appearance.push(val);
  }
};

const canProceed = computed(() => {
  if (step.value === 1) return !!form.value.name && form.value.age >= 16;
  if (step.value === 2) return !!form.value.archetype;
  if (step.value === 3) return form.value.traits.length > 0;
  if (step.value === 4) return !!form.value.relationship; // 羈絆必選
  if (step.value === 5) return form.value.appearance.length > 0; // 外觀必選
  if (step.value === 6) return true; // 最終確認，只要前面都選了即可
  return false;
});

const summaryText = computed(() => {
  return `一位${form.value.age}歲、本質為${form.value.archetype}的${form.value.relationship}。性格${form.value.traits.join('、')}，外觀${form.value.appearance.join('、')}。`;
});

const nextStep = () => {
  if (step.value < 6) step.value++; // 總步數改為 6
  else submitData();
};

const submitData = () => {
  submitting.value = true;
  liff.getProfile().then(profile => {
    const payload = {
      userId: profile.userId,
      config: {
        name: form.value.name,
        age: form.value.age,
        gender: '客製化', 
        appearance: form.value.appearance.join('、'),
        personality: `${form.value.archetype}，帶有${form.value.traits.join('、')}特質`,
        relationship: form.value.relationship,
        user_nickname: profile.displayName
      }
    };

    fetch('../save_persona.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    }).then(res => res.json()).then(data => {
      submitting.value = false;
      if(data.status === 'success') {
        liff.sendMessages([{ type: 'text', text: `設定完成！我是${form.value.name}，以後請多指教囉。✨` }])
          .then(() => liff.closeWindow());
      } else {
        alert('錯誤：資料儲存失敗 (' + data.message + ')');
      }
    }).catch(err => {
      submitting.value = false;
      alert('連線錯誤，請稍後再試');
    });
  });
};

onMounted(() => {
  liff.init({ liffId: LIFF_ID }).then(() => {
    if (!liff.isLoggedIn()) liff.login();
  });
});
</script>

<style scoped>
/* 頁面容器 */
.page-container { min-height: 100vh; padding: 20px; display: flex; justify-content: center; align-items: flex-start; box-sizing: border-box; }
.main-card { background: white; width: 100%; max-width: 420px; border-radius: 24px; padding: 30px 24px; box-shadow: 0 10px 40px rgba(44, 95, 72, 0.08); border: 1px solid rgba(44, 95, 72, 0.05); display: flex; flex-direction: column; min-height: 600px; position: relative; transition: all 0.3s ease; }

/* Logo & Header */
.brand-header { text-align: center; margin-bottom: 20px; }
.brand-logo { width: 70px; height: 70px; border-radius: 50%; object-fit: cover; border: 3px solid white; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
.progress-wrapper { margin-bottom: 25px; }
.progress-bar { height: 4px; background: #F0F0F0; border-radius: 2px; overflow: hidden; }
.fill { height: 100%; background: var(--primary); transition: width 0.4s ease; }
.step-header { text-align: center; margin-bottom: 30px; }
.step-tag { color: var(--accent); font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; display: block; margin-bottom: 8px; }
.title { font-family: 'Noto Serif TC', serif; color: var(--primary); font-size: 26px; margin: 0 0 8px 0; font-weight: 600; }
.subtitle { color: #999; font-size: 14px; margin: 0; }

/* 內容區 */
.content-body { flex-grow: 1; }
.options-grid { display: grid; grid-template-columns: 1fr; gap: 12px; }

/* 選項卡片樣式 */
.option-item { display: flex; align-items: center; padding: 16px; background: white; border: 1px solid #EAEAEA; border-radius: 16px; cursor: pointer; transition: all 0.2s ease; }
.option-item:active { transform: scale(0.98); }
.option-item.active { border-color: var(--primary); background: #F4F8F6; box-shadow: 0 4px 12px rgba(44, 95, 72, 0.1); }
.icon-box { width: 40px; height: 40px; background: #F5F5F5; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-right: 15px; color: #888; transition: 0.2s; }
.option-item.active .icon-box { background: var(--primary); color: white; }
.text-box { flex-grow: 1; }
.opt-label { display: block; font-weight: bold; color: #444; font-size: 15px; }
.opt-desc { font-size: 12px; color: #AAA; }
.check-mark { color: var(--primary); }

/* Step 1: 基本資料樣式 */
.step-basic label { display: block; font-weight: bold; color: var(--primary); margin-bottom: 10px; font-family: 'Noto Serif TC'; }
.text-input { width: 100%; padding: 12px; border: 2px solid #EEE; border-radius: 12px; font-size: 18px; outline: none; transition: 0.3s; font-family: 'Noto Sans TC'; }
.text-input:focus { border-color: var(--accent); background: #FFF; }
.age-display { float: right; color: var(--accent); font-weight: bold; }
.slider-container { padding: 10px 0; }
.age-slider { width: 100%; accent-color: var(--primary); cursor: pointer; }
.slider-labels { display: flex; justify-content: space-between; font-size: 12px; color: #999; margin-top: 5px; }

/* 最終確認樣式 */
.summary-card { background: #FAFAFA; padding: 20px; border-radius: 16px; border: 1px dashed var(--primary); }
.summary-title { color: var(--primary); font-weight: bold; font-size: 16px; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; font-family: 'Noto Serif TC'; }
.summary-list { list-style: none; padding: 0; margin: 0; }
.summary-list li { margin-bottom: 12px; font-size: 14px; color: #555; display: flex; }
.summary-list li strong { min-width: 60px; color: var(--primary); }
.final-hint { text-align: center; font-size: 13px; color: #999; margin-top: 25px; line-height: 1.6; border-top: 1px solid #EEE; padding-top: 15px;}

/* 底部按鈕區 */
.card-footer { margin-top: 30px; display: flex; justify-content: space-between; align-items: center; }
.btn-prev { background: #F5F5F5; border: none; width: 48px; height: 48px; border-radius: 50%; color: #888; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
.btn-prev:hover { background: #EEE; }

.btn-next { background: var(--accent); color: white; border: none; padding: 14px 28px; border-radius: 50px; font-size: 16px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(193, 127, 89, 0.3); transition: 0.2s; }
.btn-next:disabled { background: #E0E0E0; color: #AAA; box-shadow: none; cursor: not-allowed; }
.btn-next:active { transform: scale(0.96); }

/* 動畫與其他 */
.fade-enter-active, .fade-leave-active { transition: opacity 0.3s, transform 0.3s; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(10px); }
.rotating { animation: spin 1s linear infinite; }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
</style>