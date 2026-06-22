import { resolve } from 'path'
import { defineConfig, externalizeDepsPlugin } from 'electron-vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  main: {
    plugins: [externalizeDepsPlugin()]
  },
  preload: {
    plugins: [externalizeDepsPlugin()]
  },
  renderer: {
    resolve: {
      alias: {
        '@': resolve('src/renderer/src')
      }
    },
    plugins: [react()],
    server: {
      proxy: {
        '/api': {
          target: 'https://tessa.innovfix.ai',
          changeOrigin: true,
          secure: true,
          cookieDomainRewrite: 'localhost'
        },
        '/__portal': {
          target: 'https://tessa.innovfix.ai',
          changeOrigin: true,
          secure: true,
          cookieDomainRewrite: 'localhost',
          rewrite: (path) => path.replace(/^\/__portal/, '')
        }
      }
    }
  }
})
