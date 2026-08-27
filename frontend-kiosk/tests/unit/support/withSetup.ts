import { defineComponent, h } from 'vue'
import { mount } from '@vue/test-utils'
import type { VueWrapper } from '@vue/test-utils'

/**
 * Ejecuta un composable dentro de un componente real, para que `onUnmounted` y
 * el resto de ganchos de ciclo de vida existan.
 *
 * Es imprescindible en este proyecto: la mitad de lo que hay que probar del
 * escaneo es justamente que se LIBERAN los recursos al desmontar.
 */
export function withSetup<T>(composable: () => T): { result: T; wrapper: VueWrapper } {
  let result!: T

  const wrapper = mount(
    defineComponent({
      setup() {
        result = composable()
        return () => h('div')
      },
    }),
  )

  return { result, wrapper }
}
