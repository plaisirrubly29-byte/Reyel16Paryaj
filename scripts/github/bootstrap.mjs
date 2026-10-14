#!/usr/bin/env node
// Source unique des tâches : scripts/github/tasks.json
//
//   node scripts/github/bootstrap.mjs --markdown           -> régénère docs/TASKS.md
//   node scripts/github/bootstrap.mjs --github --dry-run   -> affiche ce qui serait créé
//   node scripts/github/bootstrap.mjs --github             -> crée labels, milestones et issues (via gh)
//   node scripts/github/bootstrap.mjs --assign             -> applique owner:* et assigne les issues existantes
//                                                             (renseigner owners.<id>.github dans tasks.json)
//
// Ré-exécutable : les labels sont mis à jour, les milestones et issues existants sont ignorés.

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const { roles, owners, waves, phases, tasks } = JSON.parse(readFileSync(join(here, 'tasks.json'), 'utf8'));

const args = new Set(process.argv.slice(2));
const dryRun = args.has('--dry-run');

const phaseOf = (task) => phases.find((p) => task.id.startsWith(p.id + '-'));
const issueTitle = (task) => `[${task.id}] ${task.title}`;
const labelsOf = (task) => [
  `phase:${phaseOf(task).id}`,
  `module:${task.module}`,
  `area:${task.area}`,
  `role:${task.role}`,
  `owner:${task.owner}`,
  'type:feature',
  ...(task.blocked ? ['blocked'] : []),
];

function writeMarkdown() {
  const lines = [
    '# Plan de tâches — REYEL 16 PAYAJ V1',
    '',
    '> Fichier généré depuis `scripts/github/tasks.json` — ne pas éditer à la main.',
    '> Régénérer : `node scripts/github/bootstrap.mjs --markdown`',
    '',
    '## Rôles',
    '',
    '| Rôle | Périmètre | Membre(s) |',
    '|---|---|---|',
    ...Object.entries(roles).map(([k, v]) => `| \`${k}\` | ${v} | |`),
    '',
  ];
  lines.push('Répartition par développeur : voir [`REPARTITION.md`](REPARTITION.md).', '');
  for (const phase of phases) {
    const list = tasks.filter((t) => phaseOf(t) === phase);
    lines.push(`## ${phase.title}`, '', `**Critère de sortie :** ${phase.exit}`, '');
    lines.push('| ID | Tâche | Responsable | Vague | Module | Dépend de |', '|---|---|---|---|---|---|');
    for (const t of list) {
      const title = t.blocked ? `⛔ ${t.title}` : t.title;
      lines.push(`| ${t.id} | ${title} | ${owners[t.owner].name} | ${t.wave} | ${t.module} | ${(t.depends ?? []).join(', ') || '—'} |`);
    }
    lines.push('');
  }
  lines.push('## Détail des critères d\'acceptation', '');
  for (const t of tasks) {
    lines.push(`### ${t.id} — ${t.title}`, '', t.description, '', ...t.acceptance.map((a) => `- [ ] ${a}`), '');
  }
  const out = join(repoRoot, 'docs', 'TASKS.md');
  writeFileSync(out, lines.join('\n'));
  console.log(`Écrit : ${out} (${tasks.length} tâches)`);
  writeRepartition();
}

// Dépendances vers le travail d'un autre responsable = points de synchronisation.
function handoffs(task) {
  return (task.depends ?? [])
    .map((id) => tasks.find((t) => t.id === id))
    .filter((d) => d.owner !== task.owner)
    .map((d) => `${d.id} (${owners[d.owner].name})`);
}

function writeRepartition() {
  const lines = [
    '# Répartition des tâches par développeur',
    '',
    '> Généré depuis `scripts/github/tasks.json` — ne pas éditer à la main.',
    '> Pour changer un responsable : modifier `owner` dans tasks.json, puis `node scripts/github/bootstrap.mjs --markdown`.',
    '',
    'Les deux développeurs sont **full-stack** : chacun livre le backend **et** les écrans de son domaine,',
    'ce qui limite les conflits de fichiers. Les tests croisés (P2-07, P4-07, P6-09) sont confiés à',
    "l'autre développeur que celui qui a écrit le code.",
    '',
    '| Responsable | GitHub | Domaine | Tâches |',
    '|---|---|---|---|',
    ...Object.entries(owners).map(([k, o]) =>
      `| **${o.name}** | ${o.github ? '@' + o.github : '_à renseigner_'} | ${o.focus} | ${tasks.filter((t) => t.owner === k).length} |`),
    '',
    '## Vagues',
    '',
    'Une vague = un lot de travail à terminer avant de passer au suivant (durée à fixer selon la vitesse de l\'équipe,',
    'ex. 1 à 2 semaines). Les deux développeurs avancent dans la même vague en parallèle.',
    '',
    ...Object.entries(waves).map(([w, label]) => `- **Vague ${w}** — ${label}`),
    '',
  ];
  for (const [key, o] of Object.entries(owners)) {
    const mine = tasks.filter((t) => t.owner === key).sort((a, b) => a.wave - b.wave);
    lines.push(`## ${o.name}${o.github ? ` (@${o.github})` : ''}`, '', `_${o.focus}_`, '');
    lines.push('| Vague | ID | Tâche | Attend (autre responsable) |', '|---|---|---|---|');
    for (const t of mine) {
      const title = t.blocked ? `⛔ ${t.title}` : t.title;
      lines.push(`| ${t.wave} | ${t.id} | ${title} | ${handoffs(t).join(', ') || '—'} |`);
    }
    lines.push('');
  }
  const out = join(repoRoot, 'docs', 'REPARTITION.md');
  writeFileSync(out, lines.join('\n'));
  console.log(`Écrit : ${out}`);
}

function assignIssues() {
  for (const [key, o] of Object.entries(owners)) {
    gh('label', 'create', `owner:${key}`, '--color', '5319e7', '--description', o.name, '--force');
  }
  const issues = dryRun
    ? []
    : JSON.parse(ghRead('issue', 'list', '--state', 'all', '--limit', '1000', '--json', 'number,title'));
  let done = 0;
  for (const t of tasks) {
    const issue = issues.find((i) => i.title === issueTitle(t));
    if (!issue && !dryRun) {
      console.warn(`Issue introuvable : ${issueTitle(t)}`);
      continue;
    }
    const editArgs = ['issue', 'edit', String(issue?.number ?? t.id), '--add-label', `owner:${t.owner}`];
    if (owners[t.owner].github) editArgs.push('--add-assignee', owners[t.owner].github);
    gh(...editArgs);
    done++;
  }
  const missing = Object.values(owners).filter((o) => !o.github).map((o) => o.name);
  console.log(`Issues mises à jour : ${done}`);
  if (missing.length) console.log(`Sans compte GitHub (label seulement) : ${missing.join(', ')}`);
}

function gh(...ghArgs) {
  if (dryRun) {
    console.log('[dry-run] gh', ghArgs.map((a) => (a.includes(' ') || a.includes('\n') ? JSON.stringify(a.slice(0, 60)) : a)).join(' '));
    return '';
  }
  return execFileSync('gh', ghArgs, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
}

function ghRead(...ghArgs) {
  return execFileSync('gh', ghArgs, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
}

function setupGithub() {
  const labelSpecs = [
    ...phases.map((p) => [`phase:${p.id}`, '1d76db', p.title]),
    ...[...new Set(tasks.map((t) => t.module))].map((m) => [`module:${m}`, 'c5def5', `Module ${m}`]),
    ...[...new Set(tasks.map((t) => t.area))].map((a) => [`area:${a}`, 'fbca04', `Zone ${a}`]),
    ...Object.entries(roles).map(([k, v]) => [`role:${k}`, '0e8a16', v]),
    ...Object.entries(owners).map(([k, o]) => [`owner:${k}`, '5319e7', o.name]),
    ['type:feature', 'a2eeef', 'Fonctionnalité / tâche du plan'],
    ['type:bug', 'd73a4a', 'Comportement incorrect'],
    ['blocked', 'b60205', 'Bloqué par une décision ou une validation externe'],
  ];
  for (const [name, color, description] of labelSpecs) {
    gh('label', 'create', name, '--color', color, '--description', description.slice(0, 100), '--force');
  }
  console.log(`Labels : ${labelSpecs.length}`);

  const existingMilestones = dryRun
    ? []
    : JSON.parse(ghRead('api', 'repos/{owner}/{repo}/milestones?state=all&per_page=100')).map((m) => m.title);
  for (const p of phases) {
    if (existingMilestones.includes(p.title)) continue;
    gh('api', 'repos/{owner}/{repo}/milestones', '--method', 'POST', '-f', `title=${p.title}`, '-f', `description=Critère de sortie : ${p.exit}`);
  }
  console.log(`Milestones : ${phases.length}`);

  const existingIssues = dryRun
    ? []
    : JSON.parse(ghRead('issue', 'list', '--state', 'all', '--limit', '1000', '--json', 'title')).map((i) => i.title);
  let created = 0;
  for (const t of tasks) {
    if (existingIssues.includes(issueTitle(t))) continue;
    const body = [
      `**ID :** \`${t.id}\` · **Rôle suggéré :** \`${t.role}\` · **Module :** \`${t.module}\``,
      t.blocked ? '\n> ⛔ **Bloqué** : ne pas commencer avant la validation indiquée dans les dépendances.\n' : '',
      '## Description', '', t.description, '',
      '## Critères d\'acceptation', '', ...t.acceptance.map((a) => `- [ ] ${a}`), '',
      '## Dépend de', '', (t.depends ?? []).join(', ') || '—', '',
      '---',
      'Référence : `docs/architecture/` · Définition de « terminé » : `CONTRIBUTING.md`',
    ].join('\n');
    const labelArgs = labelsOf(t).flatMap((l) => ['--label', l]);
    gh('issue', 'create', '--title', issueTitle(t), '--body', body, '--milestone', phaseOf(t).title, ...labelArgs);
    created++;
  }
  console.log(`Issues créées : ${created} (déjà existantes ignorées : ${tasks.length - created})`);
}

if (args.has('--markdown')) writeMarkdown();
if (args.has('--github')) setupGithub();
if (args.has('--assign')) assignIssues();
if (!['--markdown', '--github', '--assign'].some((a) => args.has(a))) {
  console.log('Usage : node scripts/github/bootstrap.mjs [--markdown] [--github] [--assign] [--dry-run]');
}
