import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Alert } from '../../../shared/components/Alert';
import { ErrorAlert } from '../../../shared/components/ErrorAlert';
import { Icon } from '../../../shared/components/Icon';
import { MoneyText } from '../../../shared/components/MoneyText';
import { Spinner } from '../../../shared/components/Spinner';
import { formatMoney } from '../../../shared/format/money';
import { ReferralSoon } from '../../account/ComingSoonPage';
import { isNotOpen, useReferral, type Referral, type ReferralState, type ReferralStatus } from '../api/rewards';

const fmtDate = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

const STATUS_LABELS: Record<ReferralStatus, string> = {
  pending: 'En cours',
  qualified: 'Qualifié',
  rewarded: 'Récompensé',
  expired: 'Délai dépassé',
  blocked: 'En vérification',
};

/** Parrainage (V2-E07, D-19 §3) : code et lien à partager, règles, compteurs et suivi des filleuls. */
export function ReferralPage() {
  const referral = useReferral();

  if (referral.isPending) return <div className="table-state"><Spinner /></div>;
  if (isNotOpen(referral.error)) return <ReferralSoon />;
  if (!referral.data) return <ErrorAlert error={referral.error} onRetry={() => referral.refetch()} />;

  const state = referral.data;
  return (
    <section className="rewards stack">
      <h1>Parrainage</h1>
      <ReferralHero state={state} />
      {!state.eligible && (
        <Alert tone="warning" title="Vos récompenses sont en attente">
          Vérifiez votre identité pour recevoir les bonus de parrainage. <Link to="/verification">Vérifier mon identité</Link>
        </Alert>
      )}
      <ReferralStats state={state} />
      <HowItWorks state={state} />
      <ReferralList referrals={state.referrals} />
    </section>
  );
}

function ReferralHero({ state }: { state: ReferralState }) {
  const [note, setNote] = useState('');
  const message = `Rejoins-moi sur REYEL 16 PARYAJ avec mon code ${state.code} : ${state.link}`;

  async function copy(text: string, done: string) {
    try {
      await navigator.clipboard.writeText(text);
      setNote(done);
    } catch {
      setNote(`À copier : ${text}`);
    }
  }

  return (
    <div className="referral-hero">
      <span className="referral-hero-icon" aria-hidden="true"><Icon name="gift" size={26} /></span>
      <p className="referral-hero-title">
        Gagnez <strong><MoneyText value={state.reward} /></strong> de bonus pour chaque ami qualifié
      </p>
      <div className="referral-code">
        <span>Votre code</span>
        <strong>{state.code}</strong>
        <button type="button" className="referral-copy" onClick={() => copy(state.code, 'Code copié.')}>
          <Icon name="coupon" size={16} />Copier
        </button>
      </div>
      <div className="referral-actions">
        <a className="btn referral-whatsapp" href={`https://wa.me/?text=${encodeURIComponent(message)}`} target="_blank" rel="noopener noreferrer">
          Partager sur WhatsApp
        </a>
        <button type="button" className="btn btn-secondary" onClick={() => copy(state.link, 'Lien copié : envoyez-le à vos amis.')}>Copier le lien</button>
      </div>
      {note && <p role="status" className="referral-note">{note}</p>}
    </div>
  );
}

function ReferralStats({ state }: { state: ReferralState }) {
  return (
    <dl className="referral-stats">
      <div><dt>Filleuls</dt><dd>{state.referrals.length}</dd></div>
      <div><dt>Récompensés ce mois</dt><dd>{state.rewarded_this_month} / {state.rules.monthly_limit}</dd></div>
      <div><dt>Bonus gagnés</dt><dd><MoneyText value={state.total_rewards} /></dd></div>
    </dl>
  );
}

function HowItWorks({ state }: { state: ReferralState }) {
  const { rules, bonus_terms: terms } = state;
  const steps = [
    { icon: 'gift' as const, title: 'Partagez votre code', text: 'Votre ami s\'inscrit avec votre lien ou saisit votre code à l\'inscription.' },
    { icon: 'shield' as const, title: 'Il devient joueur', text: `Dans les ${rules.qualify_days} jours : identité vérifiée, dépôt d'au moins ${formatMoney(rules.min_deposit)} et ${formatMoney(rules.min_stake)} de paris réglés.` },
    { icon: 'wallet' as const, title: 'Vous recevez votre bonus', text: `${formatMoney(state.reward)} sur votre solde bonus, ${rules.monthly_limit} filleuls récompensés par mois au plus.` },
  ];

  return (
    <section className="rewards-card" aria-labelledby="how-title">
      <h2 id="how-title">Comment ça marche</h2>
      <ol className="referral-steps">
        {steps.map((s, i) => (
          <li key={s.title}>
            <span className="referral-step-icon" aria-hidden="true"><Icon name={s.icon} size={18} /><small>{i + 1}</small></span>
            <span><strong>{s.title}</strong><span>{s.text}</span></span>
          </li>
        ))}
      </ol>
      <p className="rewards-terms">
        <Icon name="alert" size={14} />
        Bonus à miser {terms.wagering_multiplier} fois, à une cote d'au moins {terms.min_odds.replace('.', ',')}, dans les {terms.days} jours, avant de pouvoir être retiré.
        Les comptes liés au vôtre (même téléphone, appareil ou pièce d'identité) ne comptent pas.
      </p>
    </section>
  );
}

function ReferralList({ referrals }: { referrals: Referral[] }) {
  return (
    <section className="rewards-card" aria-labelledby="referrals-title">
      <h2 id="referrals-title">Mes filleuls</h2>
      {referrals.length === 0 ? (
        <p className="muted small">Aucun filleul pour le moment. Partagez votre code pour inviter vos amis.</p>
      ) : (
        <ul className="referral-list">
          {referrals.map((r) => (
            <li key={r.id} className="referral-item">
              <div className="referral-item-top">
                <strong>{r.name}</strong>
                <span className={`referral-status referral-status-${r.status}`}>{STATUS_LABELS[r.status]}</span>
              </div>
              <span className="muted small">
                Inscrit le {fmtDate(r.joined_at)}{r.status === 'pending' && <> · à qualifier avant le {fmtDate(r.qualify_before)}</>}
              </span>
              <ul className="referral-checks" aria-label={`Étapes de ${r.name}`}>
                <Check done={r.steps.kyc} label="Identité vérifiée" />
                <Check done={r.steps.deposit} label="Dépôt" />
                <Check done={r.steps.stake} label="Paris" />
              </ul>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function Check({ done, label }: { done: boolean; label: string }) {
  return (
    <li className={done ? 'is-done' : undefined}>
      <Icon name={done ? 'check' : 'clock'} size={12} />{label}<span className="sr-only">{done ? ' : fait' : ' : en attente'}</span>
    </li>
  );
}
