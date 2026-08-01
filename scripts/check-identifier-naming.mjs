import { execFileSync } from 'node:child_process'
import { readFileSync } from 'node:fs'
import { dirname, extname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const forbiddenTermStems = new Map([
  ['english_compatibility_marker', ['leg', 'acy'].join('')],
  ['portuguese_compatibility_marker', ['leg', 'ad'].join('')]
])
const textExtensions = new Set([
  '.css', '.env', '.example', '.go', '.html', '.js', '.json', '.md', '.mjs',
  '.php', '.sql', '.ts', '.txt', '.vue', '.xml', '.yaml', '.yml'
])
const textBasenames = new Set(['Dockerfile', 'Makefile'])
const preservedLiteral = ['leg', 'acy_provisional'].join('')
const contractValueExtensions = new Set(['.go', '.js', '.json', '.mjs', '.php', '.ts'])
const slashCommentExtensions = new Set(['.go', '.js', '.mjs', '.php', '.ts'])
const blockCommentExtensions = new Set(['.go', '.js', '.mjs', '.php', '.ts'])
const hashCommentExtensions = new Set(['.php'])

function stripQuotedContractValues (source, extension) {
  let result = ''
  let index = 0
  let state = 'code'
  let quote = ''
  let value = ''

  while (index < source.length) {
    const current = source[index]
    const next = source[index + 1]

    if (state === 'line-comment') {
      result += current
      if (current === '\n') state = 'code'
      index++
      continue
    }

    if (state === 'block-comment') {
      result += current
      if (current === '*' && next === '/') {
        result += next
        index += 2
        state = 'code'
      } else {
        index++
      }
      continue
    }

    if (state === 'string') {
      if (current === '\\' && next !== undefined) {
        value += current + next
        index += 2
        continue
      }
      if (current === quote) {
        result += value === preservedLiteral ? `${quote}${quote}` : `${quote}${value}${quote}`
        index++
        state = 'code'
        quote = ''
        value = ''
      } else {
        value += current
        index++
      }
      continue
    }

    if (slashCommentExtensions.has(extension) && current === '/' && next === '/') {
      result += current + next
      index += 2
      state = 'line-comment'
      continue
    }
    if (blockCommentExtensions.has(extension) && current === '/' && next === '*') {
      result += current + next
      index += 2
      state = 'block-comment'
      continue
    }
    if (hashCommentExtensions.has(extension) && current === '#') {
      result += current
      index++
      state = 'line-comment'
      continue
    }
    if (current === "'" || current === '"') {
      quote = current
      index++
      state = 'string'
      continue
    }

    result += current
    index++
  }

  return state === 'string' ? result + quote + value : result
}

function stripMarkdownContractValues (source) {
  return source.replaceAll(/`([^`\n]+)`/g, (fragment, inlineCode) => {
    if (!inlineCode.includes(preservedLiteral)) return fragment

    return `\`${inlineCode.replaceAll(preservedLiteral, '')}\``
  })
}

const files = execFileSync('git', ['ls-files', '-z'], {
  cwd: root,
  encoding: 'utf8'
}).split('\0').filter(Boolean)

const violations = []
for (const file of files) {
  if (file.startsWith('openspec/changes/archive/')) continue

  const lowerPath = file.toLowerCase()
  for (const term of forbiddenTermStems.values()) {
    if (lowerPath.includes(term)) violations.push(`${file}: nome contém terminologia de transição`)
  }

  const basename = file.split('/').at(-1) || ''
  if (!textExtensions.has(extname(file).toLowerCase()) && !textBasenames.has(basename)) continue

  let source = readFileSync(resolve(root, file), 'utf8').toLowerCase()
  const extension = extname(file).toLowerCase()
  if (extension === '.md') {
    source = stripMarkdownContractValues(source)
  } else if (contractValueExtensions.has(extension)) {
    source = stripQuotedContractValues(source, extension)
  }
  for (const term of forbiddenTermStems.values()) {
    if (source.includes(term)) violations.push(`${file}: conteúdo contém terminologia de transição`)
  }
}

if (violations.length > 0) {
  console.error(violations.join('\n'))
  process.exit(1)
}

console.log(`Gate de naming passou em ${files.length} arquivos versionados ativos.`)
