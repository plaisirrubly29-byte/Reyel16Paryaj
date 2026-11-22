import logoUrl from '../../assets/reyel16-logo.jpg';

/** URL du logo officiel complet (carré : cheval, couronne, ballons, « Reyel16 PARYAJ » sur fond bordeaux), 720 px. */
export const BRAND_LOGO_URL = logoUrl;
/**
 * Même logo en 400 px (≈ 28 Ko) pour l'en-tête, le pied de page et le bandeau d'accueil. Adresse fixe dans public/brand
 * (V2-E05) : index.html le précharge et l'affiche dans la coquille de démarrage, avant même le JavaScript.
 */
export const BRAND_LOGO_SMALL_URL = `${import.meta.env.BASE_URL}brand/reyel16-logo-officiel-sm.jpg`;

/**
 * Vignette du logo pour l'en-tête et le pied de page : l'image complète, recadrée par CSS (.brand-logo)
 * sur « Reyel16 PARYAJ ». Décorative : le lien ou le texte qui l'entoure porte le nom.
 * `large` : grand affichage (écrans de connexion et d'inscription) : le logo complet en 720 px, sans recadrage.
 */
export function BrandLogo({ large = false }: { large?: boolean }) {
  return <span className={large ? 'brand-logo brand-logo-full' : 'brand-logo'} aria-hidden="true" style={{ backgroundImage: `url(${large ? logoUrl : BRAND_LOGO_SMALL_URL})` }} />;
}
