import { LegalPage, LegalSection } from '../components/LegalPage';

/**
 * Règles des décisions D-03 à D-06 et D-20 §10 (paris en direct) (docs/decisions), dites pour un joueur, avec les
 * mêmes exemples chiffrés.
 */
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
          <li>
            <strong>Pari en direct</strong> : pari sur un match de football en cours, en pari simple seulement (voir la
            section 6).
          </li>
          <li>Le cash-out et les paris système ne sont pas proposés pour le moment.</li>
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
          <li><strong>Score exact final</strong> (en direct) : le score à la fin du temps réglementaire.</li>
        </ul>
      </LegalSection>

      <LegalSection title="5. Marchés Spécial R16">
        <p>
          Les marchés Spécial R16 sont proposés par REYEL 16 sur certains matchs de football, en plus des marchés des
          bookmakers. Ils sont réglés sur le score final, comme le résultat du match (temps réglementaire seulement).
        </p>
        <ul>
          <li><strong>Nombre exact de buts</strong> : 0, 1, 2, 3 ou 4 buts, ou 5 buts ou plus dans le match.</li>
          <li><strong>Tranche de buts</strong> : 0 ou 1 but, 2 ou 3 buts, ou 4 buts ou plus.</li>
          <li><strong>Victoire sans encaisser</strong> : une équipe gagne sans encaisser de but, ou aucune des deux.</li>
          <li><strong>Écart de buts</strong> : une équipe gagne par 1 but, par 2 buts ou par 3 buts ou plus, ou match nul.</li>
          <li><strong>Résultat et plus/moins de 2,5 buts</strong> : le résultat du match et le total de buts, à la fois.</li>
          <li>Mise maximum d'un ticket qui contient un marché Spécial R16 : <strong>1 000,00 HTG</strong>.</li>
        </ul>
        <p className="legal-example">
          Exemple : score final 2-0 → « 2 buts », « 2 ou 3 buts », « l'équipe à domicile gagne sans encaisser », « par 2
          buts » et « domicile et moins de 2,5 buts » sont gagnants.
        </p>
      </LegalSection>

      <LegalSection title="6. Paris en direct">
        <ul>
          <li>
            Proposés au <strong>football</strong> seulement, sur le résultat du match (1X2), le plus/moins, le score exact
            final et les marchés Spécial R16. <strong>Paris simples seulement</strong> : pas de combiné avec un match en
            cours.
          </li>
          <li>
            Mise maximum : <strong>10 000,00 HTG</strong> par ticket en direct, <strong>1 000,00 HTG</strong> pour le
            score exact et les marchés Spécial R16.
          </li>
          <li>
            <strong>Validation</strong> : le pari est d'abord « en cours de validation » et sa mise est réservée. Environ{' '}
            <strong>8 secondes</strong> plus tard, il est revérifié, puis accepté ou refusé. S'il est refusé, la mise vous
            est rendue en entier. Un pari qui n'a pas pu être vérifié dans les 60 secondes est refusé : il n'est jamais
            accepté par défaut.
          </li>
          <li>
            <strong>Motifs de refus</strong> : la cote a baissé de plus de 3 %, le score a changé, le marché a été
            suspendu, les paris en direct ont été fermés, ou la validation a pris trop de temps. Si la cote a baissé de
            3 % ou moins, le pari est accepté à la cote la plus basse.
          </li>
          <li>
            <strong>Suspension</strong> : après un but, les marchés du match sont suspendus environ{' '}
            <strong>10 secondes</strong>, puis rouverts avec de nouvelles cotes. Un marché est aussi suspendu quand son
            issue est presque acquise, quand le score n'a pas pu être vérifié récemment, ou par un administrateur. Un
            marché suspendu reste affiché, grisé.
          </li>
          <li>
            <strong>Fermeture</strong> : les paris restent ouverts, temps additionnel compris, jusqu'au résultat final. Ils
            sont fermés au plus tard <strong>150 minutes</strong> après l'heure prévue du coup d'envoi, et plus tôt si le
            score du match n'est plus suivi depuis 5 minutes.
          </li>
          <li>
            Le score en direct est fourni par un fournisseur de données sportives et peut avoir du retard. La minute de
            jeu affichée est une <strong>estimation</strong> calculée depuis l'heure prévue du coup d'envoi. Les paris
            sont toujours réglés sur le score final officiel.
          </li>
        </ul>
        <p className="legal-example">
          Exemple : le match est à 2-1, vous pariez « score exact 3-1 ». Si le score change pendant les 8 secondes de
          validation, le pari est refusé et la mise rendue. Sinon, il est accepté et gagne si le match finit 3-1.
        </p>
      </LegalSection>

      <LegalSection title="7. Sélection annulée">
        <p>
          Une sélection annulée compte pour une cote de 1. Un pari simple est alors remboursé. Dans un combiné, les
          autres sélections continuent. Si toutes les sélections sont annulées, la mise est remboursée.
        </p>
        <p className="legal-example">
          Exemple : le combiné ci-dessus, dont le 2ᵉ match est annulé → 2,10 × 1 × 1,50 = 3,15 → gain{' '}
          <strong>315,00 HTG</strong> si les deux autres sélections gagnent.
        </p>
      </LegalSection>

      <LegalSection title="8. Match annulé, abandonné ou reporté">
        <ul>
          <li>Match annulé, ou abandonné sans score officiel : la sélection est annulée. Match abandonné avec un score officiel : le pari est réglé sur ce score.</li>
          <li>Match reporté : le pari reste valable si le match commence dans les <strong>48 heures</strong> après l'heure prévue au moment du pari. Sinon, la sélection est annulée.</li>
        </ul>
      </LegalSection>

      <LegalSection title="9. Résultats et corrections">
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
