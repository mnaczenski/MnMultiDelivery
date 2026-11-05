const { PluginBaseClass } = window;

/**
 * Sends the delivery planner's forms in the background and swaps in what
 * depends on the plan: the planner itself, the summary and the order form's
 * cart hash. Without JavaScript the forms post and reload as usual.
 */
export default class MnMultiDeliveryPlugin extends PluginBaseClass {
    static options = {
        updatingText: '',
        updatedText: '',
        summarySelector: '.checkout-aside-summary-list-container',
        hashSelector: '#confirmOrderForm input[name="hash"]',
        submitSelector: '#confirmFormSubmit',
        loadingClass: 'is-loading',
    };

    init() {
        this._queue = Promise.resolve();
        this._status = this._createStatus();

        this.el.addEventListener('change', this._onChange.bind(this));
        this.el.addEventListener('submit', this._onSubmit.bind(this));
    }

    _createStatus() {
        const status = document.createElement('p');
        status.className = 'visually-hidden';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        this.el.after(status);

        return status;
    }

    _onChange(event) {
        const field = event.target.closest('[data-mn-md-auto-submit]');
        if (field?.form) {
            field.form.requestSubmit();
        }
    }

    _onSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        event.preventDefault();

        // Read the values now: the form is replaced before queued changes run.
        const data = new FormData(form, event.submitter);
        const focusId = document.activeElement?.id || null;
        this._queue = this._queue
            .then(() => this._send(form.action, data, focusId))
            .catch(() => (form.isConnected ? form.submit() : window.location.reload()));
    }

    async _send(action, data, focusId) {
        this._setBusy(true);
        try {
            const response = await fetch(action, {
                method: 'POST',
                body: data,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error(`Multi Delivery update failed: ${response.status}`);
            }

            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const meta = doc.querySelector('[data-mn-md-meta]');
            if (!meta) {
                throw new Error('Multi Delivery update returned no data');
            }
            if (meta.dataset.redirect) {
                window.location.href = meta.dataset.redirect;
                return;
            }
            if (meta.dataset.reload === '1') {
                window.location.reload();
                return;
            }

            this._swap(doc, meta);
            this._status.textContent = this.options.updatedText;
            document.getElementById(focusId)?.focus({ preventScroll: true });
            this.$emitter.publish('updated');
        } finally {
            this._setBusy(false);
        }
    }

    _swap(doc, meta) {
        const planner = doc.querySelector('template[data-mn-md-planner]');
        if (planner) {
            this.el.replaceChildren(document.importNode(planner.content, true));
        }

        const summary = doc.querySelector('template[data-mn-md-summary]');
        const summaryContainer = document.querySelector(this.options.summarySelector);
        if (summary && summaryContainer) {
            summaryContainer.replaceChildren(document.importNode(summary.content, true));
        }

        document.querySelectorAll(this.options.hashSelector).forEach((input) => {
            input.value = meta.dataset.hash;
        });
        const submit = document.querySelector(this.options.submitSelector);
        if (submit) {
            submit.disabled = meta.dataset.blockResubmit === '1';
        }

        window.PluginManager.initializePlugins();
    }

    _setBusy(busy) {
        const section = this.el.querySelector('.mn-md');
        section?.classList.toggle(this.options.loadingClass, busy);
        section?.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (busy) {
            this._status.textContent = this.options.updatingText;
        }
    }
}
