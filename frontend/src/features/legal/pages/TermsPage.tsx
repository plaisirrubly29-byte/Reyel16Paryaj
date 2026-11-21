import { Link } from 'react-router-dom';
import { LegalPage, LegalSection } from '../components/LegalPage';

export function TermsPage() {
  return (
    <LegalPage
      title="Conditions d'utilisation"
      intro="Ces conditions règlent l'utilisation du site REYEL 16 PARYAJ et de votre compte joueur. En créant un compte, vous les acceptez."
    >
      <LegalSection title="1. Qui peut jouer">
        <ul>
          <li>Vous devez avoir <strong>18 ans ou plus</strong>. Votre date de naissance est demandée à l'inscription.</li>
          <li>Un seul compte par personne. Les informations de votre compte doivent être exactes et à jour.</li>
          <li>Une pièce d'identité peut vous être demandée, en particulier avant un retrait.</li>
        </ul>
      </LegalSection>

      <LegalSection title="2. Votre compte">
        <ul>
          <li>Vous vous connectez avec votre numéro de téléphone et votre mot de passe. Gardez votre mot de passe secret.</li>
          <li>Les paris placés depuis votre compte sont sous votre responsabilité. Déconnectez-vous sur un appareil partagé.</li>
          <li>Si vous pensez qu'une autre personne utilise votre compte, changez votre mot de passe et prévenez le support.</li>
        </ul>
      </LegalSection>

      <LegalSection title="3. Solde, dépôts et retraits">
        <ul>
          <li>Votre solde est tenu en <strong>gourdes haïtiennes (HTG)</strong>. Il ne produit pas d'intérêts.</li>
          <li>
            Les dépôts et retraits se font auprès de REYEL 16 PARYAJ avec votre numéro de compte, comme expliqué sur
            la page <Link to="/wallet">Solde</Link>.
          </li>
          <li>Chaque mouvement apparaît dans <Link to="/transactions">Mes transactions</Link>. Une erreur de crédit peut être corrigée : la correction y apparaît aussi.</li>
        </ul>
      </LegalSection>

      <LegalSection title="4. Paris">
        <ul>
          <li>Un pari n'est accepté que lorsque le coupon affiche « Pari placé » et que le ticket apparaît dans <Link to="/bets">Mes paris</Link>.</li>
          <li>Les cotes, mises et gains suivent le <Link to="/reglement">règlement des paris</Link>, qui fait partie de ces conditions.</li>
        </ul>
      </LegalSection>

      <LegalSection title="5. Ce qui est interdit">
        <ul>
          <li>Ouvrir plusieurs comptes ou utiliser le compte d'une autre personne.</li>
          <li>Parier sur un match dont vous pouvez influencer le résultat (joueur, arbitre, dirigeant, entraîneur).</li>
          <li>Utiliser le site pour une fraude ou pour faire passer de l'argent d'origine illégale.</li>
          <li>Utiliser des programmes automatiques ou tenter de contourner la sécurité du site.</li>
        </ul>
      </LegalSection>

      <LegalSection title="6. Suspension et fermeture du compte">
        <p>
          En cas de manquement à ces conditions ou de soupçon de fraude, le compte peut être suspendu le temps d'une
          vérification. Vous pouvez à tout moment faire une pause ou vous exclure du jeu (voir{' '}
          <Link to="/jeu-responsable">Jeu responsable</Link>), ou demander la fermeture de votre compte au support.
        </p>
      </LegalSection>

      <LegalSection title="7. Disponibilité du site">
        <p>
          Le site peut être interrompu pour une maintenance ou une panne d'un fournisseur. Un pari déjà accepté reste
          valable et se règle normalement.
        </p>
      </LegalSection>

      <LegalSection title="8. Modification des conditions">
        <p>
          La version en vigueur et sa date sont indiquées en haut de cette page. Vous êtes informé de toute
          modification importante avant qu'elle ne s'applique. Un ticket déjà accepté garde toujours les règles du jour
          où il a été placé.
        </p>
      </LegalSection>

      <LegalSection title="9. Questions et réclamations">
        <p>Pour toute question ou réclamation, contactez le support de REYEL 16 PARYAJ.</p>
      </LegalSection>
    </LegalPage>
  );
}
