<script setup>
import { ref, reactive, onMounted } from 'vue'
import { api } from '@/api'
import { useUserStore } from '@/stores/user'
import { err, ok } from '@/utils/ui'

const user = useUserStore()

const profile = reactive({ nickname: '', email: '', avatar: '' })
const pwd = reactive({ old_password: '', new_password: '', confirm: '' })

const savingProfile = ref(false)
const savingPwd = ref(false)

function syncFrom() {
  const u = user.user || {}
  profile.nickname = u.nickname || ''
  profile.email = u.email || ''
  profile.avatar = u.avatar || ''
}

onMounted(() => {
  syncFrom()
})

async function saveProfile() {
  savingProfile.value = true
  try {
    const d = await api.auth.updateProfile({
      nickname: profile.nickname,
      email: profile.email,
      avatar: profile.avatar
    })
    user.apply({ user: d.user })
    ok('资料已更新')
  } catch (e) {
    err(e.message)
  } finally {
    savingProfile.value = false
  }
}

async function savePassword() {
  if (!pwd.old_password) {
    err('请输入当前密码')
    return
  }
  if (!pwd.new_password) {
    err('请输入新密码')
    return
  }
  if (pwd.new_password !== pwd.confirm) {
    err('两次输入的新密码不一致')
    return
  }

  savingPwd.value = true
  try {
    await api.auth.changePassword(pwd.old_password, pwd.new_password)
    pwd.old_password = ''
    pwd.new_password = ''
    pwd.confirm = ''
    ok('密码已修改')
  } catch (e) {
    err(e.message)
  } finally {
    savingPwd.value = false
  }
}
</script>

<template>
  <div class="pane">
    <div class="pane-head">
      <div>
        <h2>我的账号</h2>
        <p class="text-dim">
          用户名 <b>{{ user.user ? user.user.username : '' }}</b>，
          角色 <b>{{ user.isAdmin ? '管理员' : '普通用户' }}</b>
        </p>
      </div>
    </div>

    <section class="card">
      <div class="card-title">基本资料</div>

      <div class="grid-2">
        <div class="field">
          <label class="field-label">昵称</label>
          <input v-model="profile.nickname" class="input" type="text" maxlength="20" placeholder="显示名" />
        </div>
        <div class="field">
          <label class="field-label">邮箱</label>
          <input v-model="profile.email" class="input" type="email" placeholder="可选" />
        </div>
      </div>

      <div class="field" style="margin-bottom: 0">
        <label class="field-label">头像地址</label>
        <input v-model="profile.avatar" class="input" type="text" placeholder="图片地址，可选" />
      </div>

      <div class="actions">
        <button class="btn btn-primary btn-sm" :disabled="savingProfile" @click="saveProfile">
          {{ savingProfile ? '保存中…' : '保存资料' }}
        </button>
        <button class="btn btn-sm" @click="syncFrom">还原</button>
      </div>
    </section>

    <section class="card">
      <div class="card-title">修改密码</div>

      <div class="field">
        <label class="field-label">当前密码</label>
        <input v-model="pwd.old_password" class="input" type="password" autocomplete="current-password" />
      </div>

      <div class="grid-2">
        <div class="field">
          <label class="field-label">新密码</label>
          <input v-model="pwd.new_password" class="input" type="password" autocomplete="new-password" />
        </div>
        <div class="field">
          <label class="field-label">确认新密码</label>
          <input v-model="pwd.confirm" class="input" type="password" autocomplete="new-password" />
        </div>
      </div>

      <div class="actions" style="border: none; padding-top: 0">
        <button class="btn btn-primary btn-sm" :disabled="savingPwd" @click="savePassword">
          {{ savingPwd ? '提交中…' : '修改密码' }}
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.pane-head {
  margin-bottom: 16px;
}
.pane-head h2 {
  font-size: 17px;
  font-weight: 600;
  margin-bottom: 3px;
}
.pane-head p {
  font-size: 12.5px;
}
.pane-head b {
  color: var(--c-accent);
}

.card {
  margin-bottom: 14px;
}

.actions {
  display: flex;
  gap: 8px;
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--c-border);
}
</style>
