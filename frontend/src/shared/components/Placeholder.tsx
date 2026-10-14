type Props = { title: string; task: string };

/** Écran temporaire : indique quelle tâche (docs/TASKS.md) doit l'implémenter. */
export function Placeholder({ title, task }: Props) {
  return (
    <section className="placeholder">
      <h1>{title}</h1>
      <p>
        À implémenter — tâche <code>{task}</code> (voir <code>docs/TASKS.md</code>).
      </p>
    </section>
  );
}
