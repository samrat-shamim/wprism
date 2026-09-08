// Invoked by the capsule's host-generated run-code file with explicit owned
// origin/form identity. This collects evidence; the host independently admits
// the complete record. No native form writer is called from an evaluator.
async (page, expected) => {
  const record = {format: 'wprism-wpforms-builder-save/v1', started_ms: Date.now(), ended_ms: null,
    before: null, after: null, events: null, exchanges: [], console: [], page_errors: [], errors: [], drained: false};
  const context = page.context();
  const requests = new Map();
  const tasks = [];
  const listeners = [];
  let frame;
  let observing = false;
  let overflow = false;
  const fail = error => {
    if (record.errors.length < 32) record.errors.push(String(error).slice(0, 2048));
    else overflow = true;
  };
  const check = (value, message) => { if (!value) throw new Error(message); };
  const listen = (object, event, callback) => { object.on(event, callback); listeners.push([object, event, callback]); };
  const bytes = value => {
    if (value === null) return null;
    check(value.length <= 65536, 'body exceeds the closed QR Save lane');
    return {length: value.length, base64: value.toString('base64')};
  };
  const queue = promise => tasks.push(promise.catch(fail));
  const snapshot = () => frame.evaluate(() => {
    const form = document.querySelector('#wpforms-builder-form');
    const controls = window.WPFormsBuilder.serializeAllData(window.jQuery(form));
    const maps = Array.from(document.querySelectorAll('[data-pages]'), element => ({id: element.id, value: element.getAttribute('data-pages')}));
    const result = {url: location.href, home: location.origin, form_id: Number(form.dataset.id),
      nonce: window.wpforms_builder.nonce, ajax_url: window.wpforms_builder.ajax_url,
      controls, page_maps: maps, saved: window.WPFormsBuilder.formIsSaved()};
    if (new TextEncoder().encode(JSON.stringify(result)).length > 65536) throw new Error('Builder observation exceeds the closed QR Save lane');
    return result;
  });
  const drain = async () => {
    // A response header event is not a complete body. The owned DOM timer is
    // only a refusal deadline, never a network-idle or successful-save oracle.
    const deadline = frame.evaluate(() => new Promise(resolve => {
      window.__wprismQrEvidence.drainResolve = resolve;
      window.__wprismQrEvidence.drainTimer = setTimeout(() => resolve(false), 10000);
    })).catch(error => { fail(error); return false; });
    const finish = (async () => {
      let count = -1;
      while (count !== tasks.length) {
        count = tasks.length;
        await Promise.allSettled(tasks);
      }
      return true;
    })();
    const complete = await Promise.race([finish, deadline]);
    if (!complete) fail('body task drain did not complete before the deadline');
    return complete;
  };
  try {
    check(/^http:\/\/localhost:[0-9]{4,5}$/.test(expected.home) && Number.isSafeInteger(expected.form_id)
      && expected.form_id > 0 && ['observe', 'save'].includes(expected.mode), 'explicit owned QR Builder identity and mode required');
    check(context.pages().length === 1 && context.serviceWorkers().length === 0, 'isolated browser context required');
    check(page.url().startsWith(expected.home + '/wp-admin/'), 'wrong owned admin page');
    const candidates = [];
    for (const candidate of page.frames()) {
      if (await candidate.locator('#wpforms-builder-form').count() === 1) candidates.push(candidate);
    }
    check(candidates.length === 1, 'exactly one native Builder frame required');
    frame = candidates[0];
    record.before = await snapshot();
    check(record.before.home === expected.home && record.before.form_id === expected.form_id
      && record.before.ajax_url === expected.home + '/wp-admin/admin-ajax.php', 'wrong native Builder target');
    if (expected.mode === 'observe') return {format: 'wprism-wpforms-builder-baseline/v1', observed_ms: Date.now(), snapshot: record.before};
    await frame.evaluate(() => {
      if (Object.prototype.hasOwnProperty.call(window, '__wprismQrEvidence')) throw new Error('occupied observer namespace');
      const state = window.__wprismQrEvidence = {before_save: [], saved: [], closed_ms: null,
        observationTimer: null, drainTimer: null, drainResolve: null};
      const builder = window.jQuery('#wpforms-builder');
      builder.on('wpformsBeforeSave.wprismQrEvidence', () => {
        if (state.before_save.length >= 2) throw new Error('extra native before-save event');
        // SaveExit calls tinyMCE.triggerSave before this event. Reading here
        // binds the normal serialized whole-form input, including that sync.
        state.before_save.push({at_ms: Date.now(), controls: window.WPFormsBuilder.serializeAllData(window.jQuery('#wpforms-builder-form'))});
      });
      builder.on('wpformsSaved.wprismQrEvidence', (_event, data) => {
        if (state.saved.length >= 2) throw new Error('extra native saved event');
        state.saved.push({at_ms: Date.now(), data: JSON.parse(JSON.stringify(data))});
        // Native success remains the oracle. This explicitly bounded suffix
        // catches delayed native callbacks; it proves no indefinite quiescence.
        if (state.saved.length === 1) state.observationTimer = setTimeout(() => { state.closed_ms = Date.now(); }, 250);
      });
    });
    observing = true;
    listen(context, 'request', request => {
      try {
        check(requests.size < 16, 'extra browser requests exceed the closed Save lane');
        const item = {ordinal: requests.size + 1, started_ms: Date.now(), method: request.method(), url: request.url(),
          resource_type: request.resourceType(), redirected: request.redirectedFrom() !== null,
          request_headers: null, request_body: null, response: null, failure: null};
        requests.set(request, item);
        record.exchanges.push(item);
        item.request_body = bytes(request.postDataBuffer());
        queue(request.headersArray().then(headers => { item.request_headers = headers; }));
      } catch (error) { fail(error); }
    });
    listen(context, 'response', response => {
      // Start body retrieval at the response event, before asynchronous header
      // reads or native UI navigation could retire the underlying response.
      const body = response.body();
      body.catch(() => {});
      queue((async () => {
        const item = requests.get(response.request());
        check(item, 'response lacks an observed request');
        item.response = {status: response.status(), received_ms: Date.now(), headers: null, body: null, finished_ms: null};
        item.response.headers = await response.headersArray();
        item.response.body = bytes(await body);
        item.response.finished_ms = Date.now();
      })());
    });
    listen(context, 'requestfailed', request => {
      const item = requests.get(request);
      if (item) item.failure = request.failure();
      else fail('request failure lacks an observed request');
    });
    listen(page, 'console', message => {
      if (record.console.length >= 32) { overflow = true; return; }
      record.console.push({type: message.type(), text: message.text().slice(0, 4096)});
    });
    listen(page, 'pageerror', error => {
      if (record.page_errors.length >= 32) { overflow = true; return; }
      record.page_errors.push(String(error).slice(0, 4096));
    });
    for (const [object, event] of [[page, 'crash'], [page, 'close'], [page, 'framenavigated'],
      [page, 'framedetached'], [context, 'page'], [context, 'serviceworker']]) {
      listen(object, event, () => fail('unexpected browser lifecycle event: ' + event));
    }
    // The driver must retain a fresh snapshot of this exact native control
    // before invoking the collector. This is the only authoring action here.
    await frame.locator('#wpforms-save').click({timeout: 10000});
    await frame.waitForFunction(() => window.__wprismQrEvidence.saved.length === 1
      && window.__wprismQrEvidence.closed_ms !== null && window.WPFormsBuilder.formIsSaved(), null, {timeout: 10000});
    record.after = await snapshot();
  } catch (error) { fail(error); }
  finally {
    if (observing) {
      try { record.drained = await drain(); } catch (error) { fail(error); }
      try {
        record.events = await frame.evaluate(() => {
          const state = window.__wprismQrEvidence;
          clearTimeout(state.observationTimer);
          clearTimeout(state.drainTimer);
          if (state.drainResolve) state.drainResolve(false);
          window.jQuery('#wpforms-builder').off('.wprismQrEvidence');
          delete window.__wprismQrEvidence;
          return {before_save: state.before_save, saved: state.saved, closed_ms: state.closed_ms};
        });
      } catch (error) { fail(error); }
    }
    for (const [object, event, callback] of listeners) object.off(event, callback);
    if (overflow) fail('diagnostic count exceeded the closed Save lane');
    record.ended_ms = Date.now();
  }
  return record;
}
