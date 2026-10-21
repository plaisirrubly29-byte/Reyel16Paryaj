import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '../../shared/api/client';
import { BrandLogo } from './BrandLogo';

type Health = { status: string; version: string };

/** Pied de page public (bleu marine) : présentation, liens, jeu responsable et état de l'API (discret, n'empêche jamais l'affichage). */
export function SiteFooter() {
  const health = useQuery({
    queryKey: ['health'],
    queryFn: ({ signal }) => api<Health>('/health', { signal }),
    retry: false,
    staleTime: 60_000,
  });

  const status = health.isPending ? 'vérification…' : health.data?.status === 'ok' ? 'en ligne' : 'indisponible';

  return (
    <footer className="site-footer">
      <div className="footer-inner">
        <div className="footer-brand">
          <p className="brand"><BrandLogo /><span className="sr-only">REYEL 16 PARYAJ</span></p>
          <p className="small">Paris sportifs sur le football, le basketball et le baseball. Version de démonstration : fonds fictifs uniquement.</p>
        </div>

        <nav className="footer-col" aria-label="Paris sportifs">
          <p className="footer-title">Paris sportifs</p>
          <Link to="/">Accueil</Link>
          <Link to="/sports">Sports &amp; matchs</Link>
          <Link to="/sports?sport=soccer">Football</Link>
          <Link to="/sports?sport=basketball">Basketball</Link>
          <Link to="/?tab=live">Live</Link>
        </nav>

        <nav className="footer-col" aria-label="Mon compte">
          <p className="footer-title">Mon compte</p>
          <Link to="/register">Créer un compte</Link>
          <Link to="/login">Se connecter</Link>
          <Link to="/forgot-password">Mot de passe oublié</Link>
          <Link to="/bets">Mes paris</Link>
        </nav>

        <div className="footer-col">
          <p className="footer-title">Jeu responsable</p>
          <p className="small">Interdit aux moins de 18 ans. Le jeu doit rester un divertissement : fixez-vous des limites et faites une pause si besoin.</p>
          <span className="age-badge" aria-label="Réservé aux plus de 18 ans">18+</span>
        </div>
      </div>

      <div className="footer-bottom">
        <p className="small">© REYEL 16 PARYAJ {new Date().getFullYear()} · Version de démonstration · Fonds fictifs uniquement</p>
        <p className="small">
          <span className={`dot ${health.data?.status === 'ok' ? 'dot-ok' : health.isPending ? '' : 'dot-down'}`} aria-hidden="true" />
          Service : {status}
        </p>
      </div>
    </footer>
  );
}
