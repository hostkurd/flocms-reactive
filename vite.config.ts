import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    outDir: 'dist',
    manifest: 'manifest.json',
    lib: { entry: 'resources/js/reactive.ts', name: 'FloReactive', formats: ['iife'] },
    rolldownOptions: {
      output: { entryFileNames: 'reactive-[hash].js', assetFileNames: 'reactive-[hash][extname]' },
    },
  },
});
