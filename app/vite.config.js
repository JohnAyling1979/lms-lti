import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Dev: `npm run dev` serves the app with HMR (the `app` container runs this).
// Prod: `npm run build` emits ./dist, which CI uploads to the GCS bucket behind
// the load balancer — nothing here serves static files.
export default defineConfig({
  plugins: [react()],
  base: '/',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
  server: {
    host: '0.0.0.0', // reachable by Caddy inside the container
    port: 80,
    allowedHosts: ['app.lvh.me'], // Vite blocks unknown hosts by default
    // HMR websocket connects back through Caddy's TLS on 443
    hmr: { host: 'app.lvh.me', clientPort: 443, protocol: 'wss' },
  },
})
