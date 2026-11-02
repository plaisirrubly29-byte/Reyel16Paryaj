import { LegalPage, LegalSection } from '../components/LegalPage';

/** Règles des décisions D-03 à D-06 (docs/decisions), dites pour un joueur, avec les mêmes exemples chiffrés. */
export function BettingRulesPage() {
  return (
    <LegalPage
      title="Règlement des paris"
      intro="Ce règlement explique comment un pari est accepté, puis réglé. Chaque ticket garde les règles et les cotes du moment où il a été placé."
    >
      <LegalSection title="1. Types de paris">
        <ul>
          <li><strong>Pari simple</strong> : une seule sélection.</li>
          <li><strong>Pari combiné</strong> : jusqu'à 20 sélections, une seule par match. Toutes doivent être gagnantes.</li>
          <li>Les paris en direct, le cash-out et les paris système ne sont pas proposés pour le moment. Un match commencé n'est plus pariable.</li>
        </ul>
      </LegalSection>

      <LegalSection title="2. Mises et gains">
        <ul>
          <li>Mise minimum : <strong>10,00 HTG</strong> par ticket. Mise maximum : <strong>50 000,00 HTG</strong> par ticket.</li>
          <li>Gain maximum : <strong>1 000 000,00 HTG</strong> par ticket.</li>
          <li>Gain = mise × cote totale, arrondi au centime inférieur. La cote totale d'un combiné est le produit des cotes.</li>
          <li>
            Un pari peut aussi être refusé si la limite que vous avez choisie est atteinte, si une mise maximale a été
            fixée pour votre compte, ou si le plafond d'engagement d'un match ou d'un marché est atteint.
          </li>
        </ul>
        <p className="legal-example">
          Exemple : combiné de 3 sélections à 2,10 · 1,85 · 1,50, mise 100 HTG → cote totale 5,8275 → gain potentiel{' '}
          <strong>582,75 HTG</strong>.
        </p>
      </LegalSection>

      <LegalSection title="3. Cotes">
        <ul>
          <li>Seule la cote du serveur fait foi. Si elle a changé entre votre choix et la validation, à la hausse comme à la baisse, le pari est refusé et la nouvelle cote vous est montrée : vous confirmez ou non.</li>
          <li>Une fois le ticket accepté, sa cote ne change plus.</li>
          <li>Une cote trop ancienne ou un marché suspendu ne peut pas être joué (cote grisée).</li>
        </ul>
      </LegalSection>

      <LegalSection title="4. Marchés">
        <ul>
          <li>
            <strong>Résultat du match (1X2)</strong> : au football, seul le temps réglementaire compte (90 minutes et
            arrêts de jeu), sans prolongation ni tirs au but. Au basketball, les prolongations comptent et il n'y a pas de
            match nul.
          </li>
          <li><strong>Plus / moins</strong> : si le total est exactement égal à la ligne (ex. ligne 3, score 2-1), la sélection est annulée.</li>
          <li><strong>Double chance</strong> : deux issues sur trois. <strong>Les deux équipes marquent</strong> : oui ou non.</li>
        </ul>
      </LegalSection>

      <LegalSection title="5. Sélection annulée">
        <p>
          Une sélection annulée compte pour une cote de 1. Un pari simple est alors remboursé. Dans un combiné, les
          autres sélections continuent. Si toutes les sélections sont annulées, la mise est remboursée.
        </p>
        <p className="legal-example">
          Exemple : le combiné ci-dessus, dont le 2ᵉ match est annulé → 2,10 × 1 × 1,50 = 3,15 → gain{' '}
          <strong>315,00 HTG</strong> si les deux autres sélections gagnent.
        </p>
      </LegalSection>

      <LegalSection title="6. Match annulé, abandonné ou reporté">
        <ul>
          <li>Match annulé, ou abandonné sans score officiel : la sélection est annulée. Match abandonné avec un score officiel : le pari est réglé sur ce score.</li>
          <li>Match reporté : le pari reste valable si le match commence dans les <strong>48 heures</strong> après l'heure prévue au moment du pari. Sinon, la sélection est annulée.</li>
        </ul>
      </LegalSection>

      <LegalSection title="7. Résultats et corrections">
        <ul>
          <li>Les résultats viennent du fournisseur de données sportives. S'il ne les donne pas, un administrateur les saisit et un second les valide.</li>
          <li>
            Un résultat peut être corrigé jusqu'à <strong>72 heures</strong> après le règlement, après une double
            validation. Si l'issue d'un ticket change, le gain versé est repris ou complété : votre solde peut alors
            devenir négatif.
          </li>
        </ul>
      </LegalSection>
    </LegalPage>
  );
}
