import logoUrl from '../../assets/reyel16-logo.jpg';

/** URL du logo complet (affiche carrée : cheval, portefeuille, nom, devise, sports). */
export const BRAND_LOGO_URL = logoUrl;

/**
 * Vignette du logo pour l'en-tête et le pied de page : l'image complète, recadrée par CSS (.brand-logo)
 * sur « Reyel16 PARYAJ » et la devise. Décorative : le lien ou le texte qui l'entoure porte le nom.
 */
export function BrandLogo() {
  return <span className="brand-logo" aria-hidden="true" style={{ backgroundImage: `url(${logoUrl})` }} />;
}
