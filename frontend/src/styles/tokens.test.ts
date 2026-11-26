import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/**
 * Every colour and size a stylesheet asks for exists.
 *
 * A custom property that was never defined is not an error anywhere: the
 * browser quietly drops the declaration. Dialogs asked for `--surface-1`,
 * which no theme defines, so every dialog had no background and the page
 * showed through it. A `var()` with a fallback is fine; one without needs a
 * definition in a stylesheet, or a value the code sets on an element.
 */
// Read from disk: imported into a test, a stylesheet arrives empty. The
// suite runs from the frontend's own folder.
const src = join(process.cwd(), 'src')
const here = join(src, 'styles')

function read(directory: string, keep: (file: string) => boolean): string {
  return (readdirSync(directory, { recursive: true }) as string[])
    .filter(keep)
    .map((file) => readFileSync(join(directory, file), 'utf8'))
    .join('\n')
}

describe('the design tokens', () => {
  it('defines every custom property a stylesheet uses without a fallback', () => {
    const css = read(here, (file) => file.endsWith('.css'))
    const source = read(src, (file) => /\.tsx?$/.test(file) && !/\.test\.tsx?$/.test(file))

    const defined = new Set([
      ...[...css.matchAll(/(--[\w-]+)\s*:/g)].map((match) => match[1]),
      // Set on an element from code: the pointer spotlight, the tilt, chart colours.
      ...[...source.matchAll(/['"`](--[\w-]+)['"`]/g)].map((match) => match[1]),
    ])
    const used = new Set([...css.matchAll(/var\(\s*(--[\w-]+)\s*\)/g)].map((match) => match[1]))

    expect([...used].filter((name) => !defined.has(name)).sort()).toEqual([])
  })
})
