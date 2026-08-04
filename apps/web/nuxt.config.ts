import { sanctumClientConfig } from './config/sanctum'

// https://nuxt.com/docs/api/configuration/nuxt-config
const useSanctumProxy = process.env.NUXT_SANCTUM_PROXY === 'true'
const apiBase = useSanctumProxy ? '/api/sanctum' : (process.env.NUXT_PUBLIC_API_BASE || '')
// Compose define CHOKIDAR_USEPOLLING=true; em bind-mount Docker o inotify do host
// frequentemente não chega no container — polling é necessário para HMR.
const usePolling = process.env.CHOKIDAR_USEPOLLING !== 'false'
const frontendDevPort = Number(process.env.FRONTEND_DEV_PORT || 3000)
const nitroOutputDir = process.env.NITRO_OUTPUT_DIR
const enableNuxtUiFonts = process.env.NUXT_UI_FONTS !== 'false'
// Host público para HMR/WS (IP ou domínio). Se vazio, o client usa window.location.hostname
// (necessário p/ acesso remoto). Nunca forçar "localhost" em dev exposto por IP.
const hmrHost = process.env.NUXT_DEV_HMR_HOST || process.env.PUBLIC_HOST || ''

function viteHmrConfig(): Record<string, unknown> {
  // port + clientPort iguais à porta publicada (3000). Sem isso o Vite gera
  // fallback directSocketHost em :5173, que não existe no host e quebra o remote.
  const hmr: Record<string, unknown> = {
    protocol: 'ws',
    port: frontendDevPort,
    clientPort: frontendDevPort
  }
  if (hmrHost) {
    hmr.host = hmrHost
  }
  return hmr
}

export default defineNuxtConfig({

  modules: [
    '@nuxt/eslint',
    '@nuxt/ui',
    '@vueuse/nuxt',
    'nuxt-auth-sanctum',
    '@vite-pwa/nuxt',
    '@vue-dnd-kit/nuxt'
  ],
  ssr: false,

  // DevTools + seletor de componentes (Shift+Alt+D). Opt-out: NUXT_DEVTOOLS=false
  devtools: {
    enabled: process.env.NUXT_DEVTOOLS !== 'false'
  },

  css: ['~/assets/css/main.css'],
  ui: {
    fonts: enableNuxtUiFonts
  },

  runtimeConfig: {
    public: {
      apiBase,
      communicationEnabled: process.env.NUXT_PUBLIC_COMMUNICATION_ENABLED === 'true',
      reverb: {
        key: process.env.NUXT_PUBLIC_REVERB_APP_KEY || 'communication-disabled',
        host: process.env.NUXT_PUBLIC_REVERB_HOST || '',
        port: Number(process.env.NUXT_PUBLIC_REVERB_PORT || 0),
        scheme: process.env.NUXT_PUBLIC_REVERB_SCHEME || 'https'
      }
    }
  },

  // Watcher do Nuxt (rotas, plugins, reinício de config)
  watchers: {
    chokidar: {
      usePolling,
      interval: 300
    }
  },

  compatibilityDate: '2026-06-30',

  nitro: {
    preset: 'static',
    ...(nitroOutputDir ? { output: { dir: nitroOutputDir } } : {})
  },

  vite: {
    optimizeDeps: {
      include: [
        '@unovis/ts',
        '@unovis/vue',
        '@vue-flow/core',
        'date-fns',
        'date-fns/locale',
        'zod'
      ]
    },
    server: {
      // Vite 6+: evita "Blocked request. This host is not allowed" ao abrir por IP
      allowedHosts: true,
      // URL pública vista pelo browser (assets/HMR). Ex.: http://IP:3000
      origin: hmrHost ? `http://${hmrHost}:${frontendDevPort}` : undefined,
      watch: {
        usePolling,
        interval: 300
      },
      hmr: viteHmrConfig()
    }
  },

  // Watcher do Vite (HMR de .vue/.ts/.css) + WebSocket no host.
  // Hook garante merge: só `vite.server.watch` às vezes é sobrescrito pelo Nuxt.
  hooks: {
    'pages:extend'(pages) {
      const healthPage = pages.find(page => page.path === '/health')
      if (!healthPage) return
      pages.push({
        name: 'health-type',
        path: '/health/type/:type',
        file: healthPage.file
      })
    },
    'vite:extendConfig'(config) {
      const server = (config.server ?? {}) as NonNullable<typeof config.server> & {
        watch?: Record<string, unknown>
        hmr?: boolean | Record<string, unknown>
        host?: string | boolean
        allowedHosts?: boolean | string[]
      }
      server.host = '0.0.0.0'
      server.allowedHosts = true
      if (hmrHost) {
        server.origin = `http://${hmrHost}:${frontendDevPort}`
      }
      server.watch = {
        ...(typeof server.watch === 'object' && server.watch ? server.watch : {}),
        usePolling,
        interval: 300
      }
      server.hmr = {
        ...(typeof server.hmr === 'object' && server.hmr ? server.hmr : {}),
        ...viteHmrConfig()
      }
      Object.assign(config, { server })
    }
  },

  eslint: {
    config: {
      stylistic: {
        commaDangle: 'never',
        braceStyle: '1tbs'
      }
    }
  },

  icon: {
    clientBundle: {
      // A SPA estática não deve depender da API pública do Iconify para ações essenciais.
      scan: true,
      icons: [
        'lucide:alarm-clock',
        'lucide:check',
        'lucide:circle-check',
        'lucide:circle-fading-arrow-up',
        'lucide:clock-3',
        'lucide:ellipsis-vertical',
        'lucide:list-filter',
        'lucide:list-tree',
        'lucide:list-x',
        'lucide:loader-circle',
        'lucide:mail',
        'lucide:mail-check',
        'lucide:message-circle-plus',
        'lucide:panel-right-close',
        'lucide:panel-right-open',
        'lucide:radio',
        'lucide:refresh-cw',
        'lucide:rotate-ccw',
        'lucide:settings-2',
        'lucide:sliders-horizontal',
        'lucide:sunrise',
        'lucide:tags',
        'lucide:user-round-check',
        'lucide:user-round-x',
        'lucide:wifi-off'
      ]
    }
  },

  /**
   * PWA — app instalável no navegador (Chrome/Edge/Android “Instalar app”).
   * Requisito: contexto seguro (HTTPS ou localhost). HTTP em IP público NÃO
   * libera o prompt de instalação.
   * @see https://vite-pwa-org.netlify.app/frameworks/nuxt
   */
  pwa: {
    registerType: 'autoUpdate',
    includeAssets: ['favicon.ico', 'apple-touch-icon.png'],
    manifest: {
      id: '/',
      name: 'KontiveHub',
      short_name: 'KontiveHub',
      description: 'Gestão fiscal, documentos e trabalho para escritórios contábeis',
      theme_color: '#00C16A',
      background_color: '#09090b',
      display: 'standalone',
      orientation: 'any',
      lang: 'pt-BR',
      dir: 'ltr',
      start_url: '/',
      scope: '/',
      categories: ['business', 'finance', 'productivity'],
      icons: [
        {
          src: 'pwa-192x192.png',
          sizes: '192x192',
          type: 'image/png'
        },
        {
          src: 'pwa-512x512.png',
          sizes: '512x512',
          type: 'image/png'
        },
        {
          src: 'pwa-512x512.png',
          sizes: '512x512',
          type: 'image/png',
          purpose: 'maskable'
        }
      ]
    },
    workbox: {
      navigateFallback: '/',
      globPatterns: ['**/*.{js,css,html,png,svg,ico,woff,woff2,webp}'],
      runtimeCaching: [
        {
          // RegExp é serializável pelo generateSW. Callbacks definidos no
          // nuxt.config eram emitidos como `[native code]` e quebravam o SW.
          urlPattern: /\/(?:api|sanctum)\//,
          handler: 'NetworkOnly' as const
        },
        {
          urlPattern: /\/(?:login|logout)(?:\/|$)|\/user\//,
          handler: 'NetworkOnly' as const
        }
      ]
    },
    client: {
      installPrompt: true,
      periodicSyncForUpdates: 3600
    },
    devOptions: {
      // Em localhost permite testar SW/manifest; em IP HTTP o Chrome ainda bloqueia install.
      enabled: process.env.NUXT_PWA_DEV === 'true',
      type: 'module'
    }
  },

  sanctum: {
    baseUrl: apiBase,
    mode: 'cookie',
    // O middleware global já decide quando consultar /me. A consulta automática
    // do módulo roda antes da rota e gera um 401 esperado nas páginas públicas.
    client: sanctumClientConfig,
    endpoints: {
      csrf: '/sanctum/csrf-cookie',
      login: '/login',
      logout: '/logout',
      user: '/api/v1/me'
    },
    redirect: {
      onLogin: false,
      onLogout: '/login',
      onAuthOnly: '/login',
      onGuestOnly: '/'
    },
    globalMiddleware: {
      enabled: false
    },
    serverProxy: {
      enabled: useSanctumProxy,
      route: '/api/sanctum',
      baseUrl: process.env.NUXT_SANCTUM_PROXY_BASE || 'http://localhost:8080'
    }
  }
})
