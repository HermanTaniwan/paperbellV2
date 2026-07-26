import * as pdfjsLib from './pdfjs/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = new URL(
  './pdfjs/pdf.worker.min.mjs',
  import.meta.url,
).href;

const PDF_ASSET_ROOT = new URL('./pdfjs/', import.meta.url).href;

export class PaperbellPdfViewer {
  constructor({ container, canvas, onState }) {
    this.container = container;
    this.canvas = canvas;
    this.onState = onState;
    this.document = null;
    this.loadingTask = null;
    this.pageNumber = 1;
    this.pageCount = 0;
    this.zoom = 1;
    this.generation = 0;
    this.renderGeneration = 0;
    this.pages = new Map();
    this.renderTasks = new Map();
    this.resizeTimer = null;
    this.scrollFrame = null;

    this.pageObserver = new IntersectionObserver(entries => {
      for (const entry of entries) {
        if (entry.isIntersecting) this.renderPage(Number(entry.target.dataset.pdfPage));
      }
    }, { root: container, rootMargin: '100% 0px', threshold: 0.01 });

    this.scrollHandler = () => {
      if (this.scrollFrame) return;
      this.scrollFrame = requestAnimationFrame(() => {
        this.scrollFrame = null;
        this.updateCurrentPage();
      });
    };
    container.addEventListener('scroll', this.scrollHandler, { passive: true });

    this.resizeObserver = new ResizeObserver(() => {
      clearTimeout(this.resizeTimer);
      this.resizeTimer = setTimeout(() => {
        if (this.document) this.relayout();
      }, 140);
    });
    this.resizeObserver.observe(container);
  }

  state(extra = {}) {
    this.onState?.({
      loading: false,
      error: '',
      page: this.pageNumber,
      pages: this.pageCount,
      zoom: Math.round(this.zoom * 100),
      ...extra,
    });
  }

  async load(url) {
    const generation = ++this.generation;
    await this.closeDocument();
    this.pageNumber = 1;
    this.pageCount = 0;
    this.zoom = 1;
    this.state({ loading: true });

    try {
      this.loadingTask = pdfjsLib.getDocument({
        url,
        cMapUrl: `${PDF_ASSET_ROOT}cmaps/`,
        cMapPacked: true,
        standardFontDataUrl: `${PDF_ASSET_ROOT}standard_fonts/`,
        wasmUrl: `${PDF_ASSET_ROOT}wasm/`,
        iccUrl: `${PDF_ASSET_ROOT}iccs/`,
      });
      const document = await this.loadingTask.promise;
      if (generation !== this.generation) {
        await document.destroy();
        return;
      }
      this.document = document;
      this.pageCount = document.numPages;
      await this.buildPageStack(generation);
      if (generation !== this.generation) return;
      this.state();
      this.renderPage(1);
    } catch (error) {
      if (generation !== this.generation || error?.name === 'RenderingCancelledException') return;
      this.state({ loading: false, error: 'Preview PDF tidak dapat dimuat. Coba buka di tab baru.' });
      console.error('PDF preview failed', error);
    }
  }

  async buildPageStack(generation) {
    this.clearPageStack();
    let previousCanvas = null;

    for (let pageNumber = 1; pageNumber <= this.pageCount; pageNumber++) {
      const page = await this.document.getPage(pageNumber);
      if (generation !== this.generation) return;
      const naturalViewport = page.getViewport({ scale: 1 });
      const canvas = pageNumber === 1 ? this.canvas : document.createElement('canvas');
      canvas.className = 'pdf-page-canvas';
      canvas.dataset.pdfPage = String(pageNumber);
      canvas.setAttribute('aria-label', `Halaman PDF ${pageNumber}`);
      canvas.hidden = false;
      if (previousCanvas) previousCanvas.insertAdjacentElement('afterend', canvas);
      previousCanvas = canvas;
      this.pages.set(pageNumber, { canvas, naturalViewport, renderedZoom: null });
      this.sizePlaceholder(pageNumber);
      this.pageObserver.observe(canvas);
    }
  }

  pageScale(naturalViewport) {
    const availableWidth = Math.max(240, this.container.clientWidth - 32);
    return (availableWidth / naturalViewport.width) * this.zoom;
  }

  sizePlaceholder(pageNumber) {
    const entry = this.pages.get(pageNumber);
    if (!entry) return;
    const scale = this.pageScale(entry.naturalViewport);
    entry.canvas.style.width = `${Math.floor(entry.naturalViewport.width * scale)}px`;
    entry.canvas.style.height = `${Math.floor(entry.naturalViewport.height * scale)}px`;
  }

  async renderPage(pageNumber) {
    const entry = this.pages.get(pageNumber);
    if (!this.document || !entry || entry.renderedZoom === this.zoom || this.renderTasks.has(pageNumber)) return;
    const generation = this.generation;
    const renderGeneration = this.renderGeneration;
    const pendingTask = { cancel() {} };
    let task = pendingTask;
    this.renderTasks.set(pageNumber, pendingTask);

    try {
      const page = await this.document.getPage(pageNumber);
      if (generation !== this.generation || renderGeneration !== this.renderGeneration) return;
      const viewport = page.getViewport({ scale: this.pageScale(entry.naturalViewport) });
      const outputScale = Math.min(window.devicePixelRatio || 1, 2);
      entry.canvas.width = Math.floor(viewport.width * outputScale);
      entry.canvas.height = Math.floor(viewport.height * outputScale);
      entry.canvas.style.width = `${Math.floor(viewport.width)}px`;
      entry.canvas.style.height = `${Math.floor(viewport.height)}px`;
      task = page.render({
        canvas: entry.canvas,
        viewport,
        transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0],
        background: '#ffffff',
      });
      this.renderTasks.set(pageNumber, task);
      await task.promise;
      if (generation === this.generation && renderGeneration === this.renderGeneration) entry.renderedZoom = this.zoom;
    } catch (error) {
      if (error?.name !== 'RenderingCancelledException' && generation === this.generation) {
        console.error(`PDF page ${pageNumber} render failed`, error);
      }
    } finally {
      if (this.renderTasks.get(pageNumber) === task || this.renderTasks.get(pageNumber) === pendingTask) {
        this.renderTasks.delete(pageNumber);
      }
    }
  }

  updateCurrentPage() {
    if (!this.pages.size) return;
    const bounds = this.container.getBoundingClientRect();
    const marker = bounds.top + Math.min(bounds.height * 0.32, 220);
    let nearestPage = this.pageNumber;
    let nearestDistance = Infinity;

    for (const [pageNumber, entry] of this.pages) {
      const rect = entry.canvas.getBoundingClientRect();
      const distance = rect.top <= marker && rect.bottom >= marker
        ? 0
        : Math.min(Math.abs(rect.top - marker), Math.abs(rect.bottom - marker));
      if (distance < nearestDistance) {
        nearestDistance = distance;
        nearestPage = pageNumber;
      }
    }

    if (nearestPage !== this.pageNumber) {
      this.pageNumber = nearestPage;
      this.state();
    }
  }

  relayout() {
    const currentPage = this.pageNumber;
    ++this.renderGeneration;
    for (const task of this.renderTasks.values()) task.cancel();
    this.renderTasks.clear();
    this.pageObserver.disconnect();
    for (const [pageNumber, entry] of this.pages) {
      entry.renderedZoom = null;
      entry.canvas.width = 1;
      entry.canvas.height = 1;
      this.sizePlaceholder(pageNumber);
      this.pageObserver.observe(entry.canvas);
    }
    this.renderPage(currentPage);
    this.renderPage(currentPage + 1);
    this.state();
  }

  goTo(pageNumber) {
    const target = Math.min(this.pageCount, Math.max(1, Number(pageNumber) || 1));
    const entry = this.pages.get(target);
    if (!entry) return;
    this.pageNumber = target;
    this.renderPage(target);
    this.container.scrollTo({ top: Math.max(0, entry.canvas.offsetTop - 12), behavior: 'smooth' });
    this.state();
  }

  zoomBy(multiplier) {
    const next = Math.min(2.5, Math.max(0.6, this.zoom * multiplier));
    if (Math.abs(next - this.zoom) < 0.01) return;
    this.zoom = next;
    this.relayout();
    requestAnimationFrame(() => this.goTo(this.pageNumber));
  }

  resetZoom() {
    if (this.zoom === 1) return;
    this.zoom = 1;
    this.relayout();
    requestAnimationFrame(() => this.goTo(this.pageNumber));
  }

  clearPageStack() {
    this.pageObserver.disconnect();
    for (const [pageNumber, entry] of this.pages) {
      if (pageNumber > 1) entry.canvas.remove();
    }
    this.pages.clear();
    this.canvas.hidden = true;
    this.canvas.removeAttribute('style');
    this.canvas.removeAttribute('data-pdf-page');
    this.canvas.width = 0;
    this.canvas.height = 0;
  }

  async closeDocument() {
    ++this.renderGeneration;
    for (const task of this.renderTasks.values()) task.cancel();
    this.renderTasks.clear();
    this.clearPageStack();
    if (this.loadingTask) {
      try { await this.loadingTask.destroy(); } catch {}
      this.loadingTask = null;
    } else if (this.document) {
      try { await this.document.destroy(); } catch {}
    }
    this.document = null;
  }

  async destroy() {
    ++this.generation;
    clearTimeout(this.resizeTimer);
    if (this.scrollFrame) cancelAnimationFrame(this.scrollFrame);
    this.container.removeEventListener('scroll', this.scrollHandler);
    this.resizeObserver.disconnect();
    this.pageObserver.disconnect();
    await this.closeDocument();
  }
}
