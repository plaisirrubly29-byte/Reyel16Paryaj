/** Icônes vectorielles au trait (24×24), couleur héritée du texte. Décoratives : masquées aux lecteurs d'écran. */
const PATHS = {
  home: 'M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
  ball: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-13.5 3.3 2.4-1.3 3.9h-4l-1.3-3.9zM12 3v4.5M15.3 9.9l4.6-1.5M14 13.8l2.8 3.9M10 13.8l-2.8 3.9M8.7 9.9 4.1 8.4',
  basketball: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM3 12h18M12 3v18M5.6 5.6c2.4 1.6 3.9 3.9 3.9 6.4s-1.5 4.8-3.9 6.4M18.4 5.6c-2.4 1.6-3.9 3.9-3.9 6.4s1.5 4.8 3.9 6.4',
  baseball: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM6.3 5.1c1.5 1.9 2.3 4.3 2.3 6.9s-.8 5-2.3 6.9M17.7 5.1c-1.5 1.9-2.3 4.3-2.3 6.9s.8 5 2.3 6.9',
  tennis: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM5.2 6.1A9 9 0 0 1 5.2 17.9M18.8 6.1a9 9 0 0 0 0 11.8',
  hockey: 'M4 3l7 13h5a2 2 0 0 1 0 4H9.5L3 7M20 3l-4.5 9M13 20h7',
  trophy: 'M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0zM7 6H4v2a3 3 0 0 0 3 3M17 6h3v2a3 3 0 0 1-3 3',
  coupon: 'M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2zM9 8h6M9 12h6',
  tickets: 'M4 6h16M4 12h16M4 18h10',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-8 9a8 8 0 0 1 16 0',
  wallet: 'M3 7a2 2 0 0 1 2-2h13v4M3 7v11a2 2 0 0 0 2 2h15V9H5a2 2 0 0 1-2-2zM16 14.5h.01',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
  shield: 'M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6zM9 12l2 2 4-4',
  bolt: 'M13 3 5 14h6l-1 7 8-11h-6z',
  chevron: 'M9 6l6 6-6 6',
  check: 'M5 12.5l4.5 4.5L19 7.5',
  mail: 'M4 6h16v12H4zM4 7l8 6 8-6',
  lock: 'M6 11V8a6 6 0 0 1 12 0v3M5 11h14v10H5zM12 15v3',
  calendar: 'M4 9h16M7 3v4M17 3v4M5 6h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1zM8 14h.01M12 14h.01M16 14h.01',
  flag: 'M5 21V4M5 4h13l-3 4 3 4H5',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
  cart: 'M3 4h2.5l2.2 11h10.6L20.5 8H6.6M9.5 20h.01M17 20h.01',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3',
  flame: 'M12 3c1 3.5 5 5.5 5 10a5 5 0 0 1-10 0c0-2.2 1.1-3.6 2.4-4.8.3 1.6 1.1 2.6 2.1 3 0-3.2-.8-5.7.5-8.2z',
  eye: 'M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  eyeOff: 'M3 3l18 18M10.6 6.1A9.8 9.8 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-2.9 3.6M6.4 7.4C3.9 9.1 2.5 12 2.5 12S6 18 12 18c1.6 0 3-.4 4.2-1M9.9 9.9a3 3 0 0 0 4.2 4.2',
  live: 'M12 21a9 9 0 1 0-6.4-2.6M12 7v5l3 2',
  arrowRight: 'M4 12h15M13 6l6 6-6 6',
  phone: 'M8 3h8a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1zM11 18h2',
  logout: 'M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 8l-4 4 4 4M6 12h10',
  help: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01',
  sports: 'M13 4.5a1.5 1.5 0 1 0 3 0 1.5 1.5 0 0 0-3 0zM7 21l3-5 3 2v5M5 11l4-3 4 1 2 4 4 1M10 16l1-6',
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
