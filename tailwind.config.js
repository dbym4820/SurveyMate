/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./resources/ts/**/*.{js,ts,jsx,tsx}",
    "./resources/views/**/*.blade.php",
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Noto Sans JP"', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
