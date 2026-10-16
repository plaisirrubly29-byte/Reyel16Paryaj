/** Icônes vectorielles au trait (24×24), couleur héritée du texte. Décoratives : masquées aux lecteurs d'écran. */
const PATHS = {
  home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  ball: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-13.5 3.3 2.4-1.3 3.9h-4l-1.3-3.9zM12 3v4.5M15.3 9.9l4.6-1.5M14 13.8l2.8 3.9M10 13.8l-2.8 3.9M8.7 9.9 4.1 8.4',
  coupon: 'M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2zM9 8h6M9 12h6',
  tickets: 'M4 6h16M4 12h16M4 18h10',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-8 9a8 8 0 0 1 16 0',
} as const;

export type IconName = keyof typeof PATHS;

export function Icon({ name, size = 22 }: { name: IconName; size?: number }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
    >
      <path d={PATHS[name]} />
    </svg>
  );
}
