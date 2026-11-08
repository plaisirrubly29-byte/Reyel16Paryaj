import { useState, type FormEvent } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { useMe } from '../../auth/api/auth';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { LOTTERY_SALES_OPEN, usePlaceLotteryTicket, type LotteryDraw } from '../api/lottery';
import { formatCountdown, formatDrawMoment } from '../format';
import { LOTTERY_GAMES, lotteryGame, playLabel, randomNumber, validatePlay, type LotteryGameKey, type LotteryPlay } from '../games';

type Props = { draw: LotteryDraw };

/** Devise de la plateforme (décision Phase 0) ; le serveur reste maître du montant débité. */
const LOTTERY_CURRENCY = 'HTG';

let nextId = 0;

/**
 * Fiche de borlette d'un tirage : on ajoute des lignes (jeu, boules, mise), puis on valide la fiche.
 * Tant que la vente n'est pas ouverte côté serveur (LOTTERY_SALES_OPEN), la fiche se prépare mais ne s'envoie pas.
 */
export function LotterySlip({ draw }: Props) {
  const me = useMe();
  const user = me.data ?? null;
  const currency = LOTTERY_CURRENCY;
  const location = useLocation();
  const place = usePlaceLotteryTicket();

  const [gameKey, setGameKey] = useState<LotteryGameKey>('bolet');
  const [numbers, setNumbers] = useState<string[]>(['']);
  const [option, setOption] = useState<number | null>(null);
  const [stake, setStake] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [plays, setPlays] = useState<LotteryPlay[]>([]);

  const game = lotteryGame(gameKey);
  const total = plays.reduce((sum, p) => sum + p.stake, 0);
  const closesAt = draw.next_draw_at;

  function chooseGame(key: LotteryGameKey) {
    const g = lotteryGame(key);
    setGameKey(key);
    setNumbers(Array.from({ length: g.count }, () => ''));
    setOption(g.options[0]?.value ?? null);
    setError(null);
  }

  function setNumber(i: number, value: string) {
    setNumbers((current) => current.map((n, j) => (j === i ? value.replace(/\D/g, '').slice(0, game.digits) : n)));
  }

  function addPlay(e: FormEvent) {
    e.preventDefault();
    const message = validatePlay(game, numbers, stake, option);
    if (message) {
      setError(message);
      return;
    }
    setPlays((current) => [...current, { id: `p${++nextId}`, game: game.key, option: game.options.length > 0 ? option : null, numbers, stake: Number(stake) }]);
    setNumbers(Array.from({ length: game.count }, () => ''));
    setError(null);
    place.reset();
  }

  function submit() {
    place.mutate({ draw: draw.key, plays, currency }, { onSuccess: () => setPlays([]) });
  }

  return (
    <section className="lottery-slip" aria-labelledby="lottery-slip-title">
      <header className="lottery-slip-head">
        <h2 id="lottery-slip-title"><Icon name="coupon" size={20} /> Fiche : {draw.name}</h2>
        {closesAt && (
          <p className="lottery-slip-closes">
            Tirage {formatDrawMoment(closesAt, draw.timezone)} · ferme dans {formatCountdown(closesAt)}
          </p>
        )}
      </header>

      <form className="lottery-form" onSubmit={addPlay} noValidate>
        <div className="lottery-games" role="group" aria-label="Jeu">
          {LOTTERY_GAMES.map((g) => (
            <button
              key={g.key}
              type="button"
              className={g.key === gameKey ? 'active' : undefined}
              aria-pressed={g.key === gameKey}
              onClick={() => chooseGame(g.key)}
            >
              {g.name}
            </button>
          ))}
        </div>
        <p className="lottery-help">{game.help}</p>

        <div className="lottery-inputs">
          {numbers.map((n, i) => (
            <label key={i} className="lottery-number">
              <span>{game.count > 1 ? `Boule ${i + 1}` : 'Numéro'}</span>
              <input
                inputMode="numeric"
                autoComplete="off"
                placeholder={'0'.repeat(game.digits)}
                maxLength={game.digits}
                value={n}
                onChange={(e) => setNumber(i, e.target.value)}
              />
            </label>
          ))}
          <button
            type="button"
            className="btn btn-sm btn-secondary lottery-random"
            onClick={() => setNumbers(numbers.map(() => randomNumber(game.digits)))}
          >
            Au hasard
          </button>
        </div>

        {game.options.length > 0 && (
          <label className="lottery-option">
            <span>Option</span>
            <select value={option ?? ''} onChange={(e) => setOption(Number(e.target.value))}>
              {game.options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </label>
        )}

        <div className="lottery-stake-row">
          <label className="lottery-stake">
            <span>Mise ({currency})</span>
            <input inputMode="numeric" autoComplete="off" placeholder="50" value={stake} onChange={(e) => setStake(e.target.value.replace(/\D/g, ''))} />
          </label>
          <button type="submit" className="btn btn-sm btn-navy">Ajouter à la fiche</button>
        </div>
        {error && <p className="lottery-error" role="alert">{error}</p>}
      </form>

      {plays.length === 0 ? (
        <p className="lottery-empty">Votre fiche est vide : ajoutez une boule.</p>
      ) : (
        <ul className="lottery-plays" aria-label="Lignes de la fiche">
          {plays.map((p) => (
            <li key={p.id}>
              <span className="lottery-play-game">{lotteryGame(p.game).name}</span>
              <span className="lottery-play-numbers">{playLabel(p)}</span>
              <MoneyText value={{ minor: p.stake * 100, currency }} />
              <button
                type="button"
                className="lottery-play-remove"
                aria-label={`Retirer ${lotteryGame(p.game).name} ${playLabel(p)}`}
                onClick={() => setPlays((current) => current.filter((x) => x.id !== p.id))}
              >
                <Icon name="trash" size={18} />
              </button>
            </li>
          ))}
        </ul>
      )}

      <div className="lottery-total">
        <span>Total</span>
        <strong><MoneyText value={{ minor: total * 100, currency }} /></strong>
      </div>

      {place.isSuccess && (
        <Alert tone="success" title="Fiche acceptée">
          <p>Référence : <strong>{place.data.data.reference}</strong></p>
        </Alert>
      )}
      <ErrorAlert error={place.error} />

      {!LOTTERY_SALES_OPEN ? (
        <>
          <button type="button" className="btn btn-primary btn-block" disabled>Valider la fiche</button>
          <p className="lottery-soon">La vente de fiches borlette ouvre bientôt. Vous pouvez déjà préparer votre fiche.</p>
        </>
      ) : !user ? (
        <Link to={`/login?redirect=${encodeURIComponent(location.pathname + location.search)}`} className="btn btn-primary btn-block">
          Se connecter pour jouer
        </Link>
      ) : (
        <button type="button" className="btn btn-primary btn-block" disabled={plays.length === 0 || place.isPending} onClick={submit}>
          {place.isPending ? 'Envoi…' : 'Valider la fiche'}
        </button>
      )}
    </section>
  );
}
