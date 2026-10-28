import { useState } from 'react';

type Props = {
  code: string;
  /** Résumé du pari, envoyé avec le betcode (ex. « Cote 6.30 · Mise 100 HTG »). */
  summary: string;
};

/**
 * Partage d'un betcode. WhatsApp accepte un texte prérempli ; Facebook n'accepte qu'un lien,
 * donc le betcode est copié et la fenêtre de partage s'ouvre pour le coller dans la publication.
 */
export function BetCodeShare({ code, summary }: Props) {
  const [note, setNote] = useState('');
  const message = `${summary} — betcode REYEL 16 PARYAJ : ${code}`;

  async function copy(): Promise<boolean> {
    try {
      await navigator.clipboard.writeText(code);
      return true;
    } catch {
      return false;
    }
  }

  async function shareFacebook() {
    const copied = await copy();
    setNote(copied ? 'Betcode copié : collez-le dans votre publication.' : `Betcode : ${code}`);
    window.open(
      `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(window.location.origin)}`,
      '_blank',
      'noopener,noreferrer,width=600,height=500',
    );
  }

  async function copyOnly() {
    const copied = await copy();
    setNote(copied ? 'Betcode copié.' : `Betcode : ${code}`);
  }

  return (
    <div className="betcode-share">
      <div className="betcode-code">
        <span>Betcode</span>
        <strong>{code}</strong>
      </div>
      <div className="betcode-actions">
        <a
          className="btn btn-secondary btn-sm"
          href={`https://wa.me/?text=${encodeURIComponent(message)}`}
          target="_blank"
          rel="noopener noreferrer"
        >
          WhatsApp
        </a>
        <button type="button" className="btn btn-secondary btn-sm" onClick={shareFacebook}>Facebook</button>
        <button type="button" className="btn btn-ghost btn-sm" onClick={copyOnly}>Copier</button>
      </div>
      {note && <p role="status" className="muted small">{note}</p>}
    </div>
  );
}
