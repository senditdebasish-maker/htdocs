// ESLint flat config (v9). Pragmatic, security-conscious defaults.
module.exports = [
  {
    ignores: ['node_modules/**', 'data/**', 'coverage/**', 'public/app.css'],
  },
  {
    files: ['**/*.js'],
    languageOptions: {
      ecmaVersion: 2022,
      sourceType: 'commonjs',
      globals: {
        require: 'readonly',
        module: 'readonly',
        exports: 'readonly',
        __dirname: 'readonly',
        process: 'readonly',
        console: 'readonly',
        Buffer: 'readonly',
        setInterval: 'readonly',
        clearInterval: 'readonly',
        setTimeout: 'readonly',
        clearTimeout: 'readonly',
        URL: 'readonly',
        TextEncoder: 'readonly',
        TextDecoder: 'readonly',
      },
    },
    rules: {
      'no-unused-vars': ['warn', { argsIgnorePattern: '^_' }],
      'no-var': 'error',
      'no-eval': 'error',
      'no-new-func': 'error',
      'no-implied-eval': 'error',
      'no-prototype-builtins': 'warn',
      'eqeqeq': ['warn', 'always'],
      'no-dupe-keys': 'error',
      'no-unreachable': 'error',
    },
  },
];
