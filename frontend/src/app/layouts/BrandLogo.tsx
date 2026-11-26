import logoUrl from '../../assets/reyel16-logo.jpg';

/** URL du logo officiel complet (carré : cheval, couronne, ballons, « Reyel16 PARYAJ » sur fond bordeaux), 720 px. */
export const BRAND_LOGO_URL = logoUrl;
/**
 * Même logo en 400 px (≈ 28 Ko) pour l'en-tête, le pied de page et le bandeau d'accueil. Adresse fixe dans public/brand
 * (V2-E05) : index.html le précharge et l'affiche dans la coquille de démarrage, avant même le JavaScript.
 */
export const BRAND_LOGO_SMALL_URL = `${import.meta.env.BASE_URL}brand/reyel16-logo-officiel-sm.jpg`;

/**
 * Logo de l'en-tête, du pied de page et de l'admin : le logo officiel ENTIER, jamais recadré ni retouché (consigne de
 * Rubly). Décoratif : le lien ou le texte qui l'entoure porte le nom.
 * `large` : grand affichage (écrans de connexion et d'inscription), avec l'image en 720 px.
 */
export function BrandLogo({ large = false }: { large?: boolean }) {
  return <span className={large ? 'brand-logo brand-logo-full' : 'brand-logo'} aria-hidden="true" style={{ backgroundImage: `url(${large ? logoUrl : BRAND_LOGO_SMALL_URL})` }} />;
}
