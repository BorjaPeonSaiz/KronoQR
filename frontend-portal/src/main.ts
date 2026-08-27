import { setAuthTokenProvider, setUnauthenticatedHandler } from '@kronoqr/web-kit/http'
import { createPinia } from 'pinia'
import { createApp, watch } from 'vue'
import App from './App.vue'
import './assets/main.css'
import { useSessionStore } from './features/login/session.store'
import { createAppRouter } from './router'
import { registerAuthGuard } from './router/guards'
import { createAppI18n, isSupportedLocale, resolveLocale } from './shared/i18n'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)

const router = createAppRouter()
const i18n = createAppI18n(resolveLocale(navigator.languages))
const session = useSessionStore(pinia)

// El cliente HTTP no conoce la tienda y la tienda no conoce al router: se atan
// aqui, en el arranque, que es el unico sitio donde se puede sin crear un ciclo.
setAuthTokenProvider(() => session.token)
setUnauthenticatedHandler(() => {
  session.clear()
  void router.push({ name: 'login' })
})

// El idioma del portal es el de la persona que ha entrado (`employees.locale`),
// no el del navegador desde el que mira ni el del dispositivo compartido del
// centro (doc 01 §6.6, PortalEmployee.locale).
watch(
  () => session.employee?.locale,
  (employeeLocale) => {
    if (isSupportedLocale(employeeLocale)) {
      i18n.global.locale.value = employeeLocale
    }
  },
  { immediate: true },
)

registerAuthGuard(router)

app.use(router)
app.use(i18n)

app.mount('#app')
