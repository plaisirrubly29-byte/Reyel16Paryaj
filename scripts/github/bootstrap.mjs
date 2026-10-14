#!/usr/bin/env node
// Source unique des tâches : scripts/github/tasks.json
//
//   node scripts/github/bootstrap.mjs --markdown           -> régénère docs/TASKS.md
//   node scripts/github/bootstrap.mjs --github --dry-run   -> affiche ce qui serait créé
//   node scripts/github/bootstrap.mjs --github             -> crée labels, milestones et issues (via gh)
//
// Ré-exécutable : les labels sont mis à jour, les milestones et issues existants sont ignorés.

import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repoRoot = join(here, '..', '..');
const { roles, phases, tasks } = JSON.parse(readFileSync(join(here, 'tasks.json'), 'utf8'));

const args = new Set(process.argv.slice(2));
const dryRun = args.has('--dry-run');

const phaseOf = (task) => phases.find((p) => task.id.startsWith(p.id + '-'));
const issueTitle = (task) => `[${task.id}] ${task.title}`;
const labelsOf = (task) => [
  `phase:${phaseOf(task).id}`,
  `module:${task.module}`,
  `area:${task.area}`,
  `role:${task.role}`,
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
  for (const phase of phases) {
    const list = tasks.filter((t) => phaseOf(t) === phase);
    lines.push(`## ${phase.title}`, '', `**Critère de sortie :** ${phase.exit}`, '');
    lines.push('| ID | Tâche | Rôle | Module | Dépend de |', '|---|---|---|---|---|');
    for (const t of list) {
      const title = t.blocked ? `⛔ ${t.title}` : t.title;
      lines.push(`| ${t.id} | ${title} | \`${t.role}\` | ${t.module} | ${(t.depends ?? []).join(', ') || '—'} |`);
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
if (!args.has('--markdown') && !args.has('--github')) {
  console.log('Usage : node scripts/github/bootstrap.mjs [--markdown] [--github [--dry-run]]');
}
