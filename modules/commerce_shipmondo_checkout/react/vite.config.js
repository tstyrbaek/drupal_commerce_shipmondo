import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import cssInjectedByJsPlugin from 'vite-plugin-css-injected-by-js';
import { resolve } from 'path';

const sharedConfig = {
  plugins: [react()],
  resolve: {
    alias: {
      '@': resolve(__dirname, 'src'),
    },
  },
};

const libEntry = resolve(__dirname, 'src/index.js');

export default defineConfig(({ mode }) => {
  const isUmd = mode === 'umd';

  return {
    ...sharedConfig,
    define: isUmd
      ? {
          'process.env.NODE_ENV': JSON.stringify('production'),
        }
      : undefined,
    plugins: [
      ...sharedConfig.plugins,
      isUmd && cssInjectedByJsPlugin(),
    ].filter(Boolean),
    build: {
      outDir: 'dist',
      emptyOutDir: !isUmd,
      lib: {
        entry: libEntry,
        name: 'ShipmondoServicePointSelector',
        formats: isUmd ? ['umd'] : ['es'],
        fileName: (format) =>
          format === 'umd'
            ? 'service-point-selector.umd.js'
            : 'service-point-selector.esm.js',
      },
      rollupOptions: isUmd
        ? {
            output: {
              exports: 'named',
              inlineDynamicImports: true,
            },
          }
        : {
            external: ['react', 'react-dom', 'react/jsx-runtime'],
            output: {
              exports: 'named',
            },
          },
    },
  };
});
