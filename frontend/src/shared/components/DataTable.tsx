import type { ReactNode } from 'react';
import { Spinner } from './Spinner';

export type Column<T> = {
  key: string;
  header: string;
  render: (row: T) => ReactNode;
  align?: 'left' | 'right';
};

type Props<T> = {
  columns: Column<T>[];
  rows: T[] | undefined;
  rowKey: (row: T) => string;
  loading?: boolean;
  empty?: string;
  caption?: string;
};

/** Tableau responsive : sur mobile chaque ligne devient une carte (libellés via data-label). */
export function DataTable<T>({ columns, rows, rowKey, loading = false, empty = 'Aucun élément.', caption }: Props<T>) {
  if (loading && !rows) {
    return <div className="table-state"><Spinner /></div>;
  }

  if (!rows || rows.length === 0) {
    return <p className="table-state muted">{empty}</p>;
  }

  return (
    <div className="table-wrap">
      <table className="table" aria-busy={loading || undefined}>
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead>
          <tr>
            {columns.map((c) => <th key={c.key} className={c.align === 'right' ? 'num' : undefined} scope="col">{c.header}</th>)}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr key={rowKey(row)}>
              {columns.map((c) => (
                <td key={c.key} data-label={c.header} className={c.align === 'right' ? 'num' : undefined}>{c.render(row)}</td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
