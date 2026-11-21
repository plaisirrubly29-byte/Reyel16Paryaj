import { useState, type FormEvent } from 'react';
import { ApiError, newIdempotencyKey, type Money } from '../../../shared/api/client';
import { Alert } from '../../../shared/components/Alert';
import { Button } from '../../../shared/components/Button';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Pagination } from '../../../shared/components/Pagination';
import { Spinner } from '../../../shared/components/Spinner';
import { TextField } from '../../../shared/components/TextField';
import { formatMoney } from '../../../shared/format/money';
import { LoyaltySoon } from '../../account/ComingSoonPage';
import {
  isNotOpen, useLoyalty, useLoyaltyHistory, useRedeemPoints,
  type BonusTerms, type BonusWagering, type LoyaltyState,
} from '../api/rewards';

const fmtDate = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });
const fmtPoints = (n: number) => new Intl.NumberFormat('fr-FR').format(n);

/** Bonus obtenu pour `points` au taux du palier (taux donné pour 100 points), arrondi au centime inférieur. */
function bonusFor(points: number, rate: Money): Money {
  return { minor: Math.floor((points * rate.minor) / 100), currency: rate.currency };
}

/** Points de fidélité (V2-E07, D-19) : solde et palier, échange contre un bonus, conditions de mise, historique. */
export function LoyaltyPage() {
  const loyalty = useLoyalty();

  if (loyalty.isPending) return <div className="table-state"><Spinner /></div>;
  if (isNotOpen(loyalty.error)) return <LoyaltySoon />;
  if (!loyalty.data) return <ErrorAlert error={loyalty.error} onRetry={() => loyalty.refetch()} />;

  const state = loyalty.data;
  return (
    <section className="rewards stack">
      <h1>Points de fidélité</h1>
      <LoyaltyHero state={state} />
      <RedeemCard state={state} />
      {(state.bonus_balance.minor > 0 || state.wagering.length > 0) && <BonusCard state={state} />}
      <HowToEarn state={state} />
      <LoyaltyHistory />
    </section>
  );
}

function LoyaltyHero({ state }: { state: LoyaltyState }) {
  const next = state.next_tier;
  const progress = next ? Math.min(100, Math.round((state.points_12_months / next.min_points) * 100)) : 100;

  return (
    <div className={`rewards-hero tier-${state.tier.key}`}>
      <div className="rewards-hero-top">
        <span className="tier-badge"><Icon name="star" size={16} />{state.tier.label}</span>
        {/* Valeur des points échangeables (par multiples de exchange.step), pas du solde brut. */}
        <span className="rewards-hero-worth">Valent <MoneyText value={bonusFor(Math.floor(state.points / state.exchange.step) * state.exchange.step, state.tier.rate)} /> de bonus</span>
      </div>
      <p className="rewards-points"><strong>{fmtPoints(state.points)}</strong> points</p>
      {next ? (
        <div className="tier-progress">
          <div className="tier-progress-bar" role="progressbar" aria-label={`Progression vers le palier ${next.label}`}
            aria-valuemin={0} aria-valuemax={next.min_points} aria-valuenow={state.points_12_months}>
            <span style={{ width: `${progress}%` }} />
          </div>
          <p>Encore <strong>{fmtPoints(Math.max(0, next.min_points - state.points_12_months))} points</strong> sur 12 mois pour passer {next.label}.</p>
        </div>
      ) : <p className="tier-progress-max">Vous êtes au palier le plus haut.</p>}
      {state.points_expiring && (
        <p className="rewards-expiring"><Icon name="clock" size={14} />{fmtPoints(state.points_expiring.points)} points expirent le {fmtDate(state.points_expiring.expires_at)}.</p>
      )}
    </div>
  );
}

function RedeemCard({ state }: { state: LoyaltyState }) {
  const redeem = useRedeemPoints();
  const [value, setValue] = useState('');
  const [key, setKey] = useState(newIdempotencyKey);
  const { min_points: min, step } = state.exchange;
  const max = Math.floor(state.points / step) * step;
  const points = Number(value) || 0;
  const problem = !value ? null
    : points < min ? `Échangez au moins ${fmtPoints(min)} points.`
      : points % step !== 0 ? `Par multiples de ${fmtPoints(step)} points.`
        : points > state.points ? 'Vous n\'avez pas assez de points.' : null;
  const error = redeem.error instanceof ApiError ? redeem.error : null;

  function submit(e: FormEvent) {
    e.preventDefault();
    if (!points || problem) return;
    redeem.mutate({ points, idempotencyKey: key }, {
      onSuccess: () => { setValue(''); setKey(newIdempotencyKey()); },
      onError: (err) => { if (err instanceof ApiError && err.status !== 0) setKey(newIdempotencyKey()); },
    });
  }

  return (
    <form className="rewards-card" onSubmit={submit} noValidate aria-labelledby="redeem-title">
      <h2 id="redeem-title">Échanger mes points</h2>
      {/* Hors de la condition : après un échange, le solde restant peut passer sous le minimum. */}
      {redeem.isSuccess && (
        <Alert tone="success" title="Points échangés">Le bonus a été ajouté à votre solde bonus, avec les conditions de mise ci-dessous.</Alert>
      )}
      {state.points < min ? (
        <p className="muted">Il vous faut au moins <strong>{fmtPoints(min)} points</strong> pour échanger. Continuez à parier : chaque pari réglé vous en rapporte.</p>
      ) : (
        <>
          {error && error.code !== 'validation_failed' && <ErrorAlert error={error} />}
          <TextField
            label="Points à échanger"
            inputMode="numeric"
            name="redeem-points"
            autoComplete="off"
            placeholder={String(min)}
            value={value}
            onChange={(e) => setValue(e.target.value.replace(/\D/g, ''))}
            hint={`De ${fmtPoints(min)} à ${fmtPoints(max)} points, par ${fmtPoints(step)}`}
            error={error?.fieldErrors.points ?? problem ?? undefined}
          />
          <div className="quick-amounts" role="group" aria-label="Points rapides">
            <button type="button" className="chip" onClick={() => setValue(String(min))}>{fmtPoints(min)}</button>
            {max > min && <button type="button" className="chip" onClick={() => setValue(String(max))}>Tout ({fmtPoints(max)})</button>}
          </div>
          <Button type="submit" block loading={redeem.isPending} disabled={!points || !!problem}>
            {points && !problem ? `Échanger contre ${formatMoney(bonusFor(points, state.tier.rate))}` : 'Échanger'}
          </Button>
          <BonusTermsNote terms={state.bonus_terms} />
        </>
      )}
    </form>
  );
}

/** Conditions de mise de tout bonus, affichées avant l'échange (« conditions de mise affichées »). */
function BonusTermsNote({ terms }: { terms: BonusTerms }) {
  return (
    <p className="rewards-terms">
      <Icon name="alert" size={14} />
      Bonus à miser {terms.wagering_multiplier} fois, à une cote d'au moins {terms.min_odds.replace('.', ',')}, dans les {terms.days} jours, avant de pouvoir être retiré.
    </p>
  );
}

function BonusCard({ state }: { state: LoyaltyState }) {
  return (
    <section className="rewards-card" aria-labelledby="bonus-title">
      <div className="rewards-card-head">
        <h2 id="bonus-title">Solde bonus</h2>
        <strong className="rewards-bonus"><MoneyText value={state.bonus_balance} /></strong>
      </div>
      {state.wagering.length === 0
        ? <p className="muted small">Aucune condition de mise en cours.</p>
        : <ul className="wagering-list">{state.wagering.map((w) => <WageringRow key={w.expires_at + w.amount.minor} w={w} />)}</ul>}
    </section>
  );
}

function WageringRow({ w }: { w: BonusWagering }) {
  const left = { minor: Math.max(0, w.required.minor - w.wagered.minor), currency: w.required.currency };
  const pct = w.required.minor > 0 ? Math.min(100, Math.round((w.wagered.minor / w.required.minor) * 100)) : 100;

  return (
    <li className="wagering">
      <div className="wagering-top">
        <span>Bonus de <MoneyText value={w.amount} /></span>
        <span className="muted small">avant le {fmtDate(w.expires_at)}</span>
      </div>
      <div className="tier-progress-bar" role="progressbar" aria-label="Conditions de mise remplies" aria-valuemin={0} aria-valuemax={100} aria-valuenow={pct}>
        <span style={{ width: `${pct}%` }} />
      </div>
      <p className="small">
        {left.minor > 0
          ? <>Misez encore <strong><MoneyText value={left} /></strong> à une cote d'au moins {w.min_odds.replace('.', ',')}.</>
          : 'Conditions remplies : le bonus devient retirable.'}
      </p>
    </li>
  );
}

function HowToEarn({ state }: { state: LoyaltyState }) {
  return (
    <section className="rewards-card" aria-labelledby="earn-title">
      <h2 id="earn-title">Comment gagner des points</h2>
      <p className="rewards-rule">
        <Icon name="tickets" size={18} />
        <span><strong>{state.earn_rule.points_per_step} point</strong> par tranche de <MoneyText value={state.earn_rule.stake_step} /> misés, crédité quand le pari est réglé (gagné ou perdu). Les paris annulés et les mises du solde bonus ne comptent pas.</span>
      </p>
      <ul className="tier-list" aria-label="Paliers de fidélité">
        {state.tiers.map((t) => (
          <li key={t.key} className={`tier-item tier-${t.key}${t.key === state.tier.key ? ' is-current' : ''}`}>
            <span className="tier-badge"><Icon name="star" size={14} />{t.label}</span>
            <span className="small">{t.min_points === 0 ? 'Dès l\'inscription' : `${fmtPoints(t.min_points)} pts / 12 mois`}</span>
            <strong className="small">100 pts = <MoneyText value={t.rate} /></strong>
          </li>
        ))}
      </ul>
    </section>
  );
}

function LoyaltyHistory() {
  const [page, setPage] = useState(1);
  const history = useLoyaltyHistory(page);
  const entries = history.data?.data ?? [];

  return (
    <section className="rewards-card" aria-labelledby="history-title">
      <h2 id="history-title">Historique des points</h2>
      {history.isPending && <Spinner />}
      <ErrorAlert error={history.error} onRetry={() => history.refetch()} />
      {history.data && entries.length === 0 && <p className="muted small">Aucun mouvement pour le moment.</p>}
      {entries.length > 0 && (
        <ul className="recent-ops">
          {entries.map((e) => (
            <li key={e.id}>
              <span>
                <strong>{e.label}</strong>
                <span className="muted small">{new Date(e.created_at).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' })}{e.bonus && <> · <MoneyText value={e.bonus} /> de bonus</>}</span>
              </span>
              <strong className={`points-delta points-${e.type}`}>
                {e.points >= 0 ? '+' : '−'}{fmtPoints(Math.abs(e.points))} pts
              </strong>
            </li>
          ))}
        </ul>
      )}
      {history.data && <Pagination meta={history.data.meta} onPage={setPage} />}
    </section>
  );
}
