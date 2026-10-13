import { defineConfig } from 'vite';
import { readFileSync } from 'node:fs';

const { version } = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8'));
if (typeof version !== 'string' || !/^\d+\.\d+\.\d+$/.test(version)) {
  throw new Error('Use a full release version in package.json, such as 0.2.0.');
}
const assetName = `reactive-v${version}`;

export default defineConfig({
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: 'manifest.json',
    lib: { entry: 'resources/js/reactive.ts', name: 'FloReactive', formats: ['iife'] },
    rolldownOptions: {
      output: { entryFileNames: `${assetName}.js`, assetFileNames: `${assetName}[extname]` },
    },
  },
});
