import { useId, useLayoutEffect, useRef, useState } from 'react';
import type { DailyPoint } from '../api/dashboard';

/** Largeur réelle d'un conteneur (les graphiques sont dessinés au pixel près, sans déformation). */
function useWidth<T extends HTMLElement>() {
  const ref = useRef<T>(null);
  const [width, setWidth] = useState(0);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    setWidth(el.clientWidth);
    if (typeof ResizeObserver === 'undefined') return;
    const observer = new ResizeObserver(([entry]) => setWidth(Math.round(entry!.contentRect.width)));
    observer.observe(el);
    return () => observer.disconnect();
  }, []);

  return [ref, width] as const;
}

const dayLabel = (date: string, opts: Intl.DateTimeFormatOptions) =>
  new Date(`${date}T12:00:00`).toLocaleDateString('fr-FR', opts);

/** Axe lisible : pas « rond » (1, 2, 2,5 ou 5 × 10^n) et graduations multiples de ce pas, 4 à 5 au plus. */
function niceTicks(max: number): number[] {
  if (max <= 0) return [0, 1];
  const raw = max / 4;
  const pow = 10 ** Math.floor(Math.log10(raw));
  const step = ([1, 2, 2.5, 5, 10].find((m) => m * pow >= raw) ?? 10) * pow;
  return Array.from({ length: Math.ceil(max / step) + 1 }, (_, i) => i * step);
}

/** Tendance compacte d'une tuile d'indicateur (décorative : la valeur et le tableau portent l'information). */
export function Sparkline({ points }: { points: DailyPoint[] }) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const gradient = useId();
  const height = 40;
  const max = Math.max(...points.map((p) => p.value), 1);
  const step = points.length > 1 ? width / (points.length - 1) : 0;
  const coords = points.map((p, i) => [i * step, height - 3 - (p.value / max) * (height - 8)] as const);
  const line = coords.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`).join('');
  const last = coords[coords.length - 1];

  return (
    <div ref={ref} className="sparkline" aria-hidden="true">
      {width > 0 && last && (
        <svg width={width} height={height}>
          <defs>
            <linearGradient id={gradient} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="var(--viz-1)" stopOpacity="0.22" />
              <stop offset="100%" stopColor="var(--viz-1)" stopOpacity="0" />
            </linearGradient>
          </defs>
          <path d={`${line}L${width},${height}L0,${height}Z`} fill={`url(#${gradient})`} />
          <path d={line} fill="none" stroke="var(--viz-1)" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
          <circle cx={last[0] - 1} cy={last[1]} r="3.5" fill="var(--viz-1)" stroke="var(--surface)" strokeWidth="2" />
        </svg>
      )}
    </div>
  );
}

type BarChartProps = {
  points: DailyPoint[];
  /** Nom de la série (titre du tableau et de l'info-bulle). */
  label: string;
  format: (value: number) => string;
  /** Format court des graduations de l'axe. */
  formatAxis?: (value: number) => string;
};

/**
 * Histogramme quotidien : une seule série (le titre de la carte la nomme, pas de légende),
 * barres fines arrondies côté valeur, grille discrète, info-bulle au survol ou au clavier,
 * et tableau des valeurs pour les lecteurs d'écran.
 */
export function DailyBarChart({ points, label, format, formatAxis = format }: BarChartProps) {
  const [ref, width] = useWidth<HTMLDivElement>();
  const [active, setActive] = useState<number | null>(null);
  const height = 240;
  const pad = { top: 12, right: 8, bottom: 28, left: 56 };
  const plotW = Math.max(width - pad.left - pad.right, 0);
  const plotH = height - pad.top - pad.bottom;
  const ticks = niceTicks(Math.max(...points.map((p) => p.value)));
  const max = ticks[ticks.length - 1]!;
  const band = points.length ? plotW / points.length : 0;
  const barW = Math.min(Math.max(band - 2, 2), 28); // 2 px d'espace minimum entre deux barres
  const y = (v: number) => pad.top + plotH - (v / max) * plotH;
  // Une étiquette de date toutes les N barres, pour qu'elles ne se chevauchent pas (7, 30 ou 366 jours).
  const labelEvery = Math.max(1, Math.ceil(46 / Math.max(band, 1)));
  const point = active !== null ? points[active] : undefined;

  return (
    <div className="chart">
      <div ref={ref} className="chart-plot" onMouseLeave={() => setActive(null)}>
        {width > 0 && (
          <svg width={width} height={height} role="img" aria-label={`${label}, ${points.length} derniers jours`}>
            {ticks.map((t) => (
              <g key={t}>
                <line x1={pad.left} x2={width - pad.right} y1={y(t)} y2={y(t)} className={t === 0 ? 'chart-baseline' : 'chart-grid'} />
                <text x={pad.left - 8} y={y(t)} dy="0.32em" textAnchor="end" className="chart-tick">{formatAxis(t)}</text>
              </g>
            ))}
            {points.map((p, i) => {
              const cx = pad.left + band * i + band / 2;
              const h = Math.max(plotH - (y(p.value) - pad.top), p.value > 0 ? 2 : 0);
              const r = Math.min(4, barW / 2, h);
              const x0 = cx - barW / 2;
              const top = pad.top + plotH - h;
              // Barre arrondie seulement en haut, posée à plat sur la ligne de base.
              const d = `M${x0},${pad.top + plotH}V${top + r}Q${x0},${top} ${x0 + r},${top}H${x0 + barW - r}Q${x0 + barW},${top} ${x0 + barW},${top + r}V${pad.top + plotH}Z`;
              return (
                <g key={p.date}>
                  {h > 0 && <path d={d} className={['chart-bar', active === i && 'is-active'].filter(Boolean).join(' ')} />}
                  {i % labelEvery === (points.length - 1) % labelEvery && (
                    <text x={cx} y={height - 8} textAnchor="middle" className="chart-tick">{dayLabel(p.date, { day: 'numeric', month: 'short' })}</text>
                  )}
                  {/* Zone de survol plus large que la barre. */}
                  <rect
                    x={pad.left + band * i}
                    y={pad.top}
                    width={band}
                    height={plotH}
                    fill="transparent"
                    tabIndex={0}
                    aria-label={`${dayLabel(p.date, { weekday: 'long', day: 'numeric', month: 'long' })} : ${format(p.value)}`}
                    onMouseEnter={() => setActive(i)}
                    onFocus={() => setActive(i)}
                    onBlur={() => setActive(null)}
                  />
                </g>
              );
            })}
          </svg>
        )}
        {point && active !== null && (
          <div
            className="chart-tooltip"
            role="status"
            style={{
              left: Math.min(Math.max(pad.left + band * active + band / 2, 70), width - 70),
              top: Math.max(y(point.value) - 12, 4),
            }}
          >
            <span>{dayLabel(point.date, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
            <strong>{format(point.value)}</strong>
          </div>
        )}
      </div>

      <details className="chart-data">
        <summary>Voir les données</summary>
        <table>
          <caption className="sr-only">{label}</caption>
          <thead><tr><th scope="col">Jour</th><th scope="col">{label}</th></tr></thead>
          <tbody>
            {points.map((p) => (
              <tr key={p.date}><td>{dayLabel(p.date, { weekday: 'short', day: 'numeric', month: 'short' })}</td><td>{format(p.value)}</td></tr>
            ))}
          </tbody>
        </table>
      </details>
    </div>
  );
}
