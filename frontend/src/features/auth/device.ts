export type DeviceKind = 'mobile' | 'desktop';

export type Device = { kind: DeviceKind; label: string };

// L'ordre compte : Edge, Opera et Samsung Internet se présentent aussi comme « Chrome », Chrome comme « Safari ».
const BROWSERS: [RegExp, string][] = [
  [/Edg(?:e|A|iOS)?\//, 'Edge'],
  [/OPR\/|Opera/, 'Opera'],
  [/SamsungBrowser\//, 'Samsung Internet'],
  [/Firefox\/|FxiOS\//, 'Firefox'],
  [/Chrome\/|CriOS\//, 'Chrome'],
  [/Safari\//, 'Safari'],
];

// iPhone et iPad avant « Mac OS X » (présent dans leur user-agent), Android avant Linux.
const SYSTEMS: [RegExp, string, DeviceKind][] = [
  [/iPhone|iPod/, 'iPhone', 'mobile'],
  [/iPad/, 'iPad', 'mobile'],
  [/Android/, 'Android', 'mobile'],
  [/Windows/, 'Windows', 'desktop'],
  [/Mac OS X|Macintosh/, 'Mac', 'desktop'],
  [/CrOS/, 'Chromebook', 'desktop'],
  [/Linux/, 'Linux', 'desktop'],
];

/**
 * User-agent → libellé lisible (« Chrome sur Windows »). Sert seulement à aider le joueur à reconnaître
 * ses appareils : un user-agent inconnu ou absent donne « Appareil inconnu », jamais une erreur.
 */
export function describeDevice(userAgent: string | null | undefined): Device {
  const ua = userAgent ?? '';
  const browser = BROWSERS.find(([pattern]) => pattern.test(ua))?.[1];
  const system = SYSTEMS.find(([pattern]) => pattern.test(ua));
  const kind = system?.[2] ?? (/Mobi/.test(ua) ? 'mobile' : 'desktop');

  if (browser && system) return { kind, label: `${browser} sur ${system[1]}` };
  return { kind, label: browser ?? system?.[1] ?? 'Appareil inconnu' };
}
