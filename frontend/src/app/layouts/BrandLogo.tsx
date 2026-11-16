import logoUrl from '../../assets/reyel16-logo.jpg';

/** URL du logo complet (affiche portrait : cheval, portefeuille, nom, devise, sports), 560 px de large. */
export const BRAND_LOGO_URL = logoUrl;
/**
 * Même logo en 320 px (≈ 37 Ko) pour l'en-tête, le pied de page et le bandeau d'accueil. Adresse fixe dans public/brand
 * (V2-E05) : index.html le précharge et l'affiche dans la coquille de démarrage, avant même le JavaScript.
 */
export const BRAND_LOGO_SMALL_URL = `${import.meta.env.BASE_URL}brand/reyel16-logo-sm.jpg`;

/**
 * Vignette du logo pour l'en-tête et le pied de page : l'image complète, recadrée par CSS (.brand-logo)
 * sur « Reyel16 PARYAJ » et la devise. Décorative : le lien ou le texte qui l'entoure porte le nom.
 * `large` : grand affichage (écrans de connexion et d'inscription), avec le logo en 560 px.
 */
export function BrandLogo({ large = false }: { large?: boolean }) {
  return <span className="brand-logo" aria-hidden="true" style={{ backgroundImage: `url(${large ? logoUrl : BRAND_LOGO_SMALL_URL})` }} />;
}
