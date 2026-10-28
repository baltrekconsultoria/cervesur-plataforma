// ESLint 10 · Flat Config. Tres entornos: navegador (src), pruebas (Vitest) y scripts Node (e2e, qa, configuración).
import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';
import tseslint from 'typescript-eslint';

const TS_FILES = ['src/**/*.{ts,tsx}', 'vite.config.ts'];
const TEST_FILES = ['src/**/*.test.{ts,tsx}', 'src/test/**/*.ts'];

export default tseslint.config(
  // Artefactos generados, dependencias, copias de respaldo/referencia y capturas.
  { ignores: ['dist/**', 'dist-qa/**', 'dist-qa-real/**', 'node_modules/**', 'coverage/**', '_referencia-UX-05.1/**', '_respaldo*/**', 'docs/**', 'e2e/.tmp/**', 'qa/.runtime/**'] },
  { linterOptions: { reportUnusedDisableDirectives: 'error' } },

  js.configs.recommended,

  // TypeScript con información de tipos (permite no-floating-promises, no-misused-promises, etc.).
  {
    files: TS_FILES,
    extends: [...tseslint.configs.recommendedTypeChecked],
    languageOptions: { parserOptions: { projectService: true, tsconfigRootDir: import.meta.dirname } },
    rules: {
      '@typescript-eslint/consistent-type-imports': ['error', { prefer: 'type-imports', fixStyle: 'separate-type-imports' }],
      '@typescript-eslint/no-explicit-any': 'error',
      '@typescript-eslint/no-floating-promises': 'error'
    }
  },

  // Código del navegador + React Hooks.
  {
    files: ['src/**/*.{ts,tsx}'],
    ignores: TEST_FILES,
    extends: [reactHooks.configs.flat.recommended],
    languageOptions: { globals: globals.browser },
    rules: {
      // La política de UX-05/06: el token y los datos de sesión viven solo en memoria.
      'no-restricted-globals': ['error',
        { name: 'localStorage', message: 'No se persisten datos de sesión en el navegador.' },
        { name: 'sessionStorage', message: 'No se persisten datos de sesión en el navegador.' },
        { name: 'indexedDB', message: 'No se persisten datos de sesión en el navegador.' }],
      'no-restricted-properties': ['error',
        { object: 'window', property: 'localStorage', message: 'No se persisten datos de sesión en el navegador.' },
        { object: 'window', property: 'sessionStorage', message: 'No se persisten datos de sesión en el navegador.' },
        { object: 'document', property: 'cookie', message: 'No se usan cookies en el cliente.' }],
      'no-restricted-syntax': ['error', { selector: "CallExpression[callee.name='eval']", message: 'eval no está permitido.' }],
      'no-console': 'error'
    }
  },

  // Pruebas (Vitest + jsdom): entorno de navegador y Node (lectura de archivos en las pruebas de higiene).
  {
    files: TEST_FILES,
    languageOptions: { globals: { ...globals.browser, ...globals.node } },
    rules: {
      // Dobles de prueba: funciones `async` que solo devuelven un valor para cumplir la interfaz de promesas.
      '@typescript-eslint/require-await': 'off'
    }
  },

  // Proveedor simulado: sus métodos son `async` a propósito (simulan E/S y convierten cada `throw` en un rechazo,
  // igual que el proveedor HTTP real). Regla desactivada solo en este directorio.
  {
    files: ['src/api/mock/**/*.ts'],
    rules: { '@typescript-eslint/require-await': 'off' }
  },

  // Configuración y scripts de Node (no se empaquetan en la aplicación).
  {
    files: ['vite.config.ts', 'eslint.config.js', 'e2e/**/*.mjs', 'qa/**/*.mjs', 'scripts/**/*.mjs'],
    languageOptions: { globals: globals.node },
    rules: { 'no-console': 'off' }
  },
  {
    files: ['e2e/**/*.mjs', 'qa/**/*.mjs', 'scripts/**/*.mjs', 'eslint.config.js'],
    languageOptions: { sourceType: 'module', ecmaVersion: 'latest' }
  }
);
