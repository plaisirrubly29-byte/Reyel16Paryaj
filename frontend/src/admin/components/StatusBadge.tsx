import { STATUS_LABELS, type AccountStatus } from '../api/users';

export function StatusBadge({ status }: { status: AccountStatus }) {
  return <span className={`badge status-${status}`}>{STATUS_LABELS[status]}</span>;
}
