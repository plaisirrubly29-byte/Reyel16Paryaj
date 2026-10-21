import { BettingBoard } from '../components/BettingBoard';

/** Tous les matchs ouverts aux paris, filtrables par sport ou compétition. */
export function SportsPage() {
  return (
    <>
      <h1 className="sr-only">Sports & matchs</h1>
      <BettingBoard title="Tous les matchs" perPage={40} paginate />
    </>
  );
}
