import { useEffect, useRef, type ReactNode } from 'react';
import { NavLink } from 'react-router-dom';
import { LEGAL_PAGES, LEGAL_UPDATED_AT, LEGAL_VERSION } from '../legal';

type Props = { title: string; intro: string; children: ReactNode };

/** Page d'information : menu des quatre textes, titre, version en vigueur, puis le texte en sections. */
export function LegalPage({ title, intro, children }: Props) {
  const navRef = useRef<HTMLElement>(null);

  useEffect(() => {
    // Arrivée depuis un lien du pied de page : le texte commence en haut.
    window.scrollTo({ top: 0 });
    // Sur mobile, le menu défile horizontalement : la page en cours doit y être visible.
    navRef.current?.querySelector('a.active')?.scrollIntoView?.({ block: 'nearest', inline: 'center' });
  }, [title]);

  return (
    <div className="legal">
      <nav className="legal-nav" aria-label="Pages d'information" ref={navRef}>
        {LEGAL_PAGES.map((p) => <NavLink key={p.to} to={p.to}>{p.title}</NavLink>)}
      </nav>

      <article className="legal-text">
        <header className="legal-head">
          <h1>{title}</h1>
          <p className="legal-version">En vigueur depuis le {LEGAL_UPDATED_AT} · version {LEGAL_VERSION}</p>
          <p className="legal-intro">{intro}</p>
        </header>
        {children}
      </article>
    </div>
  );
}

/** Section numérotée d'un texte (titre de niveau 2). */
export function LegalSection({ title, children }: { title: string; children: ReactNode }) {
  return (
    <section className="legal-section">
      <h2>{title}</h2>
      {children}
    </section>
  );
}
