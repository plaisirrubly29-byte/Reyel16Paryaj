import { Link } from 'react-router-dom';
import { LegalPage, LegalSection } from '../components/LegalPage';

/** Outils décrits d'après l'API P7-02 (docs/api/openapi.yaml, /responsible-gaming). */
export function ResponsibleGamingPage() {
  return (
    <LegalPage
      title="Jeu responsable"
      intro="Parier doit rester un divertissement, jamais un moyen de gagner sa vie ou de rembourser une dette. Le jeu est interdit aux moins de 18 ans."
    >
      <LegalSection title="1. Quelques règles simples">
        <ul>
          <li>Fixez-vous un budget et ne misez que ce que vous pouvez vous permettre de perdre.</li>
          <li>Ne cherchez pas à « vous refaire » après une perte.</li>
          <li>Ne pariez pas sous l'effet de l'alcool, de la colère ou de la fatigue.</li>
          <li>Faites des pauses et gardez du temps pour vos proches et vos autres activités.</li>
        </ul>
      </LegalSection>

      <LegalSection title="2. Les signes qui doivent alerter">
        <ul>
          <li>Vous misez plus que prévu, ou de plus en plus pour ressentir la même chose.</li>
          <li>Vous empruntez ou vendez des biens pour parier.</li>
          <li>Vous cachez vos paris à vos proches, ou ils vous en parlent avec inquiétude.</li>
          <li>Le jeu vous empêche de dormir, de travailler ou d'étudier.</li>
        </ul>
      </LegalSection>

      <LegalSection title="3. Les outils de votre compte">
        <ul>
          <li>
            <strong>Limites de mise</strong> par jour, par semaine et par mois. Une baisse s'applique tout de suite ; une
            hausse ou une suppression seulement après 7 jours de réflexion.
          </li>
          <li><strong>Pause</strong> de 24 heures, 7 jours ou 30 jours : vous ne pouvez plus parier, mais vous gardez l'accès à votre solde et à vos tickets. Une pause ne peut pas être raccourcie.</li>
          <li>
            <strong>Auto-exclusion</strong> de 6 mois, 1 an ou 5 ans : le compte est fermé au jeu. À la fin de la période,
            il ne rouvre que sur demande, après examen par l'équipe.
          </li>
        </ul>
        <p>
          Réglez-les depuis votre espace joueur, rubrique <Link to="/responsible-gaming">Jeu responsable</Link> (connexion
          requise). Le support peut aussi vous aider.
        </p>
      </LegalSection>

      <LegalSection title="4. Protéger les mineurs">
        <p>
          Ne laissez pas un mineur utiliser votre compte ou votre téléphone connecté. Déconnectez-vous après avoir joué
          sur un appareil partagé.
        </p>
      </LegalSection>

      <LegalSection title="5. Chercher de l'aide">
        <p>
          Si le jeu devient un problème, parlez-en à un proche, à un médecin ou à un professionnel de santé. Vous pouvez
          aussi faire une pause ou vous exclure à tout moment.
        </p>
      </LegalSection>
    </LegalPage>
  );
}
