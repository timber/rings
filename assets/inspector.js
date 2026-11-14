/**
 * Twig inspector for Query Monitor's Twig panel.
 *
 * - Inspect mode (button in QM's title bar), like browsers' inspectors but for templates: hovering the page highlights
 *   the output of the template or block rendered there, with its size and the template line of the hovered tag;
 *   clicking selects its panel row.
 * - Panel rows: hovering one highlights the output of its rendering of a template or block in the page, clicking
 *   scrolls to it.
 *
 * It relies on what's output server side:
 * - `data-twig="template:line"` attributes on the tags written in templates (SourceAnnotationExtension),
 * - `<!--twig:start {json}-->` / `<!--twig:end id-->` comments around the output of each rendering of a template or
 *   block (TemplateMarkerExtension),
 * - `data-qm-twig-*` attributes on panel rows and edit links, and `window.QMTwigInspector` (Output).
 *
 * What's read from the page may come from user content: it's only ever inserted as text.
 */
/*! Floating UI (https://floating-ui.com), bundled in assets/dist: Copyright (c) 2021-present Floating UI contributors, MIT License */
import { arrow, autoUpdate, computePosition, flip, shift } from '@floating-ui/dom';

const SELECTORS = {
  qmHost: '#query-monitor-container',
  // QM's UI: events from its shadow root are retargeted to the host element
  qm: '#query-monitor-container, #query-monitor-fallbacks',
  // QM's panel, when docked at the bottom
  qmBottomPanel: '.qm-container.qm-show:not(.qm-show-right)',
  // The inspect button gets the settings button's classes
  qmSettingsButton: '.qm-button-container-settings:not(#qm-twig-inspect-btn)',
  inspectButton: '#qm-twig-inspect-btn',
  row: 'tr[data-qm-twig-template]',
  editLink: 'a[data-qm-twig-file]',
};

/**
 * Editor link formats, as in Query Monitor 4's settings.
 */
const EDITOR_FORMATS = {
  atom: 'atom://open/?url=file://%1$s&line=%2$s',
  cursor: 'cursor://file/%1$s:%2$s',
  netbeans: 'nbopen://%1$s:%2$s',
  nova: 'nova://open?path=%1$s&line=%2$s',
  phpstorm: 'phpstorm://open?file=%1$s&line=%2$s',
  sublime: 'subl://open/?url=file://%1$s&line=%2$s',
  textmate: 'txmt://open/?url=file://%1$s&line=%2$s',
  vscode: 'vscode://file/%1$s:%2$s',
  zed: 'zed://file/%1$s:%2$s',
};

/** How long the output of a template stays highlighted after clicking its row */
const ROW_CLICK_HIGHLIGHT_DURATION = 1500;

/* Editor links ------------------------------------------------------------------------------------------------------ */

/**
 * Edit link for a (path-mapped) file, like QM 4 builds them: the editor chosen in QM's settings first, then the file
 * link format (xdebug.file_link_format / qm/output/file_link_format).
 */
function getEditUrl(file, line) {
  let editor = '';
  try {
    editor = localStorage.getItem('qm-editor') || '';
  } catch {
    // Storage unavailable
  }

  const format = (Object.hasOwn(EDITOR_FORMATS, editor) && EDITOR_FORMATS[editor])
    || window.QueryMonitorData?.l10n?.file_link_format
    || '';
  if (!format || !file) {
    return '';
  }

  return format.replace(/%1\$s/g, encodeURIComponent(file)).replace(/%2\$[sd]/g, String(line || 1));
}

/* Where elements come from ------------------------------------------------------------------------------------------ */

/**
 * @typedef {{template: string, line: number}} Source
 * @typedef {Source & {id: string, start: Comment, elements: Element[]}} Rendering
 */

/**
 * Template and line an element's tag is written at, from its `data-twig` attribute.
 *
 * @returns {Source | null}
 */
function getAnnotation(element) {
  const annotation = element.getAttribute('data-twig') ?? '';
  const separator = annotation.lastIndexOf(':');
  if (separator <= 0) {
    return null;
  }

  return {
    template: annotation.slice(0, separator),
    line: Number.parseInt(annotation.slice(separator + 1), 10) || 1,
  };
}

/**
 * Renderings of templates and blocks in the page, from the marker comments around their output.
 */
class Renderings {
  /**
   * @param {Rendering[]} renderings Inner ones first, as they're closed first.
   */
  constructor(renderings) {
    this.all = renderings;

    /** @type {Map<Element, Rendering[]>} Renderings each top-level element was output by, innermost first */
    this.byElement = new Map();
    for (const rendering of renderings) {
      for (const element of rendering.elements) {
        if (!this.byElement.has(element)) {
          this.byElement.set(element, []);
        }
        this.byElement.get(element).push(rendering);
      }
    }
  }

  static collect() {
    const renderings = [];
    const open = [];
    const walker = document.createTreeWalker(document, NodeFilter.SHOW_COMMENT);

    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
      if (node.data.startsWith('twig:start ')) {
        const start = parseStartMarker(node);
        if (start) {
          open.push(start);
        }
      } else if (node.data.startsWith('twig:end ')) {
        const id = node.data.slice('twig:end '.length);
        const index = open.findLastIndex((start) => start.id === id);
        if (index !== -1) {
          // Drop the matching start along with any unclosed one opened after it
          const [start] = open.splice(index);
          renderings.push({ ...start, elements: getElementsBetween(start.start, node) });
        }
      }
    }

    return new Renderings(renderings);
  }

  /**
   * Whether the element is the top-level element of a rendering, or has a `data-twig` annotation.
   */
  isInspectable(element) {
    return element.hasAttribute('data-twig') || this.byElement.has(element);
  }

  /**
   * The closest inspectable element, from the given one up.
   */
  findInspectable(element) {
    for (let current = element; current; current = current.parentElement) {
      if (this.isInspectable(current)) {
        return current;
      }
    }
    return null;
  }

  /**
   * Renderings the element was output by, innermost first.
   *
   * @returns {Rendering[]}
   */
  getEnclosing(element) {
    for (let current = element; current; current = current.parentElement) {
      if (this.byElement.has(current)) {
        return this.byElement.get(current);
      }
    }
    return [];
  }

  /**
   * The rendering the element comes from: the one of the template its tag is written in when known, or else the
   * innermost one it was output by.
   *
   * @returns {Rendering | null}
   */
  getOrigin(element) {
    const annotation = getAnnotation(element);
    let innermost = null;

    for (let current = element; current; current = current.parentElement) {
      for (const rendering of this.byElement.get(current) ?? []) {
        innermost ??= rendering;
        // Tags in a block are written in the template, but rendered as "template::block(name)"
        if (
          !annotation
          || rendering.template === annotation.template
          || rendering.template.startsWith(`${annotation.template}::block(`)
        ) {
          return rendering;
        }
      }
    }

    return innermost;
  }

  /**
   * Renderings a panel row stands for, with visible output, in document order: the row's own rendering, or all those
   * of its template when the row doesn't know its rendering (e.g. markers weren't enabled for its environment).
   *
   * @returns {Rendering[]}
   */
  getVisibleForRow(row) {
    const { qmTwigRendering: id, qmTwigTemplate: template } = row.dataset;
    return this.all
      .filter((rendering) => (id ? rendering.id === id : rendering.template === template))
      .filter((rendering) => getBoundingRect(rendering.elements))
      .sort((a, b) => (a.start.compareDocumentPosition(b.start) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1));
  }
}

/**
 * @returns {{id: string, template: string, line: number, start: Comment} | null}
 */
function parseStartMarker(comment) {
  let data;
  try {
    // JSON with its double quotes encoded as &quot; (see TemplateMarkerExtension)
    data = JSON.parse(comment.data.slice('twig:start '.length).replaceAll('&quot;', '"'));
  } catch {
    return null;
  }
  if (typeof data?.template !== 'string' || typeof data.id !== 'string') {
    return null;
  }

  return { id: data.id, template: data.template, line: Number.parseInt(data.line, 10) || 1, start: comment };
}

/**
 * Top-level elements between two nodes, in document order.
 * The nodes usually share a parent, but the HTML parser may have moved one of them (e.g. out of a table).
 */
function getElementsBetween(start, end) {
  const elements = [];
  let node = getNextNode(start);

  while (node && node !== end) {
    if (node.contains(end)) {
      // The end marker is inside this node: it's only partially covered, so look at its children
      node = node.firstChild;
      continue;
    }
    if (node.nodeType === Node.ELEMENT_NODE) {
      elements.push(node);
    }
    node = getNextNode(node);
  }

  return elements;
}

function getNextNode(node) {
  while (node && !node.nextSibling) {
    node = node.parentNode;
  }
  return node ? node.nextSibling : null;
}

/**
 * Smallest rectangle containing the visible elements, or null if none is visible.
 */
function getBoundingRect(elements) {
  const rects = elements.map((element) => element.getBoundingClientRect()).filter((rect) => rect.width || rect.height);
  if (!rects.length) {
    return null;
  }

  const top = Math.min(...rects.map((rect) => rect.top));
  const left = Math.min(...rects.map((rect) => rect.left));
  const bottom = Math.max(...rects.map((rect) => rect.bottom));
  const right = Math.max(...rects.map((rect) => rect.right));
  return { top, left, width: right - left, height: bottom - top };
}

/**
 * Like composedPath(), but only elements: events from QM's shadow root are retargeted to its host.
 *
 * @returns {Element[]}
 */
function getElementPath(event) {
  return event.composedPath().filter((node) => node instanceof Element);
}

/* Query Monitor ----------------------------------------------------------------------------------------------------- */

/**
 * Query Monitor 4 renders its UI in a shadow root, with its own script. Legacy panels like the Twig one are output in
 * a fallbacks container, and moved into the shadow root when shown.
 */
class QueryMonitor {
  /**
   * @param {{id: string, title: string}} panel The Twig panel.
   */
  constructor(panel) {
    this.host = document.querySelector(SELECTORS.qmHost);
    this.panel = panel;
  }

  get root() {
    return this.host?.shadowRoot ?? null;
  }

  /**
   * Call the callback with QM's shadow root once it's there, and whenever QM renders: the shadow root is attached by
   * QM's script, possibly after this one runs, and QM re-renders when its layout changes.
   */
  onRender(callback) {
    let observer = null;
    const check = () => {
      if (!this.root) {
        return;
      }
      if (!observer) {
        observer = new MutationObserver(() => callback(this.root));
        observer.observe(this.root, { childList: true, subtree: true });
      }
      callback(this.root);
    };

    check();
    document.addEventListener('DOMContentLoaded', check);
    window.addEventListener('load', check);
  }

  /**
   * Whether the node is currently shown by QM (in its shadow root).
   */
  isShown(node) {
    return Boolean(this.root) && node.getRootNode() === this.root;
  }

  /**
   * Find in QM's shadow root first, and in the fallbacks container (i.e. the document) otherwise.
   */
  querySelector(selector) {
    return this.root?.querySelector(selector) ?? document.querySelector(selector);
  }

  /**
   * Panel row of a rendering, or of its template if the rendering isn't listed.
   */
  findRow(rendering) {
    return this.querySelector(`tr[data-qm-twig-rendering="${CSS.escape(rendering.id)}"]`)
      ?? this.querySelector(`tr[data-qm-twig-template="${CSS.escape(rendering.template)}"]`);
  }

  /**
   * Open QM on the Twig panel, and resolve once the node (of the panel) is shown.
   */
  showTwigPanel(node) {
    if (!this.isShown(node)) {
      this.switchToTwigPanel();
    }

    return new Promise((resolve) => {
      let frames = 30;
      const wait = () => (this.isShown(node) || --frames <= 0 ? resolve() : requestAnimationFrame(wait));
      requestAnimationFrame(wait);
    });
  }

  /**
   * Switch QM to the Twig panel like users do, as QM doesn't provide an API for it: with its panel select when it's
   * docked on the right, the link to the panel in the admin bar if it's shown, or else its tab when docked at the
   * bottom (which can only be told by its title).
   */
  switchToTwigPanel() {
    const { id, title } = this.panel;

    const select = this.root?.querySelector(`option[value="${CSS.escape(id)}"]`)?.closest('select');
    if (select) {
      select.value = id;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }

    const link = document.querySelector(`#wp-admin-bar-query-monitor a[href="#qm-${CSS.escape(id)}"]`);
    if (link) {
      link.click();
      return;
    }

    [...(this.root?.querySelectorAll('[role="tab"]') ?? [])].find((tab) => tab.textContent.trim() === title)?.click();
  }

  /**
   * Height of the viewport not covered by QM's panel, when it's docked at the bottom.
   */
  getVisibleHeight() {
    const top = this.root?.querySelector(SELECTORS.qmBottomPanel)?.getBoundingClientRect().top;
    return top > 0 ? top : window.innerHeight;
  }
}

/* Highlighter ------------------------------------------------------------------------------------------------------- */

/**
 * Highlights groups of elements in the page, one box per group, the first one labelled. The boxes follow the elements
 * (e.g. while scrolling) and stay above QM's panel.
 *
 * Highlights have an owner, so one doesn't hide another's: the inspector and the panel both use it.
 */
class Highlighter {
  constructor(qm) {
    this.qm = qm;
    this.current = null;
    this.frame = null;

    this.layer = document.createElement('div');
    this.layer.className = 'qm-twig-highlights';
    this.layer.hidden = true;
    document.body.append(this.layer);
  }

  /**
   * @param {string}      owner
   * @param {Element[][]} groups
   * @param {{label?: string, duration?: number}} options Highlights fade out after the duration.
   */
  show(owner, groups, { label = '', duration = Infinity } = {}) {
    this.current = { owner, groups, label, until: performance.now() + duration };
    this.layer.hidden = false;
    this.layer.classList.remove('qm-twig-highlights-fade');
    cancelAnimationFrame(this.frame);
    this.frame = requestAnimationFrame(this.draw);
  }

  hide(owner) {
    if (this.current?.owner !== owner) {
      return;
    }

    this.current = null;
    cancelAnimationFrame(this.frame);
    this.layer.hidden = true;
  }

  draw = (now) => {
    if (!this.current) {
      return;
    }

    const visibleHeight = this.qm.getVisibleHeight();
    const rects = this.current.groups
      .map(getBoundingRect)
      .filter(Boolean)
      .map((rect) => {
        const top = Math.max(0, rect.top);
        const bottom = Math.min(rect.top + rect.height, visibleHeight);
        return { ...rect, top, height: bottom - top };
      })
      .filter((rect) => rect.height > 0);

    while (this.layer.children.length < rects.length) {
      const box = document.createElement('div');
      box.className = 'qm-twig-highlight';
      this.layer.append(box);
    }

    [...this.layer.children].forEach((box, index) => {
      const rect = rects[index];
      box.hidden = !rect;
      if (!rect) {
        return;
      }

      Object.assign(box.style, {
        top: `${rect.top}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
        height: `${rect.height}px`,
      });
      // Only the first box is labelled, inside it when there's no room above
      box.dataset.label = index === 0 ? this.current.label : '';
      box.classList.toggle('qm-twig-highlight-label-inside', rect.top < 20);
    });

    if (now < this.current.until) {
      this.frame = requestAnimationFrame(this.draw);
    } else {
      this.layer.classList.add('qm-twig-highlights-fade');
    }
  };
}

/* Popover ----------------------------------------------------------------------------------------------------------- */

/**
 * Tooltip next to the inspected elements, like browsers' inspectors: the template or block they were output by, their
 * size, and the hovered tag. It doesn't get the pointer: clicking selects in the panel, which has the editor links.
 */
class Popover {
  constructor() {
    this.cleanup = null;

    // The wrapper's padding leaves room for the arrow
    this.wrapper = document.createElement('div');
    this.wrapper.className = 'qm-twig-popover-wrapper';
    this.wrapper.hidden = true;

    const popover = document.createElement('div');
    popover.className = 'qm-twig-popover';
    this.arrow = document.createElement('div');
    this.arrow.className = 'qm-twig-popover-arrow';

    const header = document.createElement('div');
    header.className = 'qm-twig-popover-header';
    this.template = document.createElement('span');
    this.template.className = 'qm-twig-popover-template';
    this.size = document.createElement('span');
    this.size.className = 'qm-twig-popover-size';
    header.append(this.template, this.size);

    this.tag = document.createElement('div');
    this.tag.className = 'qm-twig-popover-tag';

    popover.append(this.arrow, header, this.tag);
    this.wrapper.append(popover);
    document.body.append(this.wrapper);
  }

  /**
   * @param {Element[]} elements
   * @param {string}    template Template or block they were output by.
   */
  show(elements, template) {
    this.template.textContent = template;
    this.wrapper.hidden = false;

    // Several elements: next to the smallest rectangle containing them, as a Floating UI virtual element
    const reference = elements.length === 1 ? elements[0] : {
      getBoundingClientRect: () => {
        const rect = getBoundingRect(elements);
        return rect ? new DOMRect(rect.left, rect.top, rect.width, rect.height) : new DOMRect();
      },
      contextElement: elements[0],
    };

    this.cleanup?.();
    this.cleanup = autoUpdate(reference, this.wrapper, () => this.position(reference), { animationFrame: true });
  }

  /**
   * @param {string} tag The hovered tag and where it's written, if known.
   */
  setTag(tag) {
    this.tag.textContent = tag;
    this.tag.hidden = !tag;
  }

  hide() {
    this.cleanup?.();
    this.cleanup = null;
    this.wrapper.hidden = true;
  }

  /**
   * @param {Element | {getBoundingClientRect: () => DOMRect, contextElement: Element}} reference
   */
  async position(reference) {
    // Size as browsers' inspectors show it, kept up to date like the position
    const { width, height } = reference.getBoundingClientRect();
    const size = `${Number(width.toFixed(2))} × ${Number(height.toFixed(2))}`;
    if (this.size.textContent !== size) {
      this.size.textContent = size;
    }

    const { x, y, placement, middlewareData } = await computePosition(reference, this.wrapper, {
      placement: 'top',
      // Over the elements when there's no room above nor below, e.g. for the template of the whole page
      middleware: [flip(), shift({ crossAxis: true, padding: 4 }), arrow({ element: this.arrow })],
    });

    Object.assign(this.wrapper.style, { left: `${x}px`, top: `${y}px` });
    this.wrapper.dataset.placement = placement;
    this.arrow.hidden = Boolean(middlewareData.shift?.y);

    const staticSide = { top: 'bottom', right: 'left', bottom: 'top', left: 'right' }[placement.split('-')[0]];
    Object.assign(this.arrow.style, {
      left: middlewareData.arrow?.x != null ? `${middlewareData.arrow.x}px` : '',
      top: middlewareData.arrow?.y != null ? `${middlewareData.arrow.y}px` : '',
      right: '',
      bottom: '',
      [staticSide]: '0',
    });
  }
}

/* Panel ------------------------------------------------------------------------------------------------------------- */

/**
 * The Twig panel's rows: highlighting the output of their rendering of a template or block in the page on hover,
 * scrolling to it on click; and their states (hovered or selected from the inspector).
 */
class Panel {
  constructor(qm, highlighter) {
    this.qm = qm;
    this.highlighter = highlighter;
    this.hoveredRow = null;
    /** @type {Record<'hovered' | 'selected', Element | null>} */
    this.rowStates = { hovered: null, selected: null };

    // mousemove rather than mouseover, which isn't reliably dispatched when moving from row to row in QM's panel
    document.addEventListener('mousemove', this.onMouseMove);
    document.documentElement.addEventListener('mouseleave', () => this.hoverRow(null));
    document.addEventListener('click', this.onClick);

    // Edit links: set now, and again on click as the editor may have been changed in QM's settings since
    document.querySelectorAll(SELECTORS.editLink).forEach(updateEditLink);
    document.addEventListener('click', this.onEditLinkClick, true);
  }

  /**
   * Mark a row as hovered or selected (one row at a time per state), and bring it into view in the panel.
   */
  setRowState(state, row) {
    this.rowStates[state]?.classList.remove(`qm-twig-row-${state}`);
    this.rowStates[state] = row;
    if (!row) {
      return;
    }

    row.classList.add(`qm-twig-row-${state}`);
    if (this.qm.isShown(row)) {
      row.scrollIntoView({ block: state === 'selected' ? 'center' : 'nearest' });
    }
  }

  /**
   * Select a row, opening QM on the Twig panel first if needed.
   */
  async selectRow(row) {
    await this.qm.showTwigPanel(row);
    this.setRowState('selected', row);
  }

  onMouseMove = (event) => {
    const row = getElementPath(event).find((element) => element.matches(SELECTORS.row)) ?? null;
    if (row !== this.hoveredRow) {
      this.hoverRow(row);
    }
  };

  hoverRow(row) {
    this.hoveredRow = row;
    const renderings = row ? Renderings.collect().getVisibleForRow(row) : [];
    if (!renderings.length) {
      this.highlighter.hide('panel');
      return;
    }

    this.highlighter.show('panel', renderings.map((rendering) => rendering.elements), {
      label: row.dataset.qmTwigTemplate,
    });
  }

  onClick = (event) => {
    const path = getElementPath(event);
    // Clicks on links (edit links) keep their behavior
    if (path.some((element) => element.matches('a, button'))) {
      return;
    }

    const row = path.find((element) => element.matches(SELECTORS.row));
    if (row) {
      this.scrollToRow(row);
    }
  };

  /**
   * Scroll to the output of a row's rendering, and highlight it.
   */
  scrollToRow(row) {
    const renderings = Renderings.collect().getVisibleForRow(row);
    if (!renderings.length) {
      return;
    }

    // Center the first one in the part of the viewport above QM's panel
    const rect = getBoundingRect(renderings[0].elements);
    const visibleHeight = this.qm.getVisibleHeight();
    window.scrollTo({
      top: Math.max(0, window.scrollY + rect.top - Math.max(16, (visibleHeight - rect.height) / 2)),
      behavior: 'smooth',
    });

    this.highlighter.show('panel', renderings.map((rendering) => rendering.elements), {
      label: row.dataset.qmTwigTemplate,
      duration: ROW_CLICK_HIGHLIGHT_DURATION,
    });
  }

  onEditLinkClick = (event) => {
    const link = getElementPath(event).find((element) => element.matches(SELECTORS.editLink));
    if (link) {
      updateEditLink(link);
    }
  };
}

function updateEditLink(link) {
  const url = getEditUrl(link.dataset.qmTwigFile, link.dataset.qmTwigLine);
  if (url) {
    link.href = url;
  }
}

/* Inspector --------------------------------------------------------------------------------------------------------- */

/**
 * What's inspected: the output of a rendering of a template or block or, when it isn't known, a tag written in a
 * template.
 *
 * @typedef {{elements: Element[], template: string, rendering: Rendering | null}} Target
 */

/**
 * Inspect mode, toggled with the button in QM's title bar, or stopped with Escape. Like browsers' inspectors, but for
 * templates: hovering the page highlights the output of the innermost template or block rendered there, clicking
 * selects its row in the panel.
 */
class Inspector {
  constructor({ button, popover, highlighter, panel, qm }) {
    this.button = button;
    this.popover = popover;
    this.highlighter = highlighter;
    this.panel = panel;
    this.qm = qm;

    /** @type {Renderings | null} Snapshot taken when starting, as the page may change in between */
    this.renderings = null;
    /** @type {Target | null} */
    this.inspected = null;

    button.addEventListener('click', () => (this.renderings ? this.stop() : this.start()));
  }

  start() {
    this.renderings = Renderings.collect();
    this.button.classList.add('qm-twig-inspect-active');
    document.body.classList.add('qm-twig-inspecting');

    document.addEventListener('mouseover', this.onMouseOver, true);
    document.addEventListener('click', this.onClick, true);
    document.addEventListener('keydown', this.onKeyDown);
  }

  stop() {
    this.renderings = null;
    this.button.classList.remove('qm-twig-inspect-active');
    document.body.classList.remove('qm-twig-inspecting');

    document.removeEventListener('mouseover', this.onMouseOver, true);
    document.removeEventListener('click', this.onClick, true);
    document.removeEventListener('keydown', this.onKeyDown);

    this.inspect(null);
  }

  /**
   * What an element of the page stands for: the innermost rendering it's part of. When it isn't known or visible (e.g.
   * no markers for its environment), the closest tag written in a template.
   *
   * @returns {Target | null}
   */
  getTarget(element) {
    const [rendering] = this.renderings.getEnclosing(element);
    if (rendering && getBoundingRect(rendering.elements)) {
      return { elements: rendering.elements, template: rendering.template, rendering };
    }

    const tag = element.closest('[data-twig]');
    const annotation = tag ? getAnnotation(tag) : null;
    return annotation ? { elements: [tag], template: annotation.template, rendering: null } : null;
  }

  /**
   * The panel row of a target: the one of its rendering, or else of the rendering its tag comes from.
   */
  getRow({ elements: [element], rendering }) {
    const origin = rendering ?? this.renderings.getOrigin(element);
    return origin ? this.qm.findRow(origin) : null;
  }

  /**
   * The tag an element is part of, written in a template, and its line: the closest annotated element in the target.
   */
  describeTag(element, target) {
    for (let current = element; current; current = current.parentElement) {
      if (!target.elements.some((targetElement) => targetElement.contains(current))) {
        return '';
      }

      const annotation = getAnnotation(current);
      if (annotation) {
        // Tags in a block are written in the template, but rendered as "template::block(name)"
        const inTemplate = target.template === annotation.template
          || target.template.startsWith(`${annotation.template}::block(`);
        const where = inTemplate ? `line ${annotation.line}` : `${annotation.template}:${annotation.line}`;
        return `<${current.localName}> ${where}`;
      }
    }

    return '';
  }

  /**
   * Show what a target is (highlight, popover, panel row), or stop showing it.
   *
   * @param {Target | null} target
   */
  inspect(target) {
    this.inspected = target;

    if (!target) {
      this.popover.hide();
      this.highlighter.hide('inspector');
      this.panel.setRowState('hovered', null);
      return;
    }

    this.popover.show(target.elements, target.template);
    this.highlighter.show('inspector', [target.elements]);
    this.panel.setRowState('hovered', this.getRow(target));
  }

  onMouseOver = (event) => {
    // QM itself isn't inspected: what's inspected stays, e.g. to find its row in the panel
    if (event.target.closest(SELECTORS.qm)) {
      return;
    }

    const target = this.getTarget(event.target);
    const key = (current) => current?.rendering ?? current?.elements[0];
    if (key(target) !== key(this.inspected)) {
      this.inspect(target);
    }
    if (target) {
      this.popover.setTag(this.describeTag(event.target, target));
    }
  };

  /**
   * Clicking an element selects what it stands for: its row in the panel becomes the current one.
   */
  onClick = (event) => {
    if (getElementPath(event).some((element) => element.matches(SELECTORS.qm))) {
      return;
    }

    // Don't follow links, submit forms...
    event.preventDefault();
    event.stopPropagation();

    // Before stopping, which forgets the renderings
    const target = this.getTarget(event.target);
    const row = target ? this.getRow(target) : null;
    this.stop();
    if (row) {
      this.panel.selectRow(row);
    }
  };

  onKeyDown = (event) => {
    if (event.key === 'Escape') {
      this.stop();
    }
  };
}

/* Boot -------------------------------------------------------------------------------------------------------------- */

const button = document.querySelector(SELECTORS.inspectButton);
if (button) {
  const { css = '', panel: twigPanel = { id: 'twig_profiler', title: 'Twig' } } = window.QMTwigInspector ?? {};
  const qm = new QueryMonitor(twigPanel);

  // Styles are used in the page (highlights, popover) and in QM's shadow root (button, rows). They're adopted rather
  // than output in the panel, as QM moves the panel in and out of its shadow root when it's shown and hidden.
  const sheet = new CSSStyleSheet();
  sheet.replaceSync(css);
  const adoptSheet = (root) => {
    if (!root.adoptedStyleSheets.includes(sheet)) {
      root.adoptedStyleSheets = [...root.adoptedStyleSheets, sheet];
    }
  };
  adoptSheet(document);

  qm.onRender((root) => {
    adoptSheet(root);

    // Next to QM's settings button, with the same look
    const settingsButton = root.querySelector(SELECTORS.qmSettingsButton);
    if (settingsButton && settingsButton.previousElementSibling !== button) {
      if (!button.classList.contains('qm-button-container-settings')) {
        button.classList.add(...settingsButton.classList);
      }
      settingsButton.before(button);
    }
  });

  const highlighter = new Highlighter(qm);
  const panel = new Panel(qm, highlighter);
  new Inspector({ button, popover: new Popover(), highlighter, panel, qm });
}
