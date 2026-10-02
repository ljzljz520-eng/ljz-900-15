<template>
  <div class="fix-page">
    <div class="fix-bg">
      <div class="fix-grid" aria-hidden="true"></div>
      <div class="fix-glow fix-glow-1"></div>
      <div class="fix-glow fix-glow-2"></div>
    </div>

    <div class="fix-container">
      <header class="fix-header">
        <div class="fix-brand">
          <div class="fix-logo">
            <svg viewBox="0 0 40 40" fill="none">
              <rect width="40" height="40" rx="10" fill="url(#fix-grad)" />
              <path d="M20 12v16M12 20h16" stroke="white" stroke-width="2" stroke-linecap="round" />
              <defs>
                <linearGradient id="fix-grad" x1="0" y1="0" x2="40" y2="40">
                  <stop stop-color="#0EA5E9" />
                  <stop offset="1" stop-color="#06B6D4" />
                </linearGradient>
              </defs>
            </svg>
          </div>
          <div>
            <h1 class="fix-title">员工整改</h1>
            <p v-if="!token" class="fix-warn">请通过扫码或链接（含 token）进入</p>
            <p v-else class="fix-sub">查看待整改项并上传整改图（图片对按 #key 从小到大排序）</p>
          </div>
        </div>
      </header>

      <section v-loading="loading" class="fix-content">
        <div v-if="token" class="fix-toolbar">
          <el-switch v-model="onlyPending" active-text="仅看待整改" inactive-text="显示全部" />
        </div>

        <div v-if="!token" class="fix-empty">
          <div class="fix-empty-icon">
            <el-icon><Link /></el-icon>
          </div>
          <p class="fix-empty-text">缺少 token</p>
          <p class="fix-empty-hint">无法加载整改列表，请使用管理员提供的链接或扫码进入</p>
        </div>

        <div v-else-if="records.length === 0 && !loading" class="fix-empty">
          <div class="fix-empty-icon success">
            <el-icon><CircleCheck /></el-icon>
          </div>
          <p class="fix-empty-text">暂无待整改记录</p>
          <p class="fix-empty-hint">您当前没有需要整改的项目</p>
        </div>

        <div v-else class="fix-list">
          <transition-group name="fix-list" tag="div" class="fix-list-inner">
            <div
              v-for="r in records"
              :key="r.id"
              class="fix-card"
            >
              <div class="fix-card-meta">
                <span class="fix-seq" :title="'序号 #' + r.sequence_key">#{{ r.sequence_key }}</span>
                <span class="fix-badge-name">{{ r.item_name_snapshot || r.item?.name }}</span>
                <span class="fix-badge-score">-{{ (r.item_score_snapshot ?? r.item?.score) }}分</span>
              </div>
              <div class="fix-card-images">
                <div class="fix-img-box">
                  <img
                    :src="imageUrl(r.issue_image)"
                    alt="问题图"
                    @error="(e) => (e.target.style.display = 'none')"
                  />
                </div>
                <div class="fix-arrow">
                  <el-icon v-if="r.fix_image" class="fix-check"><CircleCheck /></el-icon>
                  <span v-else>→</span>
                </div>
                <div class="fix-img-box">
                  <div v-if="uploadingId === r.id" class="fix-uploading">
                    <el-icon class="fix-spin"><Loading /></el-icon>
                  </div>
                  <template v-if="r.fix_image">
                    <el-image
                      class="fix-thumb"
                      :src="imageUrl(r.fix_image)"
                      :preview-src-list="[imageUrl(r.fix_image)]"
                      preview-teleported
                      fit="cover"
                      hide-on-click-modal
                    >
                      <template #error>
                        <div class="fix-thumb-error">图片加载失败</div>
                      </template>
                      <template #placeholder>
                        <div class="fix-thumb-loading"><el-icon class="fix-spin"><Loading /></el-icon></div>
                      </template>
                    </el-image>
                  </template>
                  <template v-else>
                    <div class="fix-upload-area">
                      <el-icon class="fix-upload-icon"><Plus /></el-icon>
                      <span>待上传整改图</span>
                      <el-upload
                        :show-file-list="false"
                        :accept="ACCEPT_TYPES"
                        :before-upload="(file) => uploadFix(r, file)"
                      >
                        <el-button type="primary" size="small">上传整改图</el-button>
                      </el-upload>
                      <small class="fix-upload-limit">支持 JPG / PNG / GIF / WEBP，单张不超过 {{ maxMB }}MB</small>
                    </div>
                  </template>
                </div>
              </div>

              <div v-if="r.fix_image" class="fix-card-foot">
                <div class="fix-upload-meta">
                  <span class="fix-upload-time">最后上传：{{ formatTime(r.fix_uploaded_at) }}</span>
                  <span v-if="confirmedMap[r.id]" class="fix-confirmed">
                    <el-icon><CircleCheckFilled /></el-icon>已确认图片正确
                  </span>
                  <span v-else class="fix-unconfirmed">请核对缩略图，确认是否为本次整改照片</span>
                </div>
                <div class="fix-actions">
                  <el-button
                    v-if="!confirmedMap[r.id]"
                    type="success"
                    size="small"
                    @click="confirmFix(r.id)"
                  >图片正确</el-button>
                  <el-upload
                    :show-file-list="false"
                    :accept="ACCEPT_TYPES"
                    :before-upload="(file) => uploadFix(r, file)"
                  >
                    <el-button size="small" :loading="uploadingId === r.id">重新上传</el-button>
                  </el-upload>
                </div>
              </div>
            </div>
          </transition-group>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { CircleCheck, CircleCheckFilled, Loading, Link, Plus } from '@element-plus/icons-vue'
import { api, apiBase } from '@/api/request'

const route = useRoute()
const loading = ref(true)
const uploadingId = ref(null)
const records = ref([])
const onlyPending = ref(false)
// 员工本地确认“传对了”的记录（刷新后需重新核对，不写入服务端状态）
const confirmedMap = reactive({})

const token = computed(() => route.query.token || '')

// 与后端 UploadController 保持一致
const MAX_SIZE = 10 * 1024 * 1024
const maxMB = Math.round(MAX_SIZE / 1024 / 1024)
const ACCEPT_TYPES = 'image/jpeg,image/png,image/gif,image/webp'
const ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp']

function imageUrl(path) {
  if (!path) return ''
  const base = apiBase() || (typeof window !== 'undefined' ? window.location.origin : '')
  return path.startsWith('http') ? path : (base.replace(/\/$/, '') + path)
}

function formatTime(t) {
  if (!t) return '—'
  // 兼容 "YYYY-MM-DD HH:mm:ss"（部分浏览器不能直接解析）
  const d = new Date(typeof t === 'string' ? t.replace(/-/g, '/') : t)
  if (Number.isNaN(d.getTime())) return String(t)
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

// 上传前的前端校验：格式 + 大小，不通过直接提示，不发请求
function validateImage(file) {
  const name = file.name || ''
  const ext = name.includes('.') ? name.split('.').pop().toLowerCase() : ''
  const typeOk = ALLOWED_EXT.includes(ext) || (file.type || '').startsWith('image/')
  if (!typeOk) {
    ElMessage.error('仅支持 JPG、PNG、GIF、WEBP 格式的图片，请重新拍摄或转换格式后上传')
    return false
  }
  if (file.size > MAX_SIZE) {
    ElMessage.error(`图片大小不能超过 ${maxMB}MB（当前约 ${(file.size / 1024 / 1024).toFixed(1)}MB），请压缩或重新拍摄后再上传`)
    return false
  }
  if (!file.size) {
    ElMessage.error('文件无效或为空，请重新拍摄后上传')
    return false
  }
  return true
}

async function loadRecords() {
  if (!token.value) {
    loading.value = false
    return
  }
  loading.value = true
  try {
    const list = await api.getRecords({ token: token.value, status: onlyPending.value ? 'pending' : undefined })
    records.value = list || []
  } catch (_) {
    records.value = []
  } finally {
    loading.value = false
  }
}

async function uploadFix(record, file) {
  if (!validateImage(file)) return false
  uploadingId.value = record.id
  try {
    const res = await api.uploadImage(file, token.value)
    if (!res?.path) throw new Error('上传失败')
    // 同一个 key 重复上传：覆盖 fix_image，服务端刷新 fix_uploaded_at（最后一次上传时间）
    const updated = await api.uploadFix(record.id, res.path, token.value)
    const idx = records.value.findIndex((r) => r.id === record.id)
    const next = updated && updated.id
      ? { ...record, ...updated }
      : { ...record, fix_image: res.path, fix_uploaded_at: nowText(), status: 'completed' }
    if (idx !== -1) {
      records.value[idx] = next
    }
    // 重新上传后需重新核对
    delete confirmedMap[record.id]
    ElMessage.success('上传成功，请核对缩略图确认是否传对')
  } catch (_) {
    // request 拦截器已弹出服务端返回的“压缩或重新拍摄”等提示
  } finally {
    uploadingId.value = null
  }
  return false
}

function nowText() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

function confirmFix(id) {
  confirmedMap[id] = true
  ElMessage.success('已确认整改图正确')
}

onMounted(loadRecords)

// 切换筛选后刷新
watch(onlyPending, loadRecords)
</script>

<style scoped>
.fix-page {
  min-height: 100vh;
  padding: 24px;
  position: relative;
}

.fix-bg {
  position: fixed;
  inset: 0;
  background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
  z-index: 0;
}

.fix-grid {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(14, 165, 233, 0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(14, 165, 233, 0.03) 1px, transparent 1px);
  background-size: 40px 40px;
}

.fix-glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(100px);
  opacity: 0.3;
  pointer-events: none;
}

.fix-glow-1 {
  width: 400px;
  height: 400px;
  background: #0ea5e9;
  top: -100px;
  right: -100px;
}

.fix-glow-2 {
  width: 300px;
  height: 300px;
  background: #06b6d4;
  bottom: -80px;
  left: -80px;
}

.fix-container {
  position: relative;
  z-index: 1;
  max-width: 1120px;
  margin: 0 auto;
}

.fix-header {
  margin-bottom: 32px;
}

.fix-brand {
  display: flex;
  align-items: center;
  gap: 16px;
}

.fix-logo {
  width: 56px;
  height: 56px;
}

.fix-logo svg {
  width: 100%;
  height: 100%;
}

.fix-title {
  font-size: 28px;
  font-weight: 700;
  color: white;
  margin: 0 0 4px;
  letter-spacing: -0.02em;
}

.fix-warn {
  font-size: 14px;
  color: #f87171;
  margin: 0;
}

.fix-sub {
  font-size: 14px;
  color: #94a3b8;
  margin: 0;
}

.fix-content {
  min-height: 200px;
}

.fix-toolbar {
  display: flex;
  justify-content: flex-end;
  margin: 0 0 16px;
}

.fix-empty {
  background: rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 20px;
  padding: 60px 40px;
  text-align: center;
}

.fix-empty-icon {
  width: 80px;
  height: 80px;
  margin: 0 auto 24px;
  border-radius: 20px;
  background: rgba(248, 113, 113, 0.2);
  color: #f87171;
  font-size: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.fix-empty-icon.success {
  background: rgba(16, 185, 129, 0.2);
  color: #34d399;
}

.fix-empty-text {
  font-size: 20px;
  font-weight: 600;
  color: white;
  margin: 0 0 8px;
}

.fix-empty-hint {
  font-size: 14px;
  color: #94a3b8;
  margin: 0;
}

.fix-list-inner {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.fix-card {
  background: rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(12px);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  padding: 20px;
  transition: all 0.2s;
}

.fix-card:hover {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(14, 165, 233, 0.3);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.fix-card-meta {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 16px;
}

.fix-seq {
  background: rgba(14, 165, 233, 0.25);
  color: #7dd3fc;
  padding: 4px 10px;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
}

.fix-badge-name {
  font-size: 14px;
  font-weight: 600;
  color: #e2e8f0;
}

.fix-badge-score {
  font-size: 14px;
  font-weight: 600;
  color: #f87171;
}

.fix-card-images {
  display: flex;
  align-items: stretch;
  gap: 16px;
}

.fix-img-box {
  flex: 1;
  min-width: 0;
  border-radius: 12px;
  overflow: hidden;
  background: rgba(0, 0, 0, 0.2);
  aspect-ratio: 4/3;
  position: relative;
}

.fix-img-box img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.fix-thumb {
  width: 100%;
  height: 100%;
  display: block;
  cursor: zoom-in;
}

.fix-thumb :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.fix-thumb-error,
.fix-thumb-loading {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #94a3b8;
  font-size: 13px;
}

.fix-arrow {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  font-size: 24px;
  color: #64748b;
}

.fix-check {
  color: #34d399;
  font-size: 28px;
}

.fix-upload-area {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: #94a3b8;
  font-size: 14px;
}

.fix-upload-icon {
  font-size: 28px;
  color: #64748b;
}

.fix-upload-limit {
  color: #64748b;
  font-size: 12px;
  text-align: center;
  line-height: 1.4;
  padding: 0 8px;
}

.fix-card-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  flex-wrap: wrap;
}

.fix-upload-meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.fix-upload-time {
  font-size: 13px;
  color: #cbd5e1;
}

.fix-confirmed {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  color: #34d399;
}

.fix-unconfirmed {
  font-size: 12px;
  color: #fbbf24;
}

.fix-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.fix-uploading {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, 0.55);
  backdrop-filter: blur(2px);
}

.fix-spin {
  font-size: 32px;
  color: #0ea5e9;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.fix-list-enter-active,
.fix-list-leave-active {
  transition: all 0.3s ease;
}

.fix-list-enter-from,
.fix-list-leave-to {
  opacity: 0;
  transform: translateY(12px);
}
</style>
