import { useState } from 'react';
import { useMe } from '../../features/auth/api/auth';
import { ApiError } from '../../shared/api/client';
import { Alert } from '../../shared/components/Alert';
import { DataTable, type Column } from '../../shared/components/DataTable';
import { ErrorAlert } from '../../shared/components/ErrorAlert';
import { Pagination } from '../../shared/components/Pagination';
import { Spinner } from '../../shared/components/Spinner';
import { formatMoney } from '../../shared/format/money';
import { dateTime } from '../api/events';
import { usePlatformSettings, useSaveSettings, useSettingVersions, type PlatformSettings, type Setting, type SettingType, type SettingValue, type SettingVersion } from '../api/settings';
import { PageHeader } from '../components/PageHeader';
import { ReasonForm } from '../components/ReasonForm';

/** Décimales de la devise (HTG : 2), comme formatMoney. */
const digitsOf = (currency: string) => new Intl.NumberFormat('fr-FR', { style: 'currency', currency }).resolvedOptions().maximumFractionDigits ?? 2;

/** 2500 (HTG) → « 25,00 » pour le champ de saisie. */
function minorToInput(minor: number, currency: string): string {
  const digits = digitsOf(currency);
  const abs = String(Math.abs(minor)).padStart(digits + 1, '0');
  return digits === 0 ? abs : `${abs.slice(0, -digits)},${abs.slice(-digits)}`;
}

/** « 25,5 » → 2550 ; null si la saisie n'est pas un montant positif valide (calcul sur le texte, sans flottant). */
function inputToMinor(text: string, currency: string): number | null {
  const digits = digitsOf(currency);
  const match = new RegExp(`^(\\d+)(?:[.,](\\d{0,${digits}}))?$`).exec(text.trim().replace(/\s/g, ''));
  if (!match) return null;
  return Number(match[1]) * 10 ** digits + Number((match[2] ?? '').padEnd(digits, '0') || 0);
}

function display(type: SettingType | null, value: SettingValue, currency: string | null): string {
  if (type === 'boolean') return value ? 'Oui' : 'Non';
  if (type === 'money' && currency) return formatMoney({ minor: Number(value), currency });
  return String(value);
}

/** Paramètres de la plateforme versionnés : consultation (settings.view), nouvelle version avec motif (settings.update). */
export function SettingsPage() {
  const { data: me } = useMe();
  const settings = usePlatformSettings();
  const s = settings.data;
  const canUpdate = Boolean(me?.permissions.includes('settings.update'));
  // Le formulaire est recréé à chaque version (key) : le message de succès vit ici.
  const [saved, setSaved] = useState<number | null>(null);
  const currency = s?.items.find((i) => i.currency)?.currency ?? 'HTG';

  return (
    <section className="admin-page">
      <PageHeader
        title="Paramètres"
        subtitle={s && (s.version === 0
          ? 'Valeurs de la configuration du serveur : aucune modification enregistrée.'
          : `Version ${s.version}, enregistrée le ${s.updated_at ? dateTime(s.updated_at) : '—'} par ${s.updated_by?.name ?? '—'}.`)}
      />

      {settings.isPending && <Spinner label="Chargement des paramètres" />}
      <ErrorAlert error={settings.error} onRetry={() => settings.refetch()} />

      {saved !== null && saved === s?.version && <Alert tone="success">Version {saved} enregistrée : elle s'applique dès maintenant aux nouveaux paris.</Alert>}

      {s && (canUpdate
        ? <SettingsForm key={s.version} settings={s} onSaved={setSaved} onStale={() => settings.refetch()} />
        : <SettingsView settings={s} />)}

      <VersionHistory currency={currency} />
    </section>
  );
}

function groupsOf(items: Setting[]) {
  return [...new Set(items.map((i) => i.group))].map((group) => ({ group, items: items.filter((i) => i.group === group) }));
}

function SettingsView({ settings }: { settings: PlatformSettings }) {
  return (
    <section className="admin-card stack">
      <Alert tone="info">Consultation seule : la modification est réservée au Super Admin.</Alert>
      {groupsOf(settings.items).map(({ group, items }) => (
        <div key={group} className="stack-sm">
          <h2 className="card-title">{group}</h2>
          <dl className="details">
            {items.map((i) => (
              <div key={i.key} className="details-row">
                <dt>{i.label}</dt>
                <dd>{display(i.type, i.value, i.currency)}{i.value !== i.default && <span className="muted small"> (par défaut : {display(i.type, i.default, i.currency)})</span>}</dd>
              </div>
            ))}
          </dl>
        </div>
      ))}
    </section>
  );
}

/** Brouillon : texte saisi pour les montants et nombres, valeur directe sinon. */
type Draft = Record<string, string | boolean>;

function toDraft(items: Setting[]): Draft {
  return Object.fromEntries(items.map((i) => [i.key,
    i.type === 'boolean' ? Boolean(i.value) : i.type === 'money' && i.currency ? minorToInput(Number(i.value), i.currency) : String(i.value)]));
}

function parse(item: Setting, raw: string | boolean): SettingValue | null {
  if (item.type === 'boolean') return Boolean(raw);
  if (item.type === 'choice') return String(raw);
  if (item.type === 'money') return inputToMinor(String(raw), item.currency ?? 'HTG');
  return /^\d+$/.test(String(raw).trim()) ? Number(String(raw).trim()) : null;
}

function SettingsForm({ settings, onSaved, onStale }: { settings: PlatformSettings; onSaved: (version: number) => void; onStale: () => void }) {
  const save = useSaveSettings();
  const [draft, setDraft] = useState<Draft>(() => toDraft(settings.items));

  const parsed = settings.items.map((item) => ({ item, value: parse(item, draft[item.key]) }));
  const invalid = parsed.filter((p) => p.value === null).map((p) => p.item.key);
  const changes = Object.fromEntries(parsed.filter((p) => p.value !== null && p.value !== p.item.value).map((p) => [p.item.key, p.value as SettingValue]));
  const changed = Object.keys(changes);

  async function submit(reason: string, idempotencyKey: string) {
    try {
      const updated = await save.mutateAsync({ values: changes, baseVersion: settings.version, reason, idempotencyKey });
      onSaved(updated.version);
      return updated;
    } catch (e) {
      // Version enregistrée entre-temps : on recharge (le formulaire repart des nouvelles valeurs).
      if (e instanceof ApiError && e.code === 'settings_changed') onStale();
      throw e;
    }
  }

  const fieldErrors = save.error instanceof ApiError ? save.error.fieldErrors : {};

  return (
    <section className="admin-card stack">
      <p className="muted small">
        Chaque enregistrement crée une nouvelle version, conservée dans l'historique et le journal d'audit. Elle s'applique
        aux nouveaux paris ; les tickets déjà placés gardent les règles de leur placement.
      </p>

      {groupsOf(settings.items).map(({ group, items }) => (
        <fieldset key={group} className="settings-group">
          <legend className="card-title">{group}</legend>
          {items.map((item) => (
            <SettingField
              key={item.key}
              item={item}
              raw={draft[item.key]}
              invalid={invalid.includes(item.key)}
              changed={changed.includes(item.key)}
              error={fieldErrors[`values.${item.key}`]}
              onChange={(raw) => setDraft({ ...draft, [item.key]: raw })}
            />
          ))}
        </fieldset>
      ))}

      <ReasonForm
        id="settings"
        title={changed.length === 0 ? 'Aucune modification' : `Enregistrer ${changed.length} modification${changed.length > 1 ? 's' : ''} (version ${settings.version + 1})`}
        submitLabel="Enregistrer la nouvelle version"
        incomplete={changed.length === 0 || invalid.length > 0}
        onSubmit={submit}
      />
    </section>
  );
}

type FieldProps = { item: Setting; raw: string | boolean; invalid: boolean; changed: boolean; error?: string; onChange: (raw: string | boolean) => void };

function SettingField({ item, raw, invalid, changed, error, onChange }: FieldProps) {
  const id = `setting-${item.key.replace(/\W/g, '-')}`;
  const message = error ?? (invalid ? (item.type === 'money' ? 'Montant invalide.' : 'Nombre entier attendu.') : undefined);
  const hint = [item.help, item.value !== item.default ? `Par défaut : ${display(item.type, item.default, item.currency)}.` : null].filter(Boolean).join(' ');

  if (item.type === 'boolean') {
    return (
      <div className={['setting-toggle', changed && 'field-changed'].filter(Boolean).join(' ')}>
        <input id={id} type="checkbox" checked={Boolean(raw)} onChange={(e) => onChange(e.target.checked)} />
        <label htmlFor={id}>{item.label}</label>
        {hint && <small className="muted">{hint}</small>}
      </div>
    );
  }

  return (
    <div className={['field', message && 'field-invalid', changed && 'field-changed'].filter(Boolean).join(' ')}>
      <label htmlFor={id}>{item.label}{item.type === 'money' && item.currency ? ` (${item.currency})` : ''}</label>
      {item.type === 'choice'
        ? (
          <select id={id} value={String(raw)} onChange={(e) => onChange(e.target.value)}>
            {item.options?.map((o) => <option key={o} value={o}>{o}</option>)}
          </select>
        )
        : <input id={id} inputMode={item.type === 'money' ? 'decimal' : 'numeric'} value={String(raw)} onChange={(e) => onChange(e.target.value)}
          aria-invalid={message ? true : undefined} aria-describedby={message ? `${id}-error` : undefined} />}
      {message && <small id={`${id}-error`} className="field-error" role="alert">{message}</small>}
      {hint && <small className="muted">{hint}</small>}
    </div>
  );
}

const versionColumns = (currency: string): Column<SettingVersion>[] => [
  {
    key: 'version',
    header: 'Version',
    render: (v) => (
      <div className="cell-stack">
        <strong>Version {v.version}</strong>
        <span className="muted small nowrap">{dateTime(v.created_at)}</span>
      </div>
    ),
  },
  {
    key: 'changes',
    header: 'Changements',
    render: (v) => (
      <ul className="change-list">
        {v.changes.map((c) => (
          <li key={c.key}>{c.label} : {display(c.type, c.from, currency)} → <strong>{display(c.type, c.to, currency)}</strong></li>
        ))}
      </ul>
    ),
  },
  { key: 'by', header: 'Par', render: (v) => v.created_by.name ?? `n° ${v.created_by.id}` },
  { key: 'reason', header: 'Motif', render: (v) => <span className="small">{v.reason}</span> },
];

function VersionHistory({ currency }: { currency: string }) {
  const [page, setPage] = useState(1);
  const versions = useSettingVersions(page);

  return (
    <section className="admin-card stack">
      <h2 className="card-title">Historique des versions</h2>
      <ErrorAlert error={versions.error} onRetry={() => versions.refetch()} />
      <DataTable
        caption="Versions des paramètres"
        columns={versionColumns(currency)}
        rows={versions.data?.data}
        rowKey={(v) => v.id}
        loading={versions.isFetching}
        empty="Aucune version enregistrée : la configuration du serveur s'applique."
      />
      <Pagination meta={versions.data?.meta} onPage={setPage} />
    </section>
  );
}
