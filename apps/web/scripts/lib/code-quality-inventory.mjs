import { createHash } from 'node:crypto'
import {
  readFileSync,
  realpathSync,
  statSync
} from 'node:fs'
import { basename, extname, relative, resolve, sep } from 'node:path'
import { parse as parseSfc } from '@vue/compiler-sfc'
import ts from 'typescript'

export const SCOPE_COMMAND = 'git ls-files --cached --others --exclude-standard apps/api apps/web'

const EXECUTABLE_LANGUAGES = new Set(['typescript', 'javascript', 'vue'])

function sha256(value) {
  return createHash('sha256').update(value).digest('hex')
}

function orderedUnique(values) {
  return [...new Set(values)].sort((left, right) => left < right ? -1 : left > right ? 1 : 0)
}

function sanitizeMessage(value) {
  const printable = [...String(value || 'erro de parse desconhecido')]
    .map((character) => {
      const code = character.charCodeAt(0)
      return code <= 31 || code === 127 ? ' ' : character
    })
    .join('')
  return printable
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 240)
}

function languageFor(repoPath) {
  const name = basename(repoPath).toLowerCase()
  const extension = extname(repoPath).toLowerCase()
  if (name === 'pnpm-lock.yaml') return 'yaml'
  if (name.endsWith('.d.ts')) return 'typescript'

  return {
    '.ts': 'typescript',
    '.tsx': 'typescript',
    '.js': 'javascript',
    '.mjs': 'javascript',
    '.cjs': 'javascript',
    '.vue': 'vue',
    '.json': 'json',
    '.yaml': 'yaml',
    '.yml': 'yaml',
    '.xml': 'xml',
    '.css': 'css',
    '.md': 'markdown',
    '.txt': 'text',
    '.lock': 'text',
    '.png': 'image',
    '.jpg': 'image',
    '.jpeg': 'image',
    '.gif': 'image',
    '.webp': 'image',
    '.ico': 'image',
    '.svg': 'image',
    '.woff': 'image',
    '.woff2': 'image',
    '.sh': 'shell'
  }[extension] || 'other'
}

function categoryFor(repoPath) {
  const lower = repoPath.toLowerCase()
  const name = basename(lower)
  if (repoPath.startsWith('apps/web/app/')) return 'application'
  if (repoPath.startsWith('apps/web/server/')) return 'server'
  if (repoPath.startsWith('apps/web/scripts/')) return 'script'
  if (lower.includes('/fixtures/')) return 'fixture'
  if (repoPath.startsWith('apps/web/tests/')) return 'test'
  if (repoPath.startsWith('apps/web/public/')) return 'public-asset'
  if (['package.json', 'pnpm-lock.yaml'].includes(name)) return 'dependency-manifest'
  if (/^(nuxt|eslint|vitest|playwright|tailwind)\.config\./.test(name)) return 'configuration'
  if (['tsconfig.json', 'app.config.ts'].includes(name)) return 'configuration'
  if (['.gitignore', '.env.example', 'dockerfile'].includes(name)) return 'control'
  if (lower.endsWith('.md')) return 'documentation'
  return 'other'
}

function scriptKind(repoPath, language) {
  const lower = repoPath.toLowerCase()
  if (lower.endsWith('.tsx')) return ts.ScriptKind.TSX
  if (lower.endsWith('.jsx')) return ts.ScriptKind.JSX
  if (language === 'javascript') return ts.ScriptKind.JS
  return ts.ScriptKind.TS
}

function lineCount(source) {
  if (source.length === 0) return 0
  return source.split('\n').length - (source.endsWith('\n') ? 1 : 0)
}

function lineOffsetAt(source, offset) {
  let lines = 0
  for (let index = 0; index < offset; index += 1) {
    if (source.charCodeAt(index) === 10) lines += 1
  }
  return lines
}

function flattenDiagnostic(diagnostic, sourceFile, lineOffset, language) {
  const line = typeof diagnostic.start === 'number'
    ? sourceFile.getLineAndCharacterOfPosition(diagnostic.start).line + lineOffset + 1
    : null
  return {
    language,
    line,
    message: sanitizeMessage(ts.flattenDiagnosticMessageText(diagnostic.messageText, ' '))
  }
}

function parseScript(source, repoPath, language, lineOffset = 0) {
  const sourceFile = ts.createSourceFile(
    repoPath,
    source,
    ts.ScriptTarget.Latest,
    true,
    scriptKind(repoPath, language)
  )
  const parseErrors = [...(sourceFile.parseDiagnostics || [])]
    .map(diagnostic => flattenDiagnostic(diagnostic, sourceFile, lineOffset, language))
  return { source, sourceFile, lineOffset, language, parseErrors }
}

function importSpecifiers(unit) {
  const imports = new Set()
  const visit = (node) => {
    if (ts.isImportDeclaration(node) && ts.isStringLiteral(node.moduleSpecifier)) {
      imports.add(node.moduleSpecifier.text)
    } else if (
      ts.isImportEqualsDeclaration(node)
      && ts.isExternalModuleReference(node.moduleReference)
      && node.moduleReference.expression
      && ts.isStringLiteral(node.moduleReference.expression)
    ) {
      imports.add(node.moduleReference.expression.text)
    } else if (
      ts.isCallExpression(node)
      && (node.expression.kind === ts.SyntaxKind.ImportKeyword
        || (ts.isIdentifier(node.expression) && node.expression.text === 'require'))
      && node.arguments.length > 0
      && ts.isStringLiteralLike(node.arguments[0])
    ) {
      imports.add(node.arguments[0].text)
    }
    ts.forEachChild(node, visit)
  }
  visit(unit.sourceFile)
  return imports
}

function declarationKind(node) {
  if (ts.isClassDeclaration(node) || ts.isClassExpression(node)) return 'class'
  if (ts.isInterfaceDeclaration(node)) return 'interface'
  if (ts.isEnumDeclaration(node)) return 'enum'
  if (
    ts.isMethodDeclaration(node)
    || ts.isMethodSignature(node)
    || ts.isGetAccessorDeclaration(node)
    || ts.isSetAccessorDeclaration(node)
    || ts.isConstructorDeclaration(node)
  ) return 'method'
  if (ts.isFunctionDeclaration(node)) return 'function'
  if (ts.isFunctionExpression(node)) return 'closure'
  if (ts.isArrowFunction(node)) return 'arrow-function'
  return null
}

function nameText(name, sourceFile) {
  if (!name) return null
  if (ts.isIdentifier(name) || ts.isPrivateIdentifier(name) || ts.isStringLiteralLike(name)) return name.text
  return name.getText(sourceFile)
}

function inferredName(node, sourceFile) {
  const ownName = nameText(node.name, sourceFile)
  if (ownName) return ownName
  if (ts.isConstructorDeclaration(node)) return 'constructor'

  const parent = node.parent
  if (ts.isVariableDeclaration(parent)) return nameText(parent.name, sourceFile)
  if (ts.isPropertyDeclaration(parent) || ts.isPropertyAssignment(parent)) return nameText(parent.name, sourceFile)
  if (
    ts.isBinaryExpression(parent)
    && parent.operatorToken.kind === ts.SyntaxKind.EqualsToken
  ) return parent.left.getText(sourceFile)
  return null
}

function parametersFor(node, sourceFile) {
  if (!('parameters' in node) || !node.parameters) return []
  return node.parameters.map(parameter => ({
    name: parameter.name.getText(sourceFile),
    type: parameter.type?.getText(sourceFile) || null,
    optional: Boolean(parameter.questionToken || parameter.initializer),
    variadic: Boolean(parameter.dotDotDotToken),
    byReference: false
  }))
}

function isDeclarationBoundary(node) {
  return declarationKind(node) !== null
}

function branchWeight(node) {
  if (
    ts.isIfStatement(node)
    || ts.isForStatement(node)
    || ts.isForInStatement(node)
    || ts.isForOfStatement(node)
    || ts.isWhileStatement(node)
    || ts.isDoStatement(node)
    || ts.isConditionalExpression(node)
    || ts.isCatchClause(node)
    || ts.isCaseClause(node)
  ) return 1
  if (
    ts.isBinaryExpression(node)
    && [
      ts.SyntaxKind.AmpersandAmpersandToken,
      ts.SyntaxKind.BarBarToken,
      ts.SyntaxKind.QuestionQuestionToken
    ].includes(node.operatorToken.kind)
  ) return 1
  return 0
}

function branchMetrics(root) {
  let branches = 0
  let maxDepth = 0

  const visit = (node, depth, isRoot = false) => {
    if (!isRoot && isDeclarationBoundary(node)) return
    const weight = branchWeight(node)
    const nextDepth = weight > 0 ? depth + 1 : depth
    branches += weight
    maxDepth = Math.max(maxDepth, nextDepth)
    ts.forEachChild(node, child => visit(child, nextDepth))
  }
  visit(root, 0, true)
  return { branches, maxDepth }
}

function tokenCount(source, language) {
  const scanner = ts.createScanner(
    ts.ScriptTarget.Latest,
    true,
    language === 'javascript' ? ts.LanguageVariant.Standard : ts.LanguageVariant.Standard,
    source
  )
  let count = 0
  while (scanner.scan() !== ts.SyntaxKind.EndOfFileToken) count += 1
  return count
}

function symbolFingerprint(node, sourceFile) {
  const normalized = node.getText(sourceFile).replace(/\s+/g, ' ').trim()
  return sha256(`${ts.SyntaxKind[node.kind]}:${normalized}`)
}

function symbolsForUnit(unit, repoPath, importFanOut) {
  const symbols = []
  const stack = []
  let anonymousSequence = 0

  const visit = (node) => {
    const kind = declarationKind(node)
    if (!kind) {
      ts.forEachChild(node, visit)
      return
    }

    let displayName = inferredName(node, unit.sourceFile)
    if (!displayName) {
      anonymousSequence += 1
      displayName = `${kind}#${anonymousSequence}`
    }
    const parent = stack.at(-1) || null
    const qualifiedName = parent
      ? `${parent.qualifiedName}${kind === 'method' && ['class', 'interface'].includes(parent.kind) ? '::' : '.<locals>.'}${displayName}`
      : displayName
    const start = node.getStart(unit.sourceFile, false)
    const end = node.end
    const startLine = unit.sourceFile.getLineAndCharacterOfPosition(start).line + unit.lineOffset + 1
    const endLine = unit.sourceFile.getLineAndCharacterOfPosition(Math.max(start, end - 1)).line + unit.lineOffset + 1
    const parameters = parametersFor(node, unit.sourceFile)
    const branch = branchMetrics(node)
    const id = `${repoPath}::${qualifiedName}@${startLine}`
    const symbol = {
      id,
      path: repoPath,
      qualifiedName,
      displayName,
      parentId: parent?.id || null,
      kind,
      language: unit.language,
      startLine,
      endLine,
      parameters,
      metrics: {
        lines: endLine - startLine + 1,
        branches: branch.branches,
        complexity: branch.branches + 1,
        maxDepth: branch.maxDepth,
        parameterCount: parameters.length,
        importFanOut,
        tokenCount: tokenCount(node.getText(unit.sourceFile), unit.language)
      },
      fingerprint: symbolFingerprint(node, unit.sourceFile)
    }
    symbols.push(symbol)
    stack.push({ id, qualifiedName, kind })
    try {
      ts.forEachChild(node, visit)
    } finally {
      stack.pop()
    }
  }

  visit(unit.sourceFile)
  return symbols
}

function sfcParseError(error) {
  const line = error && typeof error === 'object' && 'loc' in error
    ? error.loc?.start?.line || error.loc?.line || null
    : null
  const message = error instanceof Error
    ? error.message
    : error && typeof error === 'object' && 'message' in error
      ? error.message
      : error
  return { language: 'vue', line, message: sanitizeMessage(message) }
}

export function collectWebSource(source, repoPath) {
  const language = languageFor(repoPath)
  if (!EXECUTABLE_LANGUAGES.has(language)) return { symbols: [], parseErrors: [] }

  let units
  if (language === 'vue') {
    const parsed = parseSfc(source, { filename: repoPath, sourceMap: false })
    if (parsed.errors.length > 0) {
      return { symbols: [], parseErrors: parsed.errors.map(sfcParseError) }
    }
    units = [parsed.descriptor.script, parsed.descriptor.scriptSetup]
      .filter(Boolean)
      .map(block => parseScript(
        block.content,
        repoPath,
        'vue',
        lineOffsetAt(source, block.loc.start.offset)
      ))
  } else {
    units = [parseScript(source, repoPath, language)]
  }

  const parseErrors = units.flatMap(unit => unit.parseErrors)
  if (parseErrors.length > 0) return { symbols: [], parseErrors }

  const imports = new Set(units.flatMap(unit => [...importSpecifiers(unit)]))
  const symbols = units.flatMap(unit => symbolsForUnit(unit, repoPath, imports.size))
    .sort((left, right) => left.id < right.id ? -1 : left.id > right.id ? 1 : 0)
  return { symbols, parseErrors: [] }
}

function countsBy(rows, key) {
  const counts = new Map()
  for (const row of rows) counts.set(row[key], (counts.get(row[key]) || 0) + 1)
  return Object.fromEntries(
    [...counts.entries()].sort(([left], [right]) => left < right ? -1 : left > right ? 1 : 0)
  )
}

function inventorySummary(files, symbols) {
  return {
    files: files.length,
    symbols: symbols.length,
    executableFiles: files.filter(file => file.executable).length,
    parseErrors: files.reduce((total, file) => total + file.parseErrors.length, 0),
    byApp: { web: files.length },
    byCategory: countsBy(files, 'category'),
    byLanguage: countsBy(files, 'language'),
    bySymbolKind: countsBy(symbols, 'kind')
  }
}

export function buildWebInventory(webRoot, repoPaths) {
  const root = realpathSync(webRoot)
  const rootPrefix = root.endsWith(sep) ? root : `${root}${sep}`
  const paths = orderedUnique(repoPaths.map(path => path.trim()).filter(Boolean))
  const files = []
  const symbols = []

  for (const repoPath of paths) {
    if (!repoPath.startsWith('apps/web/')) throw new Error(`Path fora de apps/web: ${repoPath}`)
    const relativePath = repoPath.slice('apps/web/'.length)
    const absolute = realpathSync(resolve(root, relativePath))
    if (!absolute.startsWith(rootPrefix) || !statSync(absolute).isFile()) {
      throw new Error(`Arquivo do inventário fora da raiz: ${repoPath}`)
    }
    const contents = readFileSync(absolute)
    const source = contents.toString('utf8')
    const language = languageFor(repoPath)
    const parsed = collectWebSource(source, repoPath)
    symbols.push(...parsed.symbols)
    files.push({
      path: repoPath,
      app: 'web',
      category: categoryFor(repoPath),
      language,
      bytes: contents.byteLength,
      lines: language === 'image' ? 0 : lineCount(source),
      sha256: sha256(contents),
      executable: EXECUTABLE_LANGUAGES.has(language),
      symbolCount: parsed.symbols.length,
      parseErrors: parsed.parseErrors
    })
  }

  symbols.sort((left, right) => left.id < right.id ? -1 : left.id > right.id ? 1 : 0)
  const summary = inventorySummary(files, symbols)
  const core = {
    schemaVersion: 1,
    scope: {
      command: SCOPE_COMMAND,
      roots: ['apps/api', 'apps/web'],
      excludedByGitIgnore: true
    },
    summary,
    files,
    symbols
  }
  return {
    schemaVersion: 1,
    scope: core.scope,
    digest: sha256(JSON.stringify(core)),
    summary,
    files,
    symbols
  }
}

function rowsBy(rows, key) {
  return new Map((Array.isArray(rows) ? rows : [])
    .filter(row => row && typeof row === 'object' && typeof row[key] === 'string')
    .map(row => [row[key], row]))
}

function missingKeys(left, right) {
  return [...left.keys()]
    .filter(key => !right.has(key))
    .sort((first, second) => first < second ? -1 : first > second ? 1 : 0)
}

function changedKeys(expected, current, fields) {
  return [...expected.keys()]
    .filter((key) => {
      if (!current.has(key)) return false
      return fields.some(field => expected.get(key)[field] !== current.get(key)[field])
    })
    .sort((first, second) => first < second ? -1 : first > second ? 1 : 0)
}

export function inventoryDrift(expected, current) {
  const expectedFiles = rowsBy(expected.files, 'path')
  const currentFiles = rowsBy(current.files, 'path')
  const expectedSymbols = rowsBy(expected.symbols, 'id')
  const currentSymbols = rowsBy(current.symbols, 'id')

  return {
    missingFiles: missingKeys(expectedFiles, currentFiles),
    unexpectedFiles: missingKeys(currentFiles, expectedFiles),
    changedFiles: changedKeys(expectedFiles, currentFiles, ['sha256', 'category', 'language']),
    missingSymbols: missingKeys(expectedSymbols, currentSymbols),
    unexpectedSymbols: missingKeys(currentSymbols, expectedSymbols),
    changedSymbols: changedKeys(expectedSymbols, currentSymbols, ['fingerprint', 'path', 'qualifiedName', 'kind']),
    scopeChanged: JSON.stringify(expected.scope) !== JSON.stringify(current.scope)
  }
}

export function hasInventoryDrift(drift) {
  return Object.values(drift).some(value => value === true || (Array.isArray(value) && value.length > 0))
}

export function repoPathForWebFile(webRoot, absolutePath) {
  return `apps/web/${relative(realpathSync(webRoot), realpathSync(absolutePath)).split(sep).join('/')}`
}
