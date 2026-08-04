/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './src/**/*.{astro,html,js,jsx,ts,tsx,vue,svelte,md,mdx,json}',
    './public/**/*.{js,html}',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Manrope', 'sans-serif'],
      },
      colors: {
        brand: {
          dark: '#0a0a0a',
          pill: '#1a1a1a',
        },
      },
    },
  },
  corePlugins: {
    preflight: true,
  },
  plugins: [],
};