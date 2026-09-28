(() => {
  'use strict';
  const byId = (id) => document.getElementById(id);
  const spec = window.HOLOUL_CONTRACT;
  const metadata = window.HOLOUL_REFERENCE;
  if (!spec || !metadata || !window.SwaggerUIBundle) {
    byId('load-error').hidden = false;
    byId('result-count').textContent = 'Reference unavailable';
    return;
  }
  const methods = new Set(['get', 'post', 'put', 'patch', 'delete', 'head', 'options', 'trace']);
  const operations = Object.entries(spec.paths).flatMap(([path, item]) =>
    Object.entries(item).filter(([method]) => methods.has(method)).map(([method, operation]) => ({
      path, method, operation,
      terms: [path, method, operation.operationId, operation.summary, ...(operation.tags || [])].join(' ').toLowerCase(),
    })));
  const preferred = ['Identity', 'Staff onboarding', 'Customers', 'Admin integration', 'Guest intake', 'Project intake', 'Taxonomy', 'Documents', 'Discovery', 'Proposals', 'Projects', 'Milestones', 'Reporting', 'Notifications', 'AI', 'Audit', 'API'];
  const domains = [...new Set(operations.flatMap(({ operation }) => operation.tags || ['Other']))]
    .sort((a, b) => (preferred.indexOf(a) < 0 ? 999 : preferred.indexOf(a)) - (preferred.indexOf(b) < 0 ? 999 : preferred.indexOf(b)) || a.localeCompare(b));
  let selectedDomain = '';
  let search = '';
  let timer;
  let ui;

  byId('contract-version').textContent = spec.info.version;
  byId('openapi-version').textContent = `OpenAPI ${spec.openapi}`;
  byId('operation-count').textContent = operations.length;
  byId('schema-count').textContent = Object.keys(spec.components.schemas).length;
  byId('footer-count').textContent = operations.length;
  byId('contract-hash').value = metadata.sha256;

  const makeDomainButton = (domain) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'domain-link';
    button.dataset.domain = domain;
    const label = document.createElement('span');
    label.textContent = domain || 'All operations';
    const count = document.createElement('span');
    count.className = 'domain-count';
    count.textContent = domain ? operations.filter(({ operation }) => operation.tags?.includes(domain)).length : operations.length;
    button.append(label, count);
    button.addEventListener('click', () => selectDomain(domain));
    return button;
  };
  ['', ...domains].forEach((domain) => {
    byId('domain-nav').append(makeDomainButton(domain));
    const option = document.createElement('option');
    option.value = domain;
    option.textContent = domain || 'All operations';
    byId('domain-select').append(option);
  });

  function selectDomain(domain) {
    selectedDomain = domain;
    render();
    byId('operations-title').scrollIntoView({ block: 'start' });
  }
  function filteredSpec(matches) {
    const paths = {};
    for (const { path, method, operation } of matches) {
      if (!paths[path]) {
        paths[path] = Object.fromEntries(Object.entries(spec.paths[path]).filter(([key]) => !methods.has(key)));
      }
      paths[path][method] = operation;
    }
    return { ...spec, paths };
  }
  function render() {
    const words = search.trim().toLowerCase().split(/\s+/).filter(Boolean);
    const matches = operations.filter(({ operation, terms }) =>
      (!selectedDomain || operation.tags?.includes(selectedDomain)) && words.every((word) => terms.includes(word)));
    document.querySelectorAll('.domain-link').forEach((button) => {
      button.setAttribute('aria-current', String(button.dataset.domain === selectedDomain));
    });
    byId('domain-select').value = selectedDomain;
    byId('clear-filters').hidden = !search && !selectedDomain;
    byId('result-count').textContent = `${matches.length} of ${operations.length} operations${selectedDomain ? ` · ${selectedDomain}` : ' · All domains'}`;
    byId('empty-state').hidden = matches.length > 0;
    const renderedSpec = filteredSpec(matches);
    if (ui) {
      ui.specActions.updateSpec(JSON.stringify(renderedSpec));
    } else {
      ui = SwaggerUIBundle({
        spec: renderedSpec,
        dom_id: '#swagger-ui',
        layout: 'BaseLayout',
        deepLinking: true,
        docExpansion: 'list',
        displayOperationId: true,
        defaultModelsExpandDepth: 0,
        defaultModelExpandDepth: 1,
        supportedSubmitMethods: [],
        tryItOutEnabled: false,
        persistAuthorization: false,
        validatorUrl: null,
        showExtensions: true,
        showCommonExtensions: true,
        filter: false,
        tagsSorter: (a, b) => domains.indexOf(a) - domains.indexOf(b),
        presets: [SwaggerUIBundle.presets.apis],
      });
    }
  }
  function reset() {
    clearTimeout(timer);
    selectedDomain = '';
    search = '';
    byId('operation-search').value = '';
    render();
    byId('operation-search').focus();
  }
  byId('operation-search').addEventListener('input', (event) => {
    search = event.target.value;
    clearTimeout(timer);
    timer = setTimeout(render, 180);
  });
  byId('domain-select').addEventListener('change', (event) => selectDomain(event.target.value));
  byId('clear-filters').addEventListener('click', reset);
  byId('reset-search').addEventListener('click', reset);
  byId('copy-hash').addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(metadata.sha256);
      byId('copy-status').textContent = 'Contract hash copied.';
    } catch {
      byId('contract-hash').focus();
      byId('contract-hash').select();
      byId('copy-status').textContent = 'Hash selected. Use your system copy command.';
    }
  });
  try { render(); }
  catch { byId('load-error').hidden = false; byId('result-count').textContent = 'Reference unavailable'; }
})();
