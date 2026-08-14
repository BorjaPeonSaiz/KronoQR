import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'
export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
    VitePWA({
      // 'prompt' y no 'autoUpdate' a proposito: una actualizacion que se
      // aplica sola puede recargar el quiosco en mitad del cambio de turno de
      // las 06:00. La ventana controlada de actualizacion es RF-KI-07 (tarea
      // 3.12); hasta entonces, nada se actualiza sin que alguien lo acepte.
      registerType: 'prompt',
      injectRegister: 'auto',
      manifest: {
        name: 'KronoQR',
        short_name: 'KronoQR',
        description: 'Registro horario por QR',
        start_url: '/',
        display: 'standalone',
        orientation: 'landscape',
        background_color: '#0f172a',
        theme_color: '#0f172a',
        // Sin iconos: los aporta la marca blanca (RF-PD-08, tarea 5.8), que es
        // configuracion y no codigo (CLAUDE.md, regla dura 13).
        icons: [],
      },
      workbox: {
        globPatterns: ['**/*.{js,css,html,svg,woff2}'],
      },
      devOptions: { enabled: false },
    }),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  // Banderas de compilacion de vue-i18n: sin API legacy ni instalacion global
  // el compilador de mensajes se queda fuera del paquete de produccion.
  define: {
    __VUE_I18N_FULL_INSTALL__: 'false',
    __VUE_I18N_LEGACY_API__: 'false',
    __INTLIFY_PROD_DEVTOOLS__: 'false',
  },
  build: {
    target: 'es2022',
    sourcemap: false,
  },
  server: {
    host: true,
    port: 5173,
    strictPort: true,
  },
})
