import { Link } from 'react-router-dom';
import { LegalPage, LegalSection } from '../components/LegalPage';

export function PrivacyPage() {
  return (
    <LegalPage
      title="Confidentialité et cookies"
      intro="Cette page explique quelles données REYEL 16 PARYAJ garde sur vous, pourquoi, et ce que le site enregistre sur votre appareil."
    >
      <LegalSection title="1. Données collectées">
        <ul>
          <li>À l'inscription : nom, numéro de téléphone, e-mail (facultatif), date de naissance, pays et mot de passe. Le mot de passe n'est jamais gardé en clair.</li>
          <li>Pendant le jeu : vos tickets, vos mouvements de solde et vos réglages de jeu responsable.</li>
          <li>Pour la sécurité : l'adresse IP, l'appareil et l'heure des connexions.</li>
        </ul>
      </LegalSection>

      <LegalSection title="2. Pourquoi">
        <ul>
          <li>Gérer votre compte, vos paris, vos dépôts et vos retraits.</li>
          <li>Vérifier votre âge et votre identité.</li>
          <li>Protéger les comptes et lutter contre la fraude et le blanchiment.</li>
          <li>Appliquer les outils de jeu responsable.</li>
        </ul>
      </LegalSection>

      <LegalSection title="3. Qui y a accès">
        <ul>
          <li>Seuls les membres de l'équipe qui en ont besoin, selon leur rôle. La consultation des données sensibles est enregistrée.</li>
          <li>Les prestataires techniques (hébergement, envoi de messages) seulement pour faire fonctionner le service.</li>
          <li>Vos données ne sont ni vendues ni louées.</li>
        </ul>
      </LegalSection>

      <LegalSection title="4. Durée de conservation">
        <p>
          Les données sont gardées tant que votre compte est ouvert, puis aussi longtemps que l'exigent les obligations
          comptables et légales liées aux paris et aux paiements.
        </p>
      </LegalSection>

      <LegalSection title="5. Vos droits">
        <p>
          Vous voyez vos informations dans <Link to="/account">Mes informations</Link>. Pour les corriger, obtenir une
          copie de vos données ou demander leur suppression, contactez le support. Certaines données (tickets,
          transactions) doivent être conservées même après la fermeture du compte.
        </p>
      </LegalSection>

      <LegalSection title="6. Cookies et stockage sur votre appareil">
        <p>Le site n'utilise <strong>aucun cookie publicitaire ni de mesure d'audience</strong>. Il enregistre seulement :</p>
        <ul>
          <li><strong>Cookie de session</strong> : vous garde connecté.</li>
          <li><strong>Cookie de sécurité (XSRF-TOKEN)</strong> : protège vos actions contre les requêtes frauduleuses.</li>
          <li><strong>Coupon</strong> : vos sélections en cours restent sur cet appareil (stockage local), même après avoir fermé la page.</li>
          <li><strong>Bandeau d'information</strong> : se souvient que vous l'avez lu, pour ne plus l'afficher.</li>
        </ul>
        <p>Ces éléments sont nécessaires au site : sans eux, la connexion et les paris ne fonctionnent pas.</p>
      </LegalSection>

      <LegalSection title="7. Sécurité">
        <p>
          Les échanges avec le site sont chiffrés (HTTPS). L'accès de l'équipe à l'administration exige une double
          authentification.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
