import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// app.lvh.me serves this SPA at its root, so base '/'. Build to ./dist, which
// the app service serves (with SPA fallback to index.html).
export default defineConfig({
  plugins: [react()],
  base: '/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
})
