import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Button } from '../../../shared/components/Button';
import { LEGAL_VERSION } from '../legal';

const STORAGE_KEY = 'r16p.cookies';

function seen(): boolean {
  try {
    return localStorage.getItem(STORAGE_KEY) === LEGAL_VERSION;
  } catch {
    return false;
  }
}

/**
 * Bandeau d'information sur les cookies, en bas de l'écran jusqu'à « J'ai compris ».
 * Le site n'a que des cookies nécessaires (session, sécurité) : rien à refuser, seulement à expliquer.
 * Il revient quand la version des textes change.
 */
export function CookieBanner() {
  const [open, setOpen] = useState(() => !seen());

  if (!open) return null;

  function close() {
    try {
      localStorage.setItem(STORAGE_KEY, LEGAL_VERSION);
    } catch {
      // Stockage bloqué (navigation privée) : le bandeau se ferme quand même pour cette visite.
    }
    setOpen(false);
  }

  return (
    <section className="cookie-banner" aria-label="Cookies">
      <p>
        Ce site utilise seulement les cookies nécessaires à la connexion et à la sécurité, et garde votre coupon sur cet
        appareil. Aucun cookie publicitaire ni de mesure d'audience.{' '}
        <Link to="/confidentialite">En savoir plus</Link>
      </p>
      <Button className="btn-sm" onClick={close}>J'ai compris</Button>
    </section>
  );
}
