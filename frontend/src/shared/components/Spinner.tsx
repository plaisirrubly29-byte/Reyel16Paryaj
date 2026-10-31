type Props = { size?: 'sm' | 'md'; label?: string };

export function Spinner({ size = 'md', label = 'Chargement…' }: Props) {
  return <span className={`spinner spinner-${size}`} role="status" aria-label={label} />;
}
