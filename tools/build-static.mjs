import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const output = path.join(root, '.netlify-public');
fs.mkdirSync(output, { recursive: true });
fs.copyFileSync(path.join(root, 'index.html'), path.join(output, 'index.html'));
const publicExtensions = new Set(['.css', '.js', '.png', '.jpg', '.jpeg', '.webp', '.gif', '.svg', '.ico', '.woff', '.woff2']);
for (const directory of ['assets', 'frontend/assets', 'frontend/style', 'frontend/global', 'style', 'global']) {
  fs.cpSync(path.join(root, directory), path.join(output, directory), {
    recursive: true,
    filter: file => fs.statSync(file).isDirectory() || publicExtensions.has(path.extname(file).toLowerCase()),
  });
}
// Never publish the PHP project root, environment, SQL, archives or test artifacts.
function validate(directory) {
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const file = path.join(directory, entry.name);
    if (entry.isDirectory()) validate(file);
    else if (path.relative(output, file) !== 'index.html' && !publicExtensions.has(path.extname(file).toLowerCase())) {
      throw new Error('Arquivo privado no diretório de publicação: ' + path.relative(output, file));
    }
  }
}
validate(output);
console.log('Artefato estático preparado; a aplicação completa exige PHP e MySQL.');
