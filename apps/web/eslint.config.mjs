// @ts-check
import withNuxt from './.nuxt/eslint.config.mjs'

export default withNuxt(
  {
    ignores: [
      '.nuxt-*/**',
      '.tmp-*',
      'app/types/generated/**',
      '.playwright-cli/**',
      'playwright-report/**',
      'test-results/**'
    ]
  },
  {
    rules: {
      'vue/no-multiple-template-root': 'off',
      'vue/max-attributes-per-line': ['error', { singleline: 3 }]
    }
  }
)
