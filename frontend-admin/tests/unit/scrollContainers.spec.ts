// Contenedores con desplazamiento (V21-3-PA-6, R7-PA-01, V21-7-QA-2 · WCAG 2.1.1, 4.1.2).
//
// Por que axe no lo ve (V21-7-QA-2): `scrollable-region-focusable` solo se evalua
// si el contenedor desborda — y casi todo se analiza a 1 366 px con pocas filas— y
// se da por cumplida si hay cualquier elemento enfocable dentro. Casi todos estos
// contenedores tienen botones, asi que axe calla aunque quien navega con teclado no
// pueda desplazar la tabla sin llegar a un control de la columna oculta.
//
// Esta prueba no depende de lo que mida el navegador: recorre las plantillas y
// exige, en CADA elemento con `overflow-auto|x-auto|y-auto|scroll`, `tabindex="0"`,
// `role="region"` y un nombre. Un contenedor nuevo sin ellos hace fallar la prueba.
import { readFileSync, readdirSync } from 'node:fs'
import { join, relative, resolve, sep } from 'node:path'
import { parse } from 'vue/compiler-sfc'
import { describe, expect, it } from 'vitest'

const SOURCE_ROOT = resolve(process.cwd(), 'src')
// Tambien dentro de un `:class` (comillas, llaves, ternarios), no solo en el `class` estatico.
const SCROLL_CLASS = /(?:^|[^\w-])(?:[a-z]+:)?overflow-(?:x-|y-)?(?:auto|scroll)(?![\w-])/

/**
 * Contenedores que desplazan sin ser una tabla de datos, por su `data-test` (el NODO, no el
 * fichero: un contenedor nuevo en el mismo fichero tiene que cumplir) y por que no llevan region.
 */
const EXEMPT: Readonly<Record<string, string>> = {
  'app-sidebar':
    'columna lateral del menu: desplaza solo en escritorio y todo su contenido son enlaces enfocables',
  'dialog-panel':
    'el panel del dialogo: es role="dialog" con nombre y su foco lo gestiona la propia trampa de foco',
}

interface Found {
  readonly file: string
  readonly dataTest: string
  readonly tag: string
  readonly attributes: ReadonlyMap<string, string>
}

function vueFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)

    if (entry.isDirectory()) {
      return vueFiles(path)
    }

    return entry.name.endsWith('.vue') ? [path] : []
  })
}

interface AstNode {
  type: number
  tag?: string
  props?: Array<{
    type: number
    name: string
    value?: { content: string }
    arg?: { content: string }
    exp?: { loc: { source: string } }
  }>
  children?: AstNode[]
  branches?: AstNode[]
}

function walk(node: AstNode, visit: (element: AstNode) => void): void {
  if (node.type === 1) {
    visit(node)
  }

  for (const child of [...(node.children ?? []), ...(node.branches ?? [])]) {
    if (typeof child === 'object') {
      walk(child, visit)
    }
  }
}

function scrollContainers(): Found[] {
  return vueFiles(SOURCE_ROOT).flatMap((path) => {
    const ast = parse(readFileSync(path, 'utf8')).descriptor.template?.ast as AstNode | undefined
    const found: Found[] = []

    if (ast === undefined) {
      return found
    }

    walk(ast, (element) => {
      const attributes = new Map<string, string>()

      for (const prop of element.props ?? []) {
        if (prop.type === 6) {
          attributes.set(prop.name, prop.value?.content ?? '')
        } else if (prop.type === 7 && prop.name === 'bind' && prop.arg?.content !== undefined) {
          attributes.set(':' + prop.arg.content, prop.exp?.loc.source ?? '<dynamic>')
        }
      }

      const classes = `${attributes.get('class') ?? ''} ${attributes.get(':class') ?? ''}`

      if (SCROLL_CLASS.test(classes)) {
        found.push({
          dataTest: attributes.get('data-test') ?? '',
          file: relative(SOURCE_ROOT, path).split(sep).join('/'),
          tag: element.tag ?? '?',
          attributes,
        })
      }
    })

    return found
  })
}

const CONTAINERS = scrollContainers()
const TO_CHECK = CONTAINERS.filter((container) => EXEMPT[container.dataTest] === undefined)

describe('contenedores con desplazamiento (R7-PA-01)', () => {
  it('encuentra los contenedores del panel (la prueba no pasa en vacio)', () => {
    expect(TO_CHECK.length).toBeGreaterThanOrEqual(18)
  })

  it('las excepciones declaradas siguen existiendo', () => {
    for (const dataTest of Object.keys(EXEMPT)) {
      expect(
        CONTAINERS.some((container) => container.dataTest === dataTest),
        `${dataTest} ya no tiene desplazamiento: quitalo de EXEMPT`,
      ).toBe(true)
    }
  })

  it.each(TO_CHECK.map((container, index) => [`${container.file} #${index}`, container] as const))(
    '%s es una region enfocable y con nombre',
    (_label, container) => {
      expect(container.attributes.get('tabindex'), 'tabindex="0"').toBe('0')
      expect(container.attributes.get('role'), 'role="region"').toBe('region')
      expect(
        container.attributes.has('aria-label') ||
          container.attributes.has(':aria-label') ||
          container.attributes.has('aria-labelledby') ||
          container.attributes.has(':aria-labelledby'),
        'nombre accesible (aria-label o aria-labelledby)',
      ).toBe(true)
    },
  )
})
