// Crea la base de datos D1, escribe su id en wrangler.jsonc y publica la tienda.
import { execSync } from 'node:child_process';
import fs from 'node:fs';
const run = c => execSync(c, { encoding: 'utf8', stdio: ['inherit', 'pipe', 'inherit'] });
let out = '';
try { out = run('npx wrangler d1 create goldentouch'); }
catch (e) { out = run('npx wrangler d1 info goldentouch'); }
const m = out.match(/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/);
if (!m) { console.log(out); throw new Error('No encontré el database_id'); }
let w = fs.readFileSync('wrangler.jsonc', 'utf8').replace(/"database_id": "[^"]*"/, `"database_id": "${m[0]}"`);
fs.writeFileSync('wrangler.jsonc', w);
console.log('D1 lista:', m[0]);
console.log(execSync('npx wrangler deploy', { encoding: 'utf8' }));
