import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import './assets/main.css'
import { createAppRouter } from './router'
import { createAppI18n, resolveLocale } from './shared/i18n'

const app = createApp(App)

app.use(createPinia())
app.use(createAppRouter())
app.use(createAppI18n(resolveLocale(navigator.languages)))

app.mount('#app')
