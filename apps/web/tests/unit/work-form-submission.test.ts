import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { createWorkFormSubmissionGuard } from '../../app/utils/work-form-submission'

function deferred(): { promise: Promise<void>, resolve: () => void } {
  let resolve!: () => void
  const promise = new Promise<void>((done) => {
    resolve = done
  })
  return { promise, resolve }
}

describe.each(['mutação da rotina', 'preview da geração'])(
  'work form submission guard — %s',
  () => {
    it('aceita um único requestSubmit e uma única chamada enquanto a promessa está pendente', async () => {
      const locked = ref(false)
      const busy = ref(false)
      const guard = createWorkFormSubmissionGuard(locked, busy)
      const form = { requestSubmit: vi.fn() } as unknown as HTMLFormElement

      expect(guard.requestSubmit(form)).toBe(true)
      expect(guard.requestSubmit(form)).toBe(false)
      expect(form.requestSubmit).toHaveBeenCalledTimes(1)

      const pending = deferred()
      const action = vi.fn(() => pending.promise)
      const first = guard.submit(action)
      const second = guard.submit(action)

      expect(action).toHaveBeenCalledTimes(1)
      expect(locked.value).toBe(true)
      expect(busy.value).toBe(true)

      pending.resolve()
      await expect(first).resolves.toBe(true)
      await expect(second).resolves.toBe(false)
      expect(locked.value).toBe(false)
      expect(busy.value).toBe(false)
    })
  }
)

describe('work form submission guard — validação', () => {
  it('libera o lock quando o UForm emite erro antes da mutação', () => {
    const locked = ref(false)
    const busy = ref(false)
    const guard = createWorkFormSubmissionGuard(locked, busy)
    const form = { requestSubmit: vi.fn() } as unknown as HTMLFormElement

    guard.requestSubmit(form)
    guard.validationError()

    expect(locked.value).toBe(false)
    expect(guard.requestSubmit(form)).toBe(true)
    expect(form.requestSubmit).toHaveBeenCalledTimes(2)
  })
})
