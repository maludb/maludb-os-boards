// The island's build (canvas-core.md "client/ — files and build"): src/main.ts → ONE minified IIFE (es2020, no source map) and
// src/boards.css → one minified stylesheet, each named by the sha256 of its OUTPUT (boards.<first 10 hex>.js / .css) in
// ../html/assets/boards/, older boards.* removed, and manifest.json {file, css, source_hash, built_at}. The bundle is COMMITTED: the
// installer never runs npm. source_hash = sha256 over every file of client/src/ sorted by path (each as "path NUL content NUL") then
// client/package-lock.json — bin/check_bundle.php computes the same and fails when the committed bundle is stale.
import * as esbuild from 'esbuild';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..');
const out = path.join(root, 'html/assets/boards');

function walk(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((d) => (d.isDirectory() ? walk(path.join(dir, d.name)) : [path.join(dir, d.name)]));
}
function sourceHash() {
  const h = crypto.createHash('sha256');
  const files = walk(path.join(here, 'src')).map((f) => path.relative(root, f).split(path.sep).join('/')).sort();
  for (const rel of files) {
    h.update(rel); h.update('\0'); h.update(fs.readFileSync(path.join(root, rel))); h.update('\0');
  }
  h.update(fs.readFileSync(path.join(here, 'package-lock.json')));
  return h.digest('hex');
}
const short = (buf) => crypto.createHash('sha256').update(buf).digest('hex').slice(0, 10);

const js = await esbuild.build({
  entryPoints: [path.join(here, 'src/main.ts')], bundle: true, minify: true, format: 'iife', target: 'es2020', platform: 'browser',
  write: false, sourcemap: false, legalComments: 'eof', logLevel: 'warning',
});
const css = await esbuild.build({
  entryPoints: [path.join(here, 'src/boards.css')], bundle: true, minify: true, write: false, logLevel: 'warning', loader: { '.css': 'css' },
});
const jsBuf = Buffer.from(js.outputFiles[0].contents);
const cssBuf = Buffer.from(css.outputFiles[0].contents);
const jsName = `boards.${short(jsBuf)}.js`;
const cssName = `boards.${short(cssBuf)}.css`;
fs.mkdirSync(out, { recursive: true });
for (const f of fs.readdirSync(out)) {
  if (/^boards\.[0-9a-f]{10}\.(js|css)$/.test(f) && f !== jsName && f !== cssName) fs.unlinkSync(path.join(out, f));
}
fs.writeFileSync(path.join(out, jsName), jsBuf);
fs.writeFileSync(path.join(out, cssName), cssBuf);
const manifest = { file: jsName, css: cssName, source_hash: sourceHash(), built_at: new Date().toISOString().replace(/\.\d+Z$/, 'Z') };
fs.writeFileSync(path.join(out, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
console.log(`${jsName} (${(jsBuf.length / 1024).toFixed(1)} kB), ${cssName} (${(cssBuf.length / 1024).toFixed(1)} kB), source ${manifest.source_hash.slice(0, 12)}`);
