import { setAuthTokenProvider, setUnauthenticatedHandler } from '@kronoqr/web-kit/http'
import { VueQueryPlugin } from '@tanstack/vue-query'
import { createPinia } from 'pinia'
import { createApp, watch } from 'vue'
import App from './App.vue'
import './assets/main.css'
import { useSessionStore } from './features/auth/session.store'
import { createAppRouter } from './router'
import { registerAuthGuard } from './router/guards'
import { createAppQueryClient } from './shared/api/queryClient'
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

// El idioma del panel es el de la persona que entra, no el del navegador de la
// tablet en la que se ha dejado la sesion abierta.
watch(
  () => session.user?.locale,
  (userLocale) => {
    if (isSupportedLocale(userLocale)) {
      i18n.global.locale.value = userLocale
    }
  },
)

registerAuthGuard(router)

app.use(router)
app.use(i18n)
app.use(VueQueryPlugin, { queryClient: createAppQueryClient() })

app.mount('#app')
