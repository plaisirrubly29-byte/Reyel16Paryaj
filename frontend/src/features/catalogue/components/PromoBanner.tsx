import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { BRAND_LOGO_SMALL_URL } from '../../../app/layouts/BrandLogo';
import { Icon } from '../../../shared/components/Icon';
import moncashLogo from '../../../assets/payments/moncash.jpg';
import natcashLogo from '../../../assets/payments/natcash.jpg';

type Slide = {
  id: string;
  title: ReactNode;
  text: ReactNode;
  cta: string;
  to: string;
  visual: ReactNode;
  tone: 'brand' | 'gold' | 'dark';
};

/** Durée d'affichage d'une diapositive avant la suivante (défilement automatique). */
const SLIDE_MS = 6000;

function prefersReducedMotion(): boolean {
  return typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Change de diapositive d'un coup (fondu en CSS, .promo-fade) plutôt qu'avec un défilement « smooth » : celui-ci peut
 * rester bloqué à mi-chemin quand le navigateur ralentit les animations (onglet en arrière-plan, téléphone lent).
 */

/**
 * Bannière de l'accueil : quelques diapositives courtes, chacune sur une vraie fonction du site (paris, dépôt mobile,
 * paris maison, borlette ouverte). Défile seule toutes les 6 s, se glisse au doigt, points pour choisir.
 * Le défilement s'arrête au survol, au clavier et si l'appareil demande moins d'animations.
 */
export function PromoBanner({ lotteryDraws = 0 }: { lotteryDraws?: number }) {
  const slides: Slide[] = [
    {
      id: 'paris',
      tone: 'brand',
      title: <>Parye sou espò <span>ou renmen</span></>,
      text: 'Football, basketball, tennis… Cotes mises à jour en continu, pi fasil, pi rapid, pi sekirite.',
      cta: 'Voir les matchs',
      to: '/sports',
      // Plus grand élément visible de l'accueil : chargé en priorité (V2-E05). Logo officiel entier, jamais recadré.
      visual: <img className="promo-logo" src={BRAND_LOGO_SMALL_URL} alt="" width={140} height={140} fetchPriority="high" />,
    },
    {
      id: 'depot',
      tone: 'gold',
      title: <>Dépôt avec <span>MonCash ou NatCash</span></>,
      text: 'Rechargez votre compte depuis votre téléphone, puis pariez tout de suite.',
      cta: 'Déposer',
      to: '/wallet?operation=depot',
      visual: (
        <span className="promo-wallets">
          <img src={moncashLogo} alt="" width={64} height={64} loading="lazy" />
          <img src={natcashLogo} alt="" width={64} height={64} loading="lazy" />
        </span>
      ),
    },
    {
      id: 'special',
      tone: 'dark',
      title: <>Paris <span>Spécial R16</span></>,
      text: 'Nos paris maison sur les grands matchs : nombre exact de buts, tranche de buts, victoire sans encaisser…',
      cta: 'Choisir un match',
      to: '/sports',
      visual: <span className="promo-icon" aria-hidden="true"><Icon name="star" size={56} /></span>,
    },
  ];
  if (lotteryDraws > 0) {
    slides.push({
      id: 'borlette',
      tone: 'gold',
      title: <>Borlette : <span>{lotteryDraws} tirage{lotteryDraws > 1 ? 's' : ''} ouvert{lotteryDraws > 1 ? 's' : ''}</span></>,
      text: 'Jouez vos numéros avant la fermeture du tirage.',
      cta: 'Jouer à la borlette',
      to: '/borlette',
      visual: <span className="promo-icon" aria-hidden="true"><Icon name="lottery" size={56} /></span>,
    });
  }

  const trackRef = useRef<HTMLUListElement>(null);
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  // Diapositive arrivée par un point ou par le défilement automatique : fondu d'entrée (pas pour un glissé au doigt).
  const [faded, setFaded] = useState<number | null>(null);
  const count = slides.length;

  function goTo(next: number) {
    const el = trackRef.current;
    if (!el) return;
    const target = ((next % count) + count) % count;
    el.scrollTo?.({ left: target * el.clientWidth, behavior: 'auto' });
    setIndex(target);
    setFaded(target);
  }

  // Défilement automatique, sauf survol / focus clavier / moins d'animations demandé.
  useEffect(() => {
    if (paused || count < 2 || prefersReducedMotion()) return undefined;
    const timer = window.setTimeout(() => goTo(index + 1), SLIDE_MS);
    return () => window.clearTimeout(timer);
  }, [index, paused, count]); // eslint-disable-line react-hooks/exhaustive-deps

  // Glissé au doigt : le point actif suit la diapositive visible.
  function onScroll() {
    const el = trackRef.current;
    if (!el || el.clientWidth === 0) return;
    const visible = Math.round(el.scrollLeft / el.clientWidth);
    if (visible !== index) {
      setIndex(visible);
      setFaded(null);
    }
  }

  return (
    <section
      className="promo"
      aria-roledescription="carrousel"
      aria-label="À la une"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocus={() => setPaused(true)}
      onBlur={() => setPaused(false)}
    >
      <ul className="promo-track" ref={trackRef} onScroll={onScroll}>
        {slides.map((slide, i) => (
          <li
            key={slide.id}
            className={`promo-slide promo-${slide.tone}${faded === i ? ' promo-fade' : ''}`}
            aria-roledescription="diapositive"
            aria-label={`${i + 1} sur ${count}`}
            aria-hidden={i !== index}
          >
            <div className="promo-text">
              <p className="promo-title">{slide.title}</p>
              <p className="promo-desc">{slide.text}</p>
              <Link to={slide.to} className="promo-cta" tabIndex={i === index ? undefined : -1}>
                {slide.cta}<Icon name="arrowRight" size={16} />
              </Link>
            </div>
            <div className="promo-visual">{slide.visual}</div>
          </li>
        ))}
      </ul>
      {count > 1 && (
        <div className="promo-dots" role="group" aria-label="Choisir une diapositive">
          {slides.map((slide, i) => (
            <button
              key={slide.id}
              type="button"
              aria-label={`Diapositive ${i + 1}`}
              aria-current={i === index ? 'true' : undefined}
              onClick={() => goTo(i)}
            />
          ))}
        </div>
      )}
    </section>
  );
}
