import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import postcss from 'postcss';
import { parse } from 'parse5';
import YAML from 'yaml';
import TOML from '@iarna/toml';

const root = path.resolve(import.meta.dirname, '..');
const excluded = new Set(['.git', '.local-tools', '.netlify-public', 'node_modules', 'vendor', 'test-results', 'Microsoft', 'storage']);
const counts = {};
const failures = [];
function walk(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (excluded.has(entry.name)) continue;
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) { walk(file); continue; }
    const ext = path.extname(file).toLowerCase();
    if (!['.php', '.js', '.mjs', '.css', '.html', '.json', '.yml', '.yaml', '.toml'].includes(ext)) continue;
    try {
      const source = fs.readFileSync(file, 'utf8');
      if (ext === '.php' || ext === '.js' || ext === '.mjs') {
        const result = spawnSync(ext === '.php' ? process.env.PHP_BINARY || 'php' : process.execPath, [ext === '.php' ? '-l' : '--check', file], { encoding: 'utf8' });
        if (result.error) throw result.error;
        if (result.status !== 0) throw new Error(result.stderr || result.stdout);
      } else if (ext === '.css') postcss.parse(source, { from: file });
      else if (ext === '.html') {
        const errors = []; parse(source, { onParseError: error => errors.push(error) });
        if (errors.length) throw new Error(errors.map(e => `${e.code}:${e.startLine}`).join(', '));
      } else if (ext === '.json') JSON.parse(source);
      else if (ext === '.yml' || ext === '.yaml') YAML.parse(source);
      else TOML.parse(source);
      counts[ext] = (counts[ext] || 0) + 1;
    } catch (error) { failures.push(`${path.relative(root, file)}: ${error.message}`); }
  }
}
walk(root);
console.log(JSON.stringify({ checked: counts, failures }, null, 2));
if (failures.length) process.exitCode = 1;
