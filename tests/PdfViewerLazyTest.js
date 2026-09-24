// Run with: gjs tests/PdfViewerLazyTest.js
const GLib = imports.gi.GLib;
const [, bytes] = GLib.file_get_contents('assets/pdf-viewer.mjs');
const source = imports.byteArray.toString(bytes);
const Viewer = new Function(source.slice(source.indexOf('export class')).replace('export class', 'class') + '\nreturn PaperbellPdfViewer;')();
function check(value, message) { if (!value) throw new Error(message); }
function canvas() { return { style: {}, setAttribute() {}, dataset: {}, insertAdjacentElement() {} }; }
globalThis.document = { createElement: canvas };
globalThis.window = { devicePixelRatio: 1 };
async function test() {
  const requested = [];
  const viewer = Object.create(Viewer.prototype);
  Object.assign(viewer, {
    generation: 1, renderGeneration: 0, pageCount: 100, zoom: 1,
    canvas: canvas(), container: { clientWidth: 632 },
    pages: new Map(), renderTasks: new Map(), clearPageStack() {},
    document: { async getPage(number) {
      requested.push(number);
      return {
        getViewport({scale}) { return { width: 300 * scale, height: (number === 50 ? 900 : 400) * scale }; },
        render() { return { promise: Promise.resolve(), cancel() {} }; },
      };
    } },
  });
  await viewer.buildPageStack(1);
  check(requested.join(',') === '1', 'Opening must not fetch 99 off-screen pages');
  check(viewer.pages.size === 100, 'All pages need navigation placeholders');
  await viewer.renderPage(50);
  check(requested.join(',') === '1,50', 'Jumping must fetch only the target page');
  check(viewer.pages.get(50).canvas.height === 1800, 'Mixed-size page must use its real dimensions');
  await viewer.renderPage(50);
  check(requested.length === 2, 'Rendered page must not be fetched again');
  print('PDF viewer lazy metadata and mixed-size rendering: passed');
}
const loop = new GLib.MainLoop(null, false);
let failed = false;
test().catch(error => { printerr(error); failed = true; }).finally(() => loop.quit());
loop.run();
if (failed) imports.system.exit(1);
