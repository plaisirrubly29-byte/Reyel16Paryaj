/**
 * Numéro local haïtien (8 chiffres) à partir d'une saisie libre : ne garde que les chiffres,
 * retire l'indicatif "509" s'il a été tapé ou collé devant (ex. "+509 34 12 34 56" → "34123456").
 * Miroir exact de la normalisation du serveur (RegisterRequest/LoginRequest::normalizePhone).
 */
export function digitsOnly(value: string): string {
  const digits = value.replace(/\D+/g, '');
  const local = digits.length === 11 && digits.startsWith('509') ? digits.slice(3) : digits;
  return local.slice(0, 8);
}
