import { BettingBoard } from '../components/BettingBoard';

/** Tous les matchs ouverts aux paris, filtrables par sport. */
export function SportsPage() {
  return (
    <section className="stack">
      <h1>Sports & matchs</h1>
      <BettingBoard perPage={40} paginate />
    </section>
  );
}
