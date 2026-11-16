import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

test('static deployment publishes public assets without PHP, secrets, SQL or archives', () => {
  const result = spawnSync(process.execPath, ['tools/build-static.mjs'], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(fs.readFileSync('.netlify-public/index.html', 'utf8'), fs.readFileSync('index.html', 'utf8'));
  assert.ok(fs.existsSync('.netlify-public/frontend/assets/css/condoconnect.css'));
  function walk(directory) {
    for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
      const file = path.join(directory, entry.name);
      if (entry.isDirectory()) walk(file);
      else assert.doesNotMatch(file, /\.(?:php|sql|env|rar|zip|phar|log)$/i);
    }
  }
  walk('.netlify-public');
  for (const privatePath of ['backend', 'tests', '.local-tools', 'vai.env', '.env', 'db_sisc.sql']) assert.equal(fs.existsSync(path.join('.netlify-public', privatePath)), false);
});
