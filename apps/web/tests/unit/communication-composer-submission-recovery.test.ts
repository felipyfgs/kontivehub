import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

describe('recuperação de submissão do composer', () => {
  it('restaura chave sem undefined e debounce contatos com guard de epoch/contexto', () => {
    const composer = readFileSync(
      resolve(process.cwd(), 'app/components/communication/Composer.vue'),
      'utf8'
    )

    expect(composer).not.toContain('stored.idempotencyKey!')
    expect(composer).toContain('stored.idempotencyKey || createComposerBatchSubmissionKeys().idempotencyKey')
    expect(composer).toContain('contactsRequestEpoch')
    expect(composer).toContain('contactsRequestTimer')
    expect(composer).toContain('scheduleContactsLoad')
    expect(composer).toContain('epoch !== contactsRequestEpoch')
    expect(composer).toContain('props.inboxId !== inboxId')
    expect(composer).toContain('@update:model-value="scheduleContactsLoad"')
  })
})
