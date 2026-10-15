import type { Paginated } from '../api/client';

type Props = {
  meta: Paginated<unknown>['meta'] | undefined;
  onPage: (page: number) => void;
};

export function Pagination({ meta, onPage }: Props) {
  if (!meta || meta.last_page <= 1) return null;

  return (
    <nav className="pagination" aria-label="Pagination">
      <button type="button" className="btn btn-secondary" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
        Précédent
      </button>
      <span className="muted">
        Page {meta.current_page} / {meta.last_page} · {meta.total} élément{meta.total > 1 ? 's' : ''}
      </span>
      <button type="button" className="btn btn-secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
        Suivant
      </button>
    </nav>
  );
}
