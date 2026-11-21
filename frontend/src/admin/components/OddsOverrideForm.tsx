import { useState } from 'react';
import { ApiError } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import type { AdminMarket, AdminSelection } from '../api/events';
import {
  deviationHundredths, oddsAbove, OVERRIDE_APPROVAL_THRESHOLD_PERCENT, percentLabel, useRequestOverride,
} from '../api/odds';
import { ReasonForm } from './ReasonForm';

/** Cote saisie : 2 décimales au plus, de 1,01 à 1 000 (même règle que l'API). */
const ODDS_PATTERN = /^\d{1,4}(\.\d{1,2})?$/;

function validOdds(value: string): boolean {
  if (!ODDS_PATTERN.test(value)) return false;
  const [units, decimals = ''] = value.split('.');
  const hundredths = Number(units) * 100 + Number(decimals.padEnd(2, '0'));
  return hundredths >= 101 && hundredths <= 100_000;
}

/**
 * Fixer une cote à la main (V2-D03, D-18 ; permission odds.override). L'écart avec la cote calculée est montré en
 * direct : au-delà du seuil, un second administrateur doit valider ; au-dessus de la meilleure cote suivie, risque
 * d'arbitrage. Le serveur recalcule et décide.
 */
export function OddsOverrideForm({ market, selections }: { market: AdminMarket; selections: AdminSelection[] }) {
  const request = useRequestOverride(market.id);
  const [selectionId, setSelectionId] = useState(selections[0]?.id ?? '');
  const [odds, setOdds] = useState('');
  const [expiresAt, setExpiresAt] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [result, setResult] = useState<'active' | 'pending' | null>(null);

  const selection = selections.find((s) => s.id === selectionId);
  const reference = selection?.computed_odds ?? selection?.odds ?? null;
  const normalized = odds.trim().replace(',', '.');
  const oddsValid = validOdds(normalized);
  const deviation = oddsValid && reference ? deviationHundredths(normalized, reference) : null;
  const needsApproval = deviation !== null && Math.abs(deviation) > OVERRIDE_APPROVAL_THRESHOLD_PERCENT * 100;
  const aboveBest = oddsValid && selection?.best_odds ? oddsAbove(normalized, selection.best_odds) : false;
  const id = `override-${market.id}`;

  async function send(reason: string, idempotencyKey: string) {
    setFieldErrors({});
    setResult(null);
    try {
      const created = await request.mutateAsync({
        selection_id: selectionId,
        odds: normalized,
        // Champ local (sans fuseau) converti en instant ISO ; vide : le début du match.
        expires_at: expiresAt ? new Date(expiresAt).toISOString() : null,
        reason,
        idempotencyKey,
      });
      setOdds('');
      setExpiresAt('');
      setResult(created.status === 'active' ? 'active' : 'pending');
    } catch (err) {
      if (err instanceof ApiError) setFieldErrors(err.fieldErrors);
      throw err;
    }
  }

  return (
    <ReasonForm
      id={id}
      title="Fixer une cote à la main"
      description={`La cote fixée remplace la cote calculée jusqu'à la date limite (au plus tard le début du match). Les tickets déjà placés gardent leur cote. Au-delà de ±${OVERRIDE_APPROVAL_THRESHOLD_PERCENT} % d'écart avec la cote calculée, un second administrateur doit valider.`}
      submitLabel="Fixer la cote"
      incomplete={!oddsValid || !selection}
      onSubmit={send}
    >
      {result === 'active' && <Alert tone="success">Cote fixée : elle est en vigueur.</Alert>}
      {result === 'pending' && <Alert tone="success">Cote envoyée : elle attend la validation d'un second administrateur.</Alert>}

      <div className={['field', fieldErrors.selection_id && 'field-invalid'].filter(Boolean).join(' ')}>
        <label htmlFor={`${id}-selection`}>Sélection</label>
        <select id={`${id}-selection`} value={selectionId} onChange={(e) => setSelectionId(e.target.value)}>
          {selections.map((s) => <option key={s.id} value={s.id}>{s.label} — {s.name} ({s.odds})</option>)}
        </select>
        {fieldErrors.selection_id && <small className="field-error" role="alert">{fieldErrors.selection_id}</small>}
      </div>

      <div className={['field', fieldErrors.odds && 'field-invalid'].filter(Boolean).join(' ')}>
        <label htmlFor={`${id}-odds`}>Nouvelle cote</label>
        <input
          id={`${id}-odds`}
          inputMode="decimal"
          maxLength={7}
          placeholder="ex. 2.05"
          value={odds}
          onChange={(e) => setOdds(e.target.value.replace(/[^\d.,]/g, ''))}
          aria-invalid={fieldErrors.odds ? true : undefined}
          aria-describedby={`${id}-odds-help`}
        />
        <small id={`${id}-odds-help`} className="muted">
          De 1,01 à 1 000, deux décimales au plus.{reference && <> Cote calculée : <strong>{reference}</strong>.</>}
          {deviation !== null && <> Écart : <strong>{percentLabel(deviation)}</strong>.</>}
        </small>
        {fieldErrors.odds && <small className="field-error" role="alert">{fieldErrors.odds}</small>}
      </div>

      {needsApproval && (
        <Alert tone="info">
          Écart de plus de {OVERRIDE_APPROVAL_THRESHOLD_PERCENT} % : la cote n'entrera en vigueur qu'après la <strong>double validation</strong> d'un autre administrateur.
        </Alert>
      )}
      {aboveBest && (
        <Alert tone="warning">
          Cette cote dépasse la meilleure cote suivie ({selection?.best_odds}) : <strong>risque d'arbitrage</strong> (un parieur peut miser chez nous et couvrir ailleurs).
        </Alert>
      )}

      <div className={['field', fieldErrors.expires_at && 'field-invalid'].filter(Boolean).join(' ')}>
        <label htmlFor={`${id}-expires`}>Date limite (facultative)</label>
        <input id={`${id}-expires`} type="datetime-local" value={expiresAt} onChange={(e) => setExpiresAt(e.target.value)} />
        <small className="muted">Sans date, la cote fixée prend fin au début du match ; la cote calculée revient alors.</small>
        {fieldErrors.expires_at && <small className="field-error" role="alert">{fieldErrors.expires_at}</small>}
      </div>
    </ReasonForm>
  );
}
