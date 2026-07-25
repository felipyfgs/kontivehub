#!/usr/bin/env node

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import {
  buildWebInventory,
  hasInventoryDrift,
  inventoryDrift
} from './lib/code-quality-inventory.mjs'

const scriptDir = dirname(fileURLToPath(import.meta.url))
const args = process.argv.slice(2)

function option(name, fallback = null) {
  const equals = args.find(value => value.startsWith(`${name}=`))
  if (equals) return equals.slice(name.length + 1)
  const index = args.indexOf(name)
  return index >= 0 ? args[index + 1] : fallback
}

const webRoot = resolve(option('--root', resolve(scriptDir, '..')))
const output = option('--output')
const expected = option('--expected')
const allowParseErrors = args.includes('--allow-parse-errors')
const input = await new Promise((resolveInput, reject) => {
  let value = ''
  process.stdin.setEncoding('utf8')
  process.stdin.on('data', (chunk) => {
    value += chunk
  })
  process.stdin.on('end', () => resolveInput(value))
  process.stdin.on('error', reject)
})
const paths = input.split(/\r?\n/).map(path => path.trim()).filter(Boolean)
if (paths.length === 0) {
  process.stderr.write('Forneça os paths canônicos pelo stdin.\n')
  process.exitCode = 64
} else {
  const inventory = buildWebInventory(webRoot, paths)
  const json = `${JSON.stringify(inventory, null, 2)}\n`
  if (output) {
    const target = resolve(output)
    mkdirSync(dirname(target), { recursive: true })
    writeFileSync(target, json)
  } else {
    process.stdout.write(json)
  }
  process.stderr.write(`Web inventory ${inventory.digest}: ${inventory.summary.files} arquivos, ${inventory.summary.symbols} símbolos.\n`)
  if (!allowParseErrors && inventory.summary.parseErrors > 0) {
    process.exitCode = 2
  } else if (expected) {
    const expectedInventory = JSON.parse(readFileSync(resolve(expected), 'utf8'))
    const drift = inventoryDrift(expectedInventory, inventory)
    if (hasInventoryDrift(drift)) {
      process.stderr.write(`${JSON.stringify(drift, null, 2)}\n`)
      process.exitCode = 3
    }
  }
}
