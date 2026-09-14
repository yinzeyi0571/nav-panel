<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { api } from '@/api'
import { useUserStore } from '@/stores/user'
import { err, ok, confirmDialog } from '@/utils/ui'

const me = useUserStore()

const users = ref([])
const pubUserId = ref(0)
const loading = ref(true)
const selected = ref([])

const editOpen = ref(false)
const editing = ref(null)
const saving = ref(false)

const form = reactive({
  id: 0,
  username: '',
  password: '',
  nickname: '',
  email: '',
  role: 'user',
  status: 1
})

const isEdit = computed(() => form.id > 0)

function fmtTime(ts) {
  const n = Number(ts)
  if (!n) return '—'
  const d = new Date(n * 1000)
  const p = (x) => (x < 10 ? '0' + x : x)
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

async function load() {
  loading.value = true
  try {
    const d = await api.users.list()
    users.value = d.users || []
    pubUserId.value = Number(d.public_visit_user_id) || 0
  } catch (e) {
    err(e.message)
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openAdd() {
  editing.value = null
  Object.assign(form, {
    id: 0,
    username: '',
    password: '',
    nickname: '',
    email: '',
    role: 'user',
    status: 1
  })
  editOpen.value = true
}

function openEdit(u) {
  editing.value = u
  Object.assign(form, {
    id: Number(u.id),
    username: u.username,
    password: '',
    nickname: u.nickname || '',
    email: u.email || '',
    role: u.role || 'user',
    status: Number(u.status) === 0 ? 0 : 1
  })
  editOpen.value = true
}

async function submit() {
  saving.value = true
  try {
    if (isEdit.value) {
      const payload = {
        id: form.id,
        nickname: form.nickname,
        email: form.email,
        role: form.role,
        status: form.status
      }
      if (form.password) payload.password = form.password
      const d = await api.users.update(payload)
      users.value = d.users || users.value
      ok('用户已更新')
    } else {
      const d = await api.users.create({
        username: form.username,
        password: form.password,
        nickname: form.nickname || form.username,
        email: form.email,
        role: form.role
      })
      users.value = d.users || users.value
      ok('用户已创建')
    }
    editOpen.value = false
    await load()
  } catch (e) {
    err(e.message)
  } finally {
    saving.value = false
  }
}

async function removeOne(u) {
  if (Number(u.id) === Number(me.user && me.user.id)) {
    err('不能删除当前登录的账号')
    return
  }
  const yes = await confirmDialog(`确定删除用户「${u.username}」吗？该用户的分组和站点也会一起删除。`, {
    title: '删除用户',
    okText: '删除',
    danger: true
  })
  if (!yes) return
  try {
    const d = await api.users.removeMany([Number(u.id)])
    users.value = d.users || users.value
    ok('用户已删除')
    await load()
  } catch (e) {
    err(e.message)
  }
}

async function removeSelected() {
  const ids = selected.value.map(Number).filter((id) => id !== Number(me.user && me.user.id))
  if (!ids.length) {
    err('请先选择要删除的用户（不能包含当前账号）')
    return
  }
  const yes = await confirmDialog(`确定删除选中的 ${ids.length} 个用户吗？`, {
    title: '批量删除',
    okText: '删除',
    danger: true
  })
  if (!yes) return
  try {
    await api.users.removeMany(ids)
    ok('已删除')
    selected.value = []
    await load()
  } catch (e) {
    err(e.message)
  }
}

function toggle(id) {
  const k = Number(id)
  const i = selected.value.findIndex((x) => Number(x) === k)
  if (i >= 0) selected.value.splice(i, 1)
  else selected.value.push(k)
}

function isSel(id) {
  return selected.value.some((x) => Number(x) === Number(id))
}

async function changePublicVisit(e) {
  const v = Number(e.target.value) || 0
  try {
    const d = await api.users.setPublicVisit(v)
    pubUserId.value = Number(d.user_id) || 0
    ok(v > 0 ? '未登录访客将看到该用户的导航页' : '已关闭公开访问')
  } catch (e2) {
    err(e2.message)
    await load()
  }
}
</script>

<template>
  <div class="pane">
    <div class="pane-head">
      <div>
        <h2>用户管理</h2>
        <p class="text-dim">共 {{ users.length }} 个账号。每个用户有独立的导航页数据。</p>
      </div>
      <div class="row">
        <button v-if="selected.length" class="btn btn-sm btn-danger" @click="removeSelected">
          删除选中（{{ selected.length }}）
        </button>
        <button class="btn btn-sm btn-primary" @click="openAdd">新建用户</button>
      </div>
    </div>

    <section class="card">
      <div class="card-title">公开访问</div>
      <div class="field" style="margin-bottom: 0">
        <label class="field-label">未登录的访客看到谁的导航页</label>
        <select class="select" :value="pubUserId" @change="changePublicVisit">
          <option :value="0">关闭（访客看到最早的管理员空间）</option>
          <option v-for="u in users" :key="u.id" :value="Number(u.id)" :disabled="Number(u.status) === 0">
            {{ u.username }}{{ u.nickname ? '（' + u.nickname + '）' : '' }}
            {{ Number(u.status) === 0 ? ' — 已停用' : '' }}
          </option>
        </select>
        <span class="field-hint">
          选一个用户后，未登录访客会直接看到他的分组和站点；关掉则回退到最早的管理员空间。
        </span>
      </div>
    </section>

    <div v-if="loading" class="loading-row">
      <span class="spinner" />
    </div>

    <div v-else class="table">
      <div class="thead">
        <span />
        <span>账号</span>
        <span>昵称</span>
        <span>角色</span>
        <span>状态</span>
        <span>最后登录</span>
        <span class="ta-r">操作</span>
      </div>

      <div v-for="u in users" :key="u.id" class="trow" :class="{ 'is-sel': isSel(u.id) }">
        <label class="ck">
          <input
            type="checkbox"
            :checked="isSel(u.id)"
            :disabled="Number(u.id) === Number(me.user && me.user.id)"
            @change="toggle(u.id)"
          />
        </label>

        <span class="c-user">
          <span class="nm">{{ u.username }}</span>
          <span v-if="Number(u.id) === Number(me.user && me.user.id)" class="me-tag">当前</span>
        </span>

        <span class="truncate">{{ u.nickname || '—' }}</span>

        <span>
          <span class="chip" :class="{ 'chip-accent': u.role === 'admin' }">
            {{ u.role === 'admin' ? '管理员' : '普通' }}
          </span>
        </span>

        <span>
          <span class="dot" :class="{ off: Number(u.status) === 0 }" />
          {{ Number(u.status) === 0 ? '停用' : '正常' }}
        </span>

        <span class="text-weak">{{ fmtTime(u.last_login_at) }}</span>

        <span class="ops">
          <button class="btn btn-sm btn-ghost" @click="openEdit(u)">编辑</button>
          <button
            class="btn btn-sm btn-ghost danger"
            :disabled="Number(u.id) === Number(me.user && me.user.id)"
            @click="removeOne(u)"
          >
            删除
          </button>
        </span>
      </div>
    </div>

    <!-- 新建 / 编辑弹窗 -->
    <Teleport to="body">
      <div v-if="editOpen" class="modal-backdrop" @click.self="editOpen = false">
        <div class="modal" role="dialog" aria-modal="true">
          <div class="modal-head">
            <h3>{{ isEdit ? '编辑用户' : '新建用户' }}</h3>
            <button class="btn btn-icon btn-ghost" @click="editOpen = false">✕</button>
          </div>

          <div class="modal-body">
            <div class="field">
              <label class="field-label">账号</label>
              <input
                v-model="form.username"
                class="input"
                type="text"
                :disabled="isEdit"
                placeholder="字母、数字、下划线，2~32 位"
              />
            </div>

            <div class="grid-2">
              <div class="field">
                <label class="field-label">昵称</label>
                <input v-model="form.nickname" class="input" type="text" maxlength="20" placeholder="可选" />
              </div>
              <div class="field">
                <label class="field-label">邮箱</label>
                <input v-model="form.email" class="input" type="email" placeholder="可选" />
              </div>
            </div>

            <div class="field">
              <label class="field-label">{{ isEdit ? '重置密码（留空则不修改）' : '密码' }}</label>
              <input
                v-model="form.password"
                class="input"
                type="password"
                autocomplete="new-password"
                placeholder="至少 6 位"
              />
            </div>

            <div class="grid-2">
              <div class="field">
                <label class="field-label">角色</label>
                <select v-model="form.role" class="select">
                  <option value="user">普通用户</option>
                  <option value="admin">管理员</option>
                </select>
              </div>
              <div class="field">
                <label class="field-label">状态</label>
                <select v-model.number="form.status" class="select" :disabled="!isEdit">
                  <option :value="1">正常</option>
                  <option :value="0">停用</option>
                </select>
              </div>
            </div>

            <p class="field-hint">
              管理员可以管理用户、版本记录和公开访问设置；普通用户只能管理自己的导航页。
            </p>
          </div>

          <div class="modal-foot">
            <button class="btn" @click="editOpen = false">取消</button>
            <button class="btn btn-primary" :disabled="saving" @click="submit">
              {{ saving ? '保存中…' : '保存' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.pane-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}
.pane-head h2 {
  font-size: 17px;
  font-weight: 600;
  margin-bottom: 3px;
}
.pane-head p {
  font-size: 12.5px;
}

.card {
  margin-bottom: 14px;
}

.table {
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  overflow: hidden;
  background: var(--c-surface);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

.thead,
.trow {
  display: grid;
  grid-template-columns: 34px 1.2fr 1fr 84px 84px 150px 130px;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
}

.thead {
  font-size: 11.5px;
  color: var(--c-text-weak);
  border-bottom: 1px solid var(--c-border);
  background: var(--c-surface-2);
}

.trow {
  border-bottom: 1px solid var(--c-border);
  font-size: 12.5px;
  transition: background var(--dur);
}
.trow:last-child {
  border-bottom: none;
}
.trow:hover {
  background: var(--c-surface-2);
}
.trow.is-sel {
  background: var(--c-accent-soft);
}

.ck {
  display: flex;
  align-items: center;
  justify-content: center;
}
.ck input {
  cursor: pointer;
  accent-color: var(--c-accent);
}

.c-user {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}
.nm {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
}
.me-tag {
  font-size: 10px;
  color: var(--c-accent);
  border: 1px solid var(--c-accent);
  border-radius: var(--r-full);
  padding: 0 5px;
  line-height: 15px;
}

.dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--c-ok);
  margin-right: 5px;
  vertical-align: middle;
}
.dot.off {
  background: var(--c-text-weak);
}

.ops {
  display: flex;
  gap: 2px;
  justify-content: flex-end;
}
.ops .danger {
  color: var(--c-danger);
}

.ta-r {
  text-align: right;
}

@media (max-width: 900px) {
  .thead { display: none; }
  .trow {
    grid-template-columns: 34px 1fr;
    gap: 5px 10px;
    padding: 12px;
  }
  .trow > span:not(.ck) { grid-column: 2; }
  .ops { justify-content: flex-start; }
}
</style>
