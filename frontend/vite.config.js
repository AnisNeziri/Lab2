import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Optional local engineering split: inference waits do not occupy the ERP worker.
// Absent the process-only override, the established single backend is unchanged.
const proxy = {
  ...(process.env.VITE_ASSISTANT_PROXY_TARGET ? {'/api/intelligence-assistant':{target:process.env.VITE_ASSISTANT_PROXY_TARGET,changeOrigin:true}} : {}),
  '/api':{target:process.env.VITE_API_PROXY_TARGET||'http://localhost:8000',changeOrigin:true},
}

export default defineConfig({
  plugins: [react()],
  optimizeDeps: { include: ['pdfjs-dist/build/pdf.mjs'] },
  preview: { proxy },
  server: {
    port: 5173,
    proxy,
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          const module = id.replaceAll('\\', '/')
          if (/\/node_modules\/(react|react-dom|react-router-dom|react-router|scheduler)\//.test(module)) return 'vendor'
          if (module.includes('/node_modules/recharts/')) return 'charts'
          if (/\/node_modules\/(laravel-echo|pusher-js)\//.test(module)) return 'realtime'
          if (module.includes('/node_modules/zustand/')) return 'state'
          if (module.endsWith('/three/build/three.core.js')) return 'three-core'
          if (module.endsWith('/three/build/three.module.js')) return 'three-renderer'
        },
      },
    },
  },
})
