import { resolve } from 'node:path'
import { defineVitestProject } from '@nuxt/test-utils/config'
import { defineConfig } from 'vitest/config'

const aliases = {
  '~': resolve(__dirname, 'app'),
  '@': resolve(__dirname, 'app')
}

const nuxtProject = await defineVitestProject({
  resolve: { alias: aliases },
  test: {
    name: 'nuxt',
    environment: 'nuxt',
    include: ['tests/unit/**/*.nuxt.{test,spec}.ts'],
    environmentOptions: {
      nuxt: {
        rootDir: resolve(__dirname, 'tests/unit/nuxt-fixture')
      }
    }
  }
})

export default defineConfig({
  test: {
    reporters: ['default'],
    projects: [
      {
        resolve: { alias: aliases },
        test: {
          name: 'node',
          environment: 'node',
          include: ['tests/unit/**/*.{test,spec}.ts'],
          exclude: ['tests/unit/**/*.nuxt.{test,spec}.ts']
        }
      },
      nuxtProject
    ]
  }
})
