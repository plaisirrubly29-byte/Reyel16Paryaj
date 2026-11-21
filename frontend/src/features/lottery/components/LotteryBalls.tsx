import type { LotteryResult } from '../api/lottery';

type Props = { result: LotteryResult; size?: 'md' | 'lg' };

/** Les trois lots d'un résultat en boules (1er lot en rouge), et le numéro de 3 chiffres s'il est connu. */
export function LotteryBalls({ result, size = 'md' }: Props) {
  const label = `1er lot ${result.lots[0]}, 2e lot ${result.lots[1]}, 3e lot ${result.lots[2]}`;

  return (
    <div className={`lottery-balls lottery-balls-${size}`}>
      <ol className="lottery-balls-list" aria-label={label}>
        {result.lots.map((lot, i) => (
          <li key={i} className={i === 0 ? 'lottery-ball first' : 'lottery-ball'} aria-hidden="true">{lot}</li>
        ))}
      </ol>
      {result.pick3 && <span className="lottery-pick3">Loto 3 : <strong>{result.pick3}</strong></span>}
    </div>
  );
}
