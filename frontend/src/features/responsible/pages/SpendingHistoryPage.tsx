import { useState } from 'react';
import { Link } from 'react-router-dom';
import type { Money } from '../../../shared/api/client';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { useSpendingHistory, type SpendingTotals } from '../api/responsibleGaming';

type View = 'days' | 'months';
type Row = SpendingTotals & { key: string; label: string };

/** « 2026-11-10 » → « lun. 10 nov. » ; « 2026-11 » → « novembre 2026 » (dates déjà découpées par le serveur). */
const dayLabel = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'short' });
const monthLabel = (month: string) => new Date(`${month}-15T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });

const empty = (r: SpendingTotals) => [r.deposits, r.withdrawals, r.stakes, r.payouts, r.refunds].every((m) => m.minor === 0);

function sum(rows: SpendingTotals[], field: keyof SpendingTotals): Money {
  return { minor: rows.reduce((total, r) => total + r[field].minor, 0), currency: rows[0]?.[field].currency ?? 'HTG' };
}

/** Résultat net : gagné (positif) ou perdu (négatif), écrit en clair et signé. */
function Net({ value }: { value: Money }) {
  if (value.minor === 0) return <span className="muted"><MoneyText value={value} /></span>;
  return <MoneyText value={value} signed />;
}

/**
 * Historique des dépenses du joueur (V2-E06) : 30 derniers jours ou 12 derniers mois, calculés par le serveur à
 * l'heure d'Haïti. Les totaux affichés en tête additionnent simplement les lignes reçues.
 */
export function SpendingHistoryPage() {
  const history = useSpendingHistory();
  const [view, setView] = useState<View>('days');
  const [hideEmpty, setHideEmpty] = useState(true);
  const h = history.data;

  const rows: Row[] = !h ? [] : view === 'days'
    ? h.days.map((d) => ({ ...d, key: d.date, label: dayLabel(d.date) }))
    : h.months.map((m) => ({ ...m, key: m.month, label: monthLabel(m.month) }));
  const visible = hideEmpty ? rows.filter((r) => !empty(r)) : rows;
  const period = view === 'days' ? `${h?.days.length ?? 30} derniers jours` : `${h?.months.length ?? 12} derniers mois`;

  return (
    <section className="rg stack">
      <header className="rg-head">
        <p className="small"><Link to="/responsible-gaming">← Jeu responsable</Link></p>
        <h1>Historique de mes dépenses</h1>
        <p className="muted">Ce que vous avez déposé, misé et gagné. Le résultat net est vos gains et remboursements moins vos mises.</p>
      </header>

      <div className="spending-toolbar">
        <div className="segmented" role="group" aria-label="Période">
          <button type="button" className={view === 'days' ? 'is-active' : undefined} aria-pressed={view === 'days'} onClick={() => setView('days')}>Par jour</button>
          <button type="button" className={view === 'months' ? 'is-active' : undefined} aria-pressed={view === 'months'} onClick={() => setView('months')}>Par mois</button>
        </div>
        <label className="spending-filter">
          <input type="checkbox" checked={hideEmpty} onChange={(e) => setHideEmpty(e.target.checked)} />
          Masquer les périodes sans mouvement
        </label>
      </div>

      {history.isPending && <div className="table-state"><Spinner /></div>}
      <ErrorAlert error={history.error} onRetry={() => history.refetch()} />

      {h && (
        <>
          <dl className="spending-summary" aria-label={`Totaux sur les ${period}`}>
            <div><dt>Déposé</dt><dd><MoneyText value={sum(rows, 'deposits')} /></dd></div>
            <div><dt>Misé</dt><dd><MoneyText value={sum(rows, 'stakes')} /></dd></div>
            <div><dt>Gagné</dt><dd><MoneyText value={sum(rows, 'payouts')} /></dd></div>
            <div><dt>Résultat net</dt><dd><Net value={sum(rows, 'net')} /></dd></div>
          </dl>
          <p className="muted small">Totaux sur les {period}, à l'heure d'Haïti.</p>

          {visible.length === 0 ? (
            <p className="rg-card muted">Aucun dépôt, retrait ni pari sur les {period}.</p>
          ) : (
            <div className="table-wrap">
              <table className="table spending-table">
                <caption className="sr-only">Dépenses {view === 'days' ? 'par jour' : 'par mois'}</caption>
                <thead>
                  <tr>
                    <th scope="col">{view === 'days' ? 'Jour' : 'Mois'}</th>
                    <th scope="col" className="num">Dépôts</th>
                    <th scope="col" className="num">Retraits</th>
                    <th scope="col" className="num">Mises</th>
                    <th scope="col" className="num">Gains</th>
                    <th scope="col" className="num">Remboursements</th>
                    <th scope="col" className="num">Résultat net</th>
                  </tr>
                </thead>
                <tbody>
                  {visible.map((r) => (
                    <tr key={r.key}>
                      <th scope="row" className="spending-period">{r.label}</th>
                      <td className="num" data-label="Dépôts"><MoneyText value={r.deposits} /></td>
                      <td className="num" data-label="Retraits"><MoneyText value={r.withdrawals} /></td>
                      <td className="num" data-label="Mises"><MoneyText value={r.stakes} /></td>
                      <td className="num" data-label="Gains"><MoneyText value={r.payouts} /></td>
                      <td className="num" data-label="Remboursements"><MoneyText value={r.refunds} /></td>
                      <td className="num" data-label="Résultat net"><Net value={r.net} /></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  );
}
