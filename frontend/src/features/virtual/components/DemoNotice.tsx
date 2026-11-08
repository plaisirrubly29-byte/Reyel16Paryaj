import { Alert } from '../../../shared/components/Alert';

/** Bandeau affiché tant que les données viennent du module de démonstration (meta.demo = true). */
export function DemoNotice() {
  return (
    <Alert tone="warning" title="Démonstration">
      <p>
        Les épreuves, équipes, chevaux, cotes et résultats affichés ici sont fictifs. Aucun pari ne peut être placé :
        les jeux virtuels ouvriront une fois le prestataire choisi.
      </p>
    </Alert>
  );
}
