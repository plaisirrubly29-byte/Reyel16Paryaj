import { useEffect, useState } from 'react';
import QRCode from 'qrcode';

/** QR code de l'URI otpauth://, généré localement (le secret ne quitte jamais le navigateur). */
export function MfaQrCode({ uri }: { uri: string }) {
  const [src, setSrc] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    QRCode.toDataURL(uri, { margin: 1, width: 200 })
      .then((url) => { if (!cancelled) setSrc(url); })
      .catch(() => { if (!cancelled) setSrc(null); });
    return () => { cancelled = true; };
  }, [uri]);

  return src
    ? <img className="qr" src={src} width={200} height={200} alt="QR code à scanner avec votre application d'authentification" />
    : null;
}
