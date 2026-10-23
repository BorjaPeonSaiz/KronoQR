// Barrido de las plantillas: que parejas de color USAN de verdad las clases
// `*-kq-*` de las tres SPA y de web-kit (V7-UX-02). Cada literal de texto que
// contiene utilidades de color es una «cadena de clases»; dentro de una cadena,
// el texto, el fondo y la linea (borde, anillo, contorno, relleno) que
// coinciden en un mismo estado forman una pareja.
//
// Limites asumidos: solo ve las parejas cuyas dos mitades estan en el MISMO
// literal (un texto heredado de un contenedor lejano no se ve; se cubre con el
// fondo implicito: pagina o tarjeta). No sustituye a axe en los E2E; cierra el
// hueco de que una pareja nueva entrara sin que nadie la midiera.
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

export type Role = 'text' | 'background' | 'line'

export interface ClassPair {
  readonly file: string
  /** Estado: `rest` o la variante (`hover`, `active`, `aria-disabled`...). */
  readonly state: string
  readonly foreground: string | null
  readonly background: string | null
  /** Para `line`: el borde/anillo/contorno es el primer plano. */
  readonly role: 'text' | 'line'
  readonly classes: string
}

export interface DisabledUse {
  readonly file: string
  readonly opacity: number
  readonly foreground: string | null
  readonly background: string | null
  readonly classes: string
}

export interface BrightnessUse {
  readonly file: string
  readonly shade: number
  readonly foreground: string | null
  readonly background: string | null
  readonly classes: string
}

const UTILITY =
  /^((?:[A-Za-z0-9[\]=_-]+:)*)(bg|text|border|ring|outline|fill|stroke|decoration|divide|placeholder)-kq-([a-z-]+?)(\/\d+)?$/
const RESPONSIVE = new Set(['sm', 'md', 'lg', 'xl', '2xl', 'print', 'motion-safe', 'motion-reduce'])
const ROLE: Record<string, Role> = {
  bg: 'background',
  text: 'text',
  placeholder: 'text',
  border: 'line',
  ring: 'line',
  outline: 'line',
  fill: 'line',
  stroke: 'line',
  decoration: 'line',
  divide: 'line',
}

export function listSources(root: string): string[] {
  const out: string[] = []
  const walk = (dir: string): void => {
    for (const name of readdirSync(dir)) {
      if (name === 'node_modules' || name === 'dist' || name === 'coverage') continue
      const full = join(dir, name)
      if (statSync(full).isDirectory()) walk(full)
      else if (/\.(vue|ts)$/.test(name) && !name.endsWith('.d.ts')) out.push(full)
    }
  }
  walk(root)
  return out
}

/** Los literales de texto (comillas simples, dobles o plantilla) que contienen `-kq-`. */
export function classStrings(source: string): string[] {
  const found: string[] = []
  // Un literal que contiene OTRA comilla es una expresion (`cond ? 'a' : 'b'`): sus
  // literales internos se leen por separado, y mezclarlos inventaria parejas.
  for (const pattern of [/"([^"]*)"/g, /'([^']*)'/g, /`([^`]*)`/g]) {
    for (const match of source.matchAll(pattern)) {
      const body = match[1] ?? ''
      if (body.includes('-kq-') && !/["'`<>{}]/.test(body)) found.push(body)
    }
  }
  return found
}

interface Parsed {
  readonly states: Map<string, { text?: string; background?: string; lines: string[] }>
  readonly opacities: number[]
  readonly brightness: number[]
  /** Utilidades con canal alfa (`bg-kq-text/60`): su color efectivo depende de lo que hay debajo. */
  readonly translucent: string[]
}

function parse(classes: string): Parsed {
  const states: Parsed['states'] = new Map()
  const slot = (state: string) => {
    let entry = states.get(state)
    if (entry === undefined) {
      entry = { lines: [] }
      states.set(state, entry)
    }
    return entry
  }
  const opacities: number[] = []
  const brightness: number[] = []
  const translucent: string[] = []

  for (const raw of classes.split(/\s+/)) {
    if (raw === '') continue
    const parts = raw.split(':')
    const base = parts[parts.length - 1] ?? ''
    const variants = parts.slice(0, -1).filter((v) => !RESPONSIVE.has(v))
    const state = variants.length === 0 ? 'rest' : variants.join(':')

    const opacity = /^opacity-(\d+)$/.exec(base)
    if (opacity !== null && /(^|:)(aria-)?disabled(:|$)/.test(state))
      opacities.push(Number(opacity[1]) / 100)
    const shade = /^brightness-(\d+)$/.exec(base)
    if (shade !== null && /(^|:)hover(:|$)/.test(state)) brightness.push(Number(shade[1]) / 100)

    const utility = UTILITY.exec(raw)
    if (utility === null) continue
    if (utility[4] !== undefined) {
      translucent.push(raw)
      continue
    }
    const kind = utility[2] ?? ''
    const name = `--kq-color-${utility[3] ?? ''}`
    const entry = slot(state)
    const role = ROLE[kind]
    if (role === 'text') entry.text = name
    else if (role === 'background') entry.background = name
    else entry.lines.push(name)
  }
  return { states, opacities, brightness, translucent }
}

export interface Scan {
  readonly pairs: ClassPair[]
  readonly disabled: DisabledUse[]
  readonly brightness: BrightnessUse[]
  readonly translucent: string[]
}

export function scanSource(file: string, source: string): Scan {
  const pairs: ClassPair[] = []
  const disabled: DisabledUse[] = []
  const brightness: BrightnessUse[] = []
  const translucent: string[] = []

  for (const classes of classStrings(source)) {
    const { states, opacities, brightness: shades, translucent: alpha } = parse(classes)
    translucent.push(...alpha.map((utility) => `${file}: ${utility}`))
    const rest = states.get('rest')

    for (const [state, entry] of states) {
      const foreground = entry.text ?? rest?.text ?? null
      const background = entry.background ?? rest?.background ?? null
      const touchesText = entry.text !== undefined || entry.background !== undefined
      if (touchesText && (state === 'rest' ? foreground !== null || background !== null : true)) {
        pairs.push({ file, state, foreground, background, role: 'text', classes })
      }
      for (const line of entry.lines) {
        pairs.push({ file, state, foreground: line, background, role: 'line', classes })
      }
    }

    const ground = rest?.background ?? null
    const text = rest?.text ?? null
    for (const opacity of opacities) {
      disabled.push({ file, opacity, foreground: text, background: ground, classes })
    }
    for (const shade of shades) {
      brightness.push({ file, shade, foreground: text, background: ground, classes })
    }
  }
  return { pairs, disabled, brightness, translucent }
}

export function scanTree(root: string): Scan {
  const pairs: ClassPair[] = []
  const disabled: DisabledUse[] = []
  const brightness: BrightnessUse[] = []
  const translucent: string[] = []
  for (const file of listSources(root)) {
    const scan = scanSource(file, readFileSync(file, 'utf8'))
    pairs.push(...scan.pairs)
    disabled.push(...scan.disabled)
    brightness.push(...scan.brightness)
    translucent.push(...scan.translucent)
  }
  return { pairs, disabled, brightness, translucent }
}
