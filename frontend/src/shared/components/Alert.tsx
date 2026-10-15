import type { ReactNode } from 'react';

type Props = {
  tone?: 'info' | 'success' | 'warning' | 'error';
  title?: string;
  children?: ReactNode;
};

export function Alert({ tone = 'info', title, children }: Props) {
  return (
    <div className={`alert alert-${tone}`} role={tone === 'error' || tone === 'warning' ? 'alert' : 'status'}>
      {title && <strong className="alert-title">{title}</strong>}
      {children && <div>{children}</div>}
    </div>
  );
}
