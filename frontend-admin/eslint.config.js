// Estilo del frontend (doc 02 §3.5).
//
// - eslint-plugin-vue en flat/recommended: prioridades A y B de la guia de
//   estilo oficial de Vue 3 (nombres de varias palabras, props tipadas, v-for
//   con key, v-if y v-for nunca en el mismo elemento).
// - @typescript-eslint en modo estricto, con no-explicit-any como error: lo
//   desconocido es unknown y se estrecha.
// - Prettier al final, para que no discuta con nadie sobre formato.
//
// La configuracion definitiva de convenciones es de la tarea 0.7.
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import skipFormatting from '@vue/eslint-config-prettier/skip-formatting'
import pluginVue from 'eslint-plugin-vue'

export default defineConfigWithVueTs(
  {
    name: 'kronoqr/files-to-lint',
    files: ['**/*.{ts,mts,tsx,vue,js,mjs}'],
  },
  {
    name: 'kronoqr/files-to-ignore',
    ignores: ['**/dist/**', '**/dev-dist/**', '**/coverage/**', '**/node_modules/**'],
  },
  pluginVue.configs['flat/recommended'],
  vueTsConfigs.strict,
  {
    name: 'kronoqr/rules',
    rules: {
      // Redundante con la configuracion estricta, y a proposito: es la regla
      // que el doc 02 §3.5 nombra explicitamente, y no debe caerse sin que
      // alguien lo decida.
      '@typescript-eslint/no-explicit-any': 'error',

      // H-07 de la revision interna ASVS de 2026-09, y la mitad de frontend de la
      // guarda que `BladeTemplatesTest` pone sobre Blade: `v-html` no escapa.
      //
      // `flat/recommended` ya la trae, pero como `warn`: hoy bloquea solo
      // porque el `script` de lint lleva `--max-warnings 0`, asi que la guarda
      // de seguridad dependia de una bandera de estilo. Quien un dia relajara
      // esa bandera para desatascar la CI abriria un sumidero de HTML sin
      // enterarse. Aqui se declara como lo que es: un error (RS-04).
      'vue/no-v-html': 'error',
    },
  },
  {
    // «Nada de `sleep()` en las pruebas» (doc 02 §3.5 y Definicion de
    // Terminado): se espera POR CONDICION, nunca contra el reloj de pared.
    // Hasta ahora esa convencion no la verificaba ninguna herramienta, y una
    // convencion que no verifica una herramienta es una sugerencia.
    //
    // Solo sobre tests/e2e/**. En las unitarias el reloj se controla con
    // `vi.useFakeTimers()` y ahi `setTimeout` suele ser el SUJETO de la
    // prueba, no una espera.
    //
    // El segundo selector apunta a la forma exacta del sueño —el temporizador
    // no hace nada mas que resolver la promesa— y NO a un `setTimeout` que
    // ejecuta trabajo dentro de un doble (retrasar `getUserMedia` en
    // `delayCameraStart`, cerrar una ventana de medida de LCP). Esa distincion
    // es deliberada: una regla que grita ante el patron correcto acaba
    // desactivada entera.
    name: 'kronoqr/e2e-sin-esperas-por-reloj',
    files: ['tests/e2e/**/*.ts'],
    rules: {
      'no-restricted-syntax': [
        'error',
        {
          selector: 'MemberExpression[property.name="waitForTimeout"]',
          message:
            '`waitForTimeout` duerme contra el reloj de pared: en un runner cargado la espera se queda corta y la prueba parpadea (doc 02 §9.2, «cero pruebas intermitentes»). Espera por condicion: `await expect(locator).toBeVisible()`, `await expect.poll(() => ...).toBe(...)`, `await expect(async () => { ... }).toPass()`, o adelanta el reloj de la pagina con `page.clock`. Si de verdad no hay condicion observable, la excepcion se declara con `// eslint-disable-next-line no-restricted-syntax -- <motivo>` justo encima, con el motivo escrito en esa MISMA linea.',
        },
        {
          selector:
            'NewExpression[callee.name="Promise"] CallExpression[callee.name="setTimeout"][arguments.0.type="Identifier"], NewExpression[callee.name="Promise"] CallExpression[callee.name="setTimeout"][arguments.0.body.type="CallExpression"]',
          message:
            '`new Promise((resolve) => setTimeout(resolve, N))` es un `sleep()` con otro nombre y el doc 02 §3.5 lo prohibe igual: el temporizador no espera a nada, solo deja pasar el tiempo. Espera por condicion: `await expect.poll(() => ...)`, `await expect(async () => { ... }).toPass()`, `page.waitForResponse`/`waitForRequest`, o `page.clock` si lo que hace falta es que la PAGINA crea que ha pasado el tiempo. Si el reloj es legitimo —un doble que simula un servidor lento, no una espera— la excepcion se declara con `// eslint-disable-next-line no-restricted-syntax -- <motivo>` justo encima, con el motivo escrito en esa MISMA linea.',
        },
      ],
    },
  },
  {
    name: 'kronoqr/node-scripts',
    files: ['scripts/**/*.mjs', 'eslint.config.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: { process: 'readonly', console: 'readonly' },
    },
  },
  skipFormatting,
)
