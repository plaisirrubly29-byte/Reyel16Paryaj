import { useSearchParams } from 'react-router-dom';
import { SportsSidebar } from '../../catalogue/components/SportsSidebar';
import { Alert } from '../../../shared/components/Alert';
import { Icon } from '../../../shared/components/Icon';
import { Spinner } from '../../../shared/components/Spinner';
import { useLotteryDraws, useLotteryResults, type LotteryDraw } from '../api/lottery';
import { LotteryBalls } from '../components/LotteryBalls';
import { LotterySlip } from '../components/LotterySlip';
import { formatCountdown, formatDrawMoment, formatResultDate } from '../format';

/** Tirage proposé par défaut : celui qui ferme le plus tôt. */
function soonest(draws: LotteryDraw[]): LotteryDraw | undefined {
  return [...draws]
    .filter((d) => d.next_draw_at)
    .sort((a, b) => Date.parse(a.next_draw_at!) - Date.parse(b.next_draw_at!))[0] ?? draws[0];
}

/**
 * Borlette : les tirages du jour (heure, temps restant, dernières boules sorties), puis pour le tirage choisi
 * l'historique des résultats et la fiche pour jouer. Le tirage choisi reste dans l'URL (?tirage=NYM).
 */
export function LotteryPage() {
  const draws = useLotteryDraws();
  const [params, setParams] = useSearchParams();
  const list = draws.data ?? [];
  const selected = list.find((d) => d.key === params.get('tirage')) ?? soonest(list);

  function select(key: string) {
    const p = new URLSearchParams(params);
    p.set('tirage', key);
    setParams(p, { replace: true });
  }

  return (
    <div className="sportsbook">
      <SportsSidebar />

      <div className="sportsbook-main lottery">
        <section className="lottery-hero" aria-labelledby="lottery-title">
          <span className="lottery-hero-icon" aria-hidden="true"><Icon name="lottery" size={30} /></span>
          <div>
            <h1 id="lottery-title">Borlette</h1>
            <p>Tirages de Floride et de New York, résultats publiés en direct. Choisissez un tirage pour voir ses boules et jouer.</p>
          </div>
        </section>

        {draws.isPending ? (
          <Spinner label="Chargement des tirages…" />
        ) : list.length === 0 ? (
          <Alert tone="info" title="Borlette indisponible">
            <p>Aucun tirage n&apos;est proposé pour le moment. Revenez un peu plus tard.</p>
          </Alert>
        ) : (
          <>
            <ul className="lottery-draws" aria-label="Tirages">
              {list.map((d) => (
                <li key={d.key}>
                  <button
                    type="button"
                    className={`lottery-draw${d.key === selected?.key ? ' active' : ''}`}
                    aria-pressed={d.key === selected?.key}
                    onClick={() => select(d.key)}
                  >
                    <span className="lottery-draw-head">
                      <strong className="lottery-draw-name">{d.name}</strong>
                      <span className="lottery-draw-time"><Icon name="clock" size={14} /> {d.draw_time}</span>
                    </span>
                    {d.next_draw_at && (
                      <span className="lottery-draw-next">
                        Prochain : {formatDrawMoment(d.next_draw_at, d.timezone)}
                        <span className="lottery-draw-countdown">dans {formatCountdown(d.next_draw_at)}</span>
                      </span>
                    )}
                    {d.latest_result ? (
                      <span className="lottery-draw-result">
                        <span className="lottery-draw-date">{formatResultDate(d.latest_result.date)}</span>
                        <LotteryBalls result={d.latest_result} />
                      </span>
                    ) : (
                      <span className="lottery-draw-none">Pas encore de résultat publié</span>
                    )}
                  </button>
                </li>
              ))}
            </ul>

            {selected && (
              <div className="lottery-detail">
                <LotteryHistory draw={selected} />
                <LotterySlip key={selected.key} draw={selected} />
              </div>
            )}
          </>
        )}
      </div>
    </div>
  );
}

/** Derniers résultats publiés d'un tirage. */
function LotteryHistory({ draw }: { draw: LotteryDraw }) {
  const results = useLotteryResults(draw.key);
  const list = results.data?.data ?? [];

  return (
    <section className="lottery-history" aria-labelledby="lottery-history-title">
      <h2 id="lottery-history-title">Résultats : {draw.name}</h2>
      {results.isPending ? (
        <Spinner label="Chargement des résultats…" />
      ) : list.length === 0 ? (
        <p className="lottery-empty">Aucun résultat publié pour ce tirage pour l&apos;instant.</p>
      ) : (
        <ol className="lottery-history-list">
          {list.map((r) => (
            <li key={`${r.draw.key}-${r.date}`}>
              <span className="lottery-history-date">{formatResultDate(r.date)}</span>
              <LotteryBalls result={r} />
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
