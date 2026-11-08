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
  optimizeDeps: { include: ['pdfjs-dist/legacy/build/pdf.mjs'] },
  preview: { proxy },
  server: {
    port: 5173,
    proxy,
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['react', 'react-dom', 'react-router-dom'],
          charts: ['recharts'],
          realtime: ['laravel-echo', 'pusher-js'],
          state: ['zustand'],
        },
      },
    },
  },
})
