/** @type {import('tailwindcss').Config} */
export default {
  prefix: 'sps-',
  content: ['./index.html', './src/**/*.{js,jsx}'],
  theme: {
    extend: {
      colors: {
        'sps-primary': '#16a34a',
        'sps-primary-dark': '#15803d',
      },
    },
  },
  plugins: [],
};
