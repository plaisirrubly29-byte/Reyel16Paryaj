import { useState, type FormEvent } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { ApiError, type Money } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { formatMoney } from '../../../shared/format/money';
import { useMe } from '../../auth/api/auth';
import { sanitizeStake, parseStakeMinor } from '../../betting/slip/stake';
import {
  isUncertainLotteryError, usePlaceLotteryTicket,
  type LotteryDraw, type LotteryGameKey, type LotteryGameOffer, type LotterySales, type LotteryTicket,
} from '../api/lottery';
import { drawDate, formatCountdown, formatDrawMoment, formatResultDate, salesCloseAt } from '../format';
import {
  gameHelp, gameName, lotLabel, optionLabel, playLabel, possibleWins, randomNumber, validatePlay, type DraftPlay,
} from '../games';

type Props = { draw: LotteryDraw; sales: LotterySales };

let nextId = 0;

/** Refus du serveur traduit pour le joueur : message général, erreurs par ligne, prochaine date proposée. */
type Refusal = { title: string; text: string; lines: Record<number, string>; nextDate?: string };

const UNCERTAIN = 'Connexion interrompue : votre fiche a peut-être été validée. Vérifiez « Mes paris » avant de changer quoi que ce soit. '
  + 'Renvoyer la même fiche ne la comptera jamais deux fois.';

function asMoney(value: unknown, currency: string): Money | null {
  if (typeof value === 'number') return { minor: value, currency };
  if (value && typeof value === 'object' && typeof (value as Money).minor === 'number') {
    return { minor: (value as Money).minor, currency: (value as Money).currency ?? currency };
  }
  return null;
}

function refusal(error: unknown, plays: DraftPlay[], currency: string): Refusal {
  if (!(error instanceof ApiError)) return { title: 'Fiche non validée', text: 'La fiche n\'a pas pu être envoyée. Réessayez.', lines: {} };
  if (isUncertainLotteryError(error)) return { title: 'Résultat inconnu', text: UNCERTAIN, lines: {} };

  const details = error.details;
  const index = typeof details.play === 'number' ? details.play : Number(details.play);
  const line = Number.isInteger(index) && index >= 0 && index < plays.length ? index : null;
  const numbers = Array.isArray(details.numbers) ? (details.numbers as string[]).join(', ') : line !== null ? plays[line].numbers.join(', ') : '';

  switch (error.code) {
    case 'lottery_draw_closed': {
      const nextDate = typeof details.next_date === 'string' ? details.next_date : undefined;
      return {
        title: 'Ventes fermées pour ce tirage',
        text: nextDate
          ? `Les ventes de ce tirage sont fermées. Le prochain tirage a lieu le ${formatResultDate(nextDate)} : vous pouvez jouer pour cette date.`
          : 'Les ventes de ce tirage sont fermées.',
        lines: {},
        nextDate,
      };
    }
    case 'insufficient_funds':
      return { title: 'Solde insuffisant', text: 'Votre solde ne suffit pas pour cette fiche. Déposez de l\'argent ou réduisez vos mises.', lines: {} };
    case 'lottery_number_blocked': {
      const text = `La boule ${numbers} n'est plus acceptée pour ce tirage. Retirez-la de la fiche.`;
      return { title: 'Boule bloquée', text, lines: line !== null ? { [line]: 'Boule bloquée pour ce tirage.' } : {} };
    }
    case 'lottery_number_limit': {
      const remaining = asMoney(details.remaining_stake, currency);
      const text = remaining && remaining.minor > 0
        ? `La boule ${numbers} est presque complète pour ce tirage : mise encore acceptée ${formatMoney(remaining)}.`
        : `La boule ${numbers} n'accepte plus de mise pour ce tirage.`;
      return {
        title: 'Plafond de la boule atteint', text,
        lines: line !== null ? { [line]: remaining && remaining.minor > 0 ? `Mise maximale : ${formatMoney(remaining)}.` : 'Plus de mise acceptée.' } : {},
      };
    }
    // Limites que le joueur s'est fixées (jeu responsable), comme pour un pari sportif.
    case 'stake_limit_exceeded':
    case 'loss_limit_exceeded': {
      const remaining = asMoney(details.remaining_minor, currency);
      const text = remaining
        ? remaining.minor > 0
          ? `${error.message} Mise totale encore possible : ${formatMoney(remaining)}.`
          : `${error.message} Vous ne pouvez plus jouer sur cette période.`
        : error.message;
      return { title: 'Limite de mise atteinte', text, lines: {} };
    }
    case 'betting_paused':
      return { title: 'Jeu en pause', text: 'Vos paris sont en pause (pause ou auto-exclusion) : la fiche ne peut pas être validée.', lines: {} };
    case 'lottery_sales_closed':
      return { title: 'Vente fermée', text: 'La vente de fiches borlette est fermée pour le moment.', lines: {} };
    case 'unauthenticated':
      return { title: 'Session expirée', text: 'Reconnectez-vous pour valider votre fiche.', lines: {} };
    case 'validation_failed': {
      const lines: Record<number, string> = {};
      const others: string[] = [];
      for (const [field, messages] of Object.entries(details)) {
        const message = Array.isArray(messages) ? String(messages[0]) : String(messages);
        const match = /^plays\.(\d+)\./.exec(field);
        if (match && Number(match[1]) < plays.length) lines[Number(match[1])] = message;
        else others.push(message);
      }
      const text = others.length > 0 ? others.join(' ') : 'Corrigez les lignes signalées.';
      return { title: 'Vérifiez la fiche', text, lines };
    }
    default:
      return { title: 'Fiche refusée', text: error.message, lines: {} };
  }
}

/**
 * Fiche de borlette d'un tirage : on ajoute des lignes (jeu, boules, mise), on vérifie le récapitulatif, puis on
 * confirme. Jeux, options, mises min/max et barème viennent du serveur (GET /lottery/games) ; le serveur revalide tout
 * et débite le total.
 */
export function LotterySlip({ draw, sales }: Props) {
  const me = useMe();
  const user = me.data ?? null;
  const location = useLocation();
  const place = usePlaceLotteryTicket();
  const games = sales.games;

  const [gameKey, setGameKey] = useState<LotteryGameKey | undefined>(games[0]?.key);
  const game: LotteryGameOffer | undefined = games.find((g) => g.key === gameKey) ?? games[0];
  const [numbers, setNumbers] = useState<string[]>(() => Array.from({ length: game?.count ?? 1 }, () => ''));
  const [option, setOption] = useState<number | null>(game?.options[0] ?? null);
  const [stake, setStake] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [plays, setPlays] = useState<DraftPlay[]>([]);
  const [confirming, setConfirming] = useState(false);
  /** Date choisie par le joueur après un « tirage fermé » ; sinon la prochaine occurrence du tirage. */
  const [chosenDate, setChosenDate] = useState<string | null>(null);
  const [ticket, setTicket] = useState<LotteryTicket | null>(null);

  const currency = game?.min_stake.currency ?? 'HTG';
  const nextDate = draw.next_draw_at ? drawDate(draw.next_draw_at, draw.timezone) : null;
  const date = chosenDate ?? nextDate;
  const closesAt = draw.next_draw_at && !chosenDate ? salesCloseAt(draw.next_draw_at, sales.close_before_minutes) : null;
  const total: Money = { minor: plays.reduce((sum, p) => sum + p.stake.minor, 0), currency };
  const full = plays.length >= sales.max_plays_per_ticket;
  const refused = place.error ? refusal(place.error, plays, currency) : null;
  const stakeMinor = parseStakeMinor(stake);
  const preview = game && stakeMinor !== null ? possibleWins(game, { minor: Number(stakeMinor), currency }) : [];

  if (!sales.sales_open || games.length === 0 || !game) {
    return (
      <section className="lottery-slip" aria-labelledby="lottery-slip-title">
        <h2 id="lottery-slip-title"><Icon name="coupon" size={20} /> Fiche : {draw.name}</h2>
        <Alert tone="info" title="Vente fermée">
          <p>La vente de fiches borlette est fermée pour le moment. Les résultats restent consultables.</p>
        </Alert>
      </section>
    );
  }

  /** Toute modification de la fiche efface l'ancien refus (ses numéros de ligne ne correspondent plus). */
  function edited() {
    place.reset();
    setConfirming(false);
  }

  function chooseGame(key: LotteryGameKey) {
    const g = games.find((x) => x.key === key);
    if (!g) return;
    setGameKey(key);
    setNumbers(Array.from({ length: g.count }, () => ''));
    setOption(g.options[0] ?? null);
    setError(null);
  }

  function setNumber(i: number, value: string) {
    setNumbers((current) => current.map((n, j) => (j === i ? value.replace(/\D/g, '').slice(0, game!.digits) : n)));
  }

  function addPlay(e: FormEvent) {
    e.preventDefault();
    if (!game) return;
    if (full) {
      setError(`Une fiche compte au plus ${sales.max_plays_per_ticket} lignes.`);
      return;
    }
    const message = validatePlay(game, numbers, stake, option);
    if (message) {
      setError(message);
      return;
    }
    const line: DraftPlay = {
      id: `p${++nextId}`,
      game: game.key,
      option: game.options.length > 0 ? option : null,
      numbers,
      stake: { minor: Number(parseStakeMinor(stake)), currency },
    };
    setPlays((current) => [...current, line]);
    setNumbers(Array.from({ length: game.count }, () => ''));
    setError(null);
    setTicket(null);
    edited();
  }

  function removePlay(id: string) {
    setPlays((current) => current.filter((x) => x.id !== id));
    edited();
  }

  function submit() {
    if (!date) return;
    place.mutate(
      { draw: draw.key, date, plays: plays.map(({ game: g, option: o, numbers: n, stake: s }) => ({ game: g, option: o, numbers: n, stake: s })) },
      {
        onSuccess: (accepted) => {
          setTicket(accepted);
          setPlays([]);
          setChosenDate(null);
          setConfirming(false);
        },
        onError: () => setConfirming(false),
      },
    );
  }

  function playFor(next: string) {
    setChosenDate(next);
    place.reset();
    setConfirming(true);
  }

  return (
    <section className="lottery-slip" aria-labelledby="lottery-slip-title">
      <header className="lottery-slip-head">
        <h2 id="lottery-slip-title"><Icon name="coupon" size={20} /> Fiche : {draw.name}</h2>
        {chosenDate ? (
          <p className="lottery-slip-closes">Tirage du {formatResultDate(chosenDate)}</p>
        ) : draw.next_draw_at && closesAt && (
          <p className="lottery-slip-closes">
            Tirage {formatDrawMoment(draw.next_draw_at, draw.timezone)} · fin des ventes {formatDrawMoment(closesAt, draw.timezone)}
            {Date.parse(closesAt) > Date.now() && <> (dans {formatCountdown(closesAt)})</>}
          </p>
        )}
      </header>

      <form className="lottery-form" onSubmit={addPlay} noValidate>
        <div className="lottery-games" role="group" aria-label="Jeu">
          {games.map((g) => (
            <button
              key={g.key}
              type="button"
              className={g.key === game.key ? 'active' : undefined}
              aria-pressed={g.key === game.key}
              onClick={() => chooseGame(g.key)}
            >
              {gameName(g.key)}
            </button>
          ))}
        </div>
        <p className="lottery-help">
          {gameHelp(game.key)} Mise de {formatMoney(game.min_stake)} à {formatMoney(game.max_stake)}.
        </p>

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
              {game.options.map((o) => <option key={o} value={o}>{optionLabel(game.key, o)}</option>)}
            </select>
          </label>
        )}

        <div className="lottery-stake-row">
          <label className="lottery-stake">
            <span>Mise ({currency})</span>
            <input inputMode="decimal" autoComplete="off" placeholder="50" value={stake} onChange={(e) => setStake(sanitizeStake(e.target.value))} />
          </label>
          <button type="submit" className="btn btn-sm btn-navy" disabled={full}>Ajouter à la fiche</button>
        </div>
        {preview.length > 0 && (
          <p className="lottery-help" aria-live="polite">
            Gain possible : {preview.map((p) => (p.lot ? `${lotLabel(p.lot)} ${formatMoney(p.win)}` : formatMoney(p.win))).join(' · ')}
          </p>
        )}
        {full && <p className="lottery-help">Une fiche compte au plus {sales.max_plays_per_ticket} lignes.</p>}
        {error && <p className="lottery-error" role="alert">{error}</p>}
      </form>

      {ticket && <Receipt ticket={ticket} />}

      {plays.length === 0 ? (
        !ticket && <p className="lottery-empty">Votre fiche est vide : ajoutez une boule.</p>
      ) : (
        <ul className="lottery-plays" aria-label="Lignes de la fiche">
          {plays.map((p, i) => {
            const offer = games.find((g) => g.key === p.game);
            const wins = offer ? possibleWins(offer, p.stake) : [];
            const lineError = refused?.lines[i];
            return (
              <li key={p.id} className={lineError ? 'has-error' : undefined}>
                <span className="lottery-play-game">{gameName(p.game)}</span>
                <span className="lottery-play-numbers">{playLabel(p)}</span>
                <MoneyText value={p.stake} />
                <button
                  type="button"
                  className="lottery-play-remove"
                  aria-label={`Retirer ${gameName(p.game)} ${playLabel(p)}`}
                  onClick={() => removePlay(p.id)}
                >
                  <Icon name="trash" size={18} />
                </button>
                {wins.length > 0 && (
                  <span className="lottery-play-wins">
                    Gain possible : {wins.map((w) => (w.lot ? `${lotLabel(w.lot)} ${formatMoney(w.win)}` : formatMoney(w.win))).join(' · ')}
                  </span>
                )}
                {lineError && <span className="lottery-play-error">{lineError}</span>}
              </li>
            );
          })}
        </ul>
      )}

      <div className="lottery-total">
        <span>Total</span>
        <strong><MoneyText value={total} /></strong>
      </div>

      {refused && (
        <Alert tone="error" title={refused.title}>
          <p>{refused.text}</p>
          {refused.nextDate && plays.length > 0 && (
            <button type="button" className="btn btn-sm btn-secondary" onClick={() => playFor(refused.nextDate!)}>
              Jouer pour le {formatResultDate(refused.nextDate)}
            </button>
          )}
          {place.error instanceof ApiError && place.error.code === 'insufficient_funds' && (
            <Link to="/wallet" className="btn btn-sm btn-secondary">Déposer</Link>
          )}
        </Alert>
      )}

      {!user ? (
        <Link to={`/login?redirect=${encodeURIComponent(location.pathname + location.search)}`} className="btn btn-primary btn-block">
          Se connecter pour jouer
        </Link>
      ) : confirming && plays.length > 0 && date ? (
        <div className="lottery-confirm" role="group" aria-label="Confirmer la fiche">
          <p>
            {draw.name}, tirage du {formatResultDate(date)} : {plays.length} ligne{plays.length > 1 ? 's' : ''},{' '}
            <strong>{formatMoney(total)}</strong> débités de votre solde.
          </p>
          <div className="lottery-confirm-actions">
            <button type="button" className="btn btn-secondary" onClick={() => setConfirming(false)} disabled={place.isPending}>Modifier</button>
            <button type="button" className="btn btn-primary" onClick={submit} disabled={place.isPending}>
              {place.isPending ? 'Envoi…' : 'Confirmer et payer'}
            </button>
          </div>
        </div>
      ) : (
        <button type="button" className="btn btn-primary btn-block" disabled={plays.length === 0 || !date} onClick={() => setConfirming(true)}>
          Valider la fiche
        </button>
      )}
    </section>
  );
}

/** Reçu d'une fiche acceptée : référence, tirage, total débité, lien vers « Mes paris ». */
function Receipt({ ticket }: { ticket: LotteryTicket }) {
  return (
    <Alert tone="success" title="Fiche validée">
      <p>Référence : <strong className="mono">{ticket.reference}</strong></p>
      <p>
        {ticket.draw.name}, tirage du {formatResultDate(ticket.draw.date)} · {ticket.plays.length} ligne{ticket.plays.length > 1 ? 's' : ''} ·
        mise {formatMoney(ticket.stake)} débitée · gain maximal {formatMoney(ticket.max_payout)}.
      </p>
      <Link to="/bets?produit=borlette" className="btn btn-sm btn-secondary">Voir dans Mes paris</Link>
    </Alert>
  );
}
