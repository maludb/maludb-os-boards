// The TypeScript twin of the z keys, for tests/phase3/slice2/ops.php: node zkeys.mjs <fractional.mjs built by run.sh> '<json cases>'
// prints the same list the PHP side makes (keyBetween over the cases, 3,000 keys after the highest, 200 before the lowest, 40 between two).
const [, , lib, casesJson] = process.argv;
const { keyBetween, keyAfter } = await import(lib);
const out = JSON.parse(casesJson).map(([a, b]) => keyBetween(a, b));
let k = null; for (let i = 0; i < 3000; i++) k = keyAfter(k); out.push(k);
k = 'a0'; for (let i = 0; i < 200; i++) k = keyBetween(null, k); out.push(k);
let a = 'a0', c = 'a1'; for (let i = 0; i < 40; i++) c = keyBetween(a, c); out.push(c);
process.stdout.write(JSON.stringify(out));
