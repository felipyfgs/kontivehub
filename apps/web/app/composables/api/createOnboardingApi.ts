import type { ApiClient } from './types'
import type {
  CompleteInitialOnboardingBody,
  CompleteInitialOnboardingResult,
  OnboardingStatusResult
} from '~/types/api'

export function createOnboardingApi(client: ApiClient) {
  return {
    onboarding: {
      status: () =>
        client<{ data: OnboardingStatusResult }>('/api/v1/onboarding/status'),
      complete: (body: CompleteInitialOnboardingBody) =>
        client<{ data: CompleteInitialOnboardingResult }>('/api/v1/onboarding', {
          method: 'POST',
          body
        })
    }
  }
}
