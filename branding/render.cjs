// Postbox icons: PNGs and the ICO from the SVGs in this folder, into snappymail/v/0.0.0/static/.
// node render.js <path of a Chromium/headless_shell>   (needs playwright-core: npm i playwright-core)
const { chromium } = require('playwright-core');
const fs = require('fs');
const path = require('path');

const here = __dirname;
const out = path.join(here, '..', 'snappymail', 'v', '0.0.0', 'static');
const svg = (name) => fs.readFileSync(path.join(here, name), 'utf8');

// file → [svg, size]
const PNGS = {
	'favicon.png': ['favicon.svg', 64],
	'favicon-light.png': ['favicon.svg', 64],
	'apple-touch-icon.png': ['icon-maskable.svg', 180],   // iOS rounds the corners itself
	'android-icon.png': ['icon.svg', 192],
	'logo-512.png': ['icon.svg', 512],
	'icon-maskable-512.png': ['icon-maskable.svg', 512],
};

// An ICO holding PNG images (Vista and later read it everywhere)
function ico(pngs) {
	const header = Buffer.alloc(6);
	header.writeUInt16LE(0, 0); header.writeUInt16LE(1, 2); header.writeUInt16LE(pngs.length, 4);
	const entries = [], datas = [];
	let offset = 6 + 16 * pngs.length;
	for (const [size, data] of pngs) {
		const e = Buffer.alloc(16);
		e.writeUInt8(size >= 256 ? 0 : size, 0); e.writeUInt8(size >= 256 ? 0 : size, 1);
		e.writeUInt16LE(1, 4); e.writeUInt16LE(32, 6);
		e.writeUInt32LE(data.length, 8); e.writeUInt32LE(offset, 12);
		offset += data.length;
		entries.push(e); datas.push(data);
	}
	return Buffer.concat([header, ...entries, ...datas]);
}

(async () => {
	const b = await chromium.launch({ executablePath: process.argv[2] });
	const page = await b.newPage();
	const render = async (name, size) => {
		await page.setViewportSize({ width: size, height: size });
		await page.setContent(`<html><body style="margin:0;background:transparent">${svg(name).replace('<svg ', `<svg width="${size}" height="${size}" `)}</body></html>`);
		return page.screenshot({ omitBackground: true, clip: { x: 0, y: 0, width: size, height: size } });
	};
	for (const [file, [name, size]] of Object.entries(PNGS)) {
		fs.writeFileSync(path.join(out, file), await render(name, size));
		console.log(file, size);
	}
	const icoData = ico([[16, await render('favicon.svg', 16)], [32, await render('favicon.svg', 32)], [48, await render('favicon.svg', 48)]]);
	fs.writeFileSync(path.join(out, 'favicon.ico'), icoData);
	fs.writeFileSync(path.join(out, 'favicon-light.ico'), icoData);
	fs.copyFileSync(path.join(here, 'favicon.svg'), path.join(out, 'favicon.svg'));
	console.log('favicon.ico, favicon.svg');
	await b.close();
})();
