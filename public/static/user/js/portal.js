(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    function setStatus(target, message, ok) {
        if (!target) {
            return;
        }
        target.textContent = message || '';
        target.classList.toggle('ok', Boolean(ok));
        target.classList.toggle('error', ok === false);
    }

    function pretty(payload) {
        if (payload === null || payload === undefined) {
            return '';
        }
        if (typeof payload === 'string') {
            return payload;
        }
        return JSON.stringify(payload, null, 2);
    }

    async function request(endpoint, options) {
        const response = await fetch(endpoint, {
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
                ...(options && options.headers ? options.headers : {}),
            },
            ...(options || {}),
        });

        const text = await response.text();
        try {
            return JSON.parse(text);
        } catch (error) {
            return {
                code: 0,
                msg: text || response.statusText,
                data: {},
            };
        }
    }

    function wirePortalForms() {
        document.querySelectorAll('[data-portal-form]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const status = form.querySelector('[data-form-status]');
                setStatus(status, 'Submitting...', null);

                try {
                    const result = await request(form.dataset.endpoint, {
                        method: 'POST',
                        body: new FormData(form),
                    });
                    const ok = Number(result.code) === 1;
                    setStatus(status, result.msg || (ok ? 'Success' : 'Failed'), ok);

                    if (ok && form.dataset.successRedirect) {
                        if (form.dataset.registerLoginEndpoint) {
                            const account = form.elements.email?.value || form.elements.mobile?.value || '';
                            const password = form.elements.password?.value || '';
                            const loginData = new FormData();
                            loginData.set('account', account);
                            loginData.set('password', password);

                            const loginResult = await request(form.dataset.registerLoginEndpoint, {
                                method: 'POST',
                                body: loginData,
                            });

                            if (Number(loginResult.code) !== 1) {
                                setStatus(status, loginResult.msg || 'Registered. Please login manually.', false);
                                return;
                            }
                        }

                        window.location.href = form.dataset.successRedirect;
                    }
                } catch (error) {
                    setStatus(status, error.message, false);
                }
            });
        });
    }

    function endpointMap(element) {
        if (!element) {
            return {};
        }
        return {
            vip: element.dataset.vip,
            balance: element.dataset.balance,
            ledger: element.dataset.ledger,
            withdrawals: element.dataset.withdrawals,
            invite: element.dataset.invite,
            inviteRecords: element.dataset.inviteRecords,
            activation: element.dataset.activation,
            withdrawalRequest: element.dataset.withdrawalRequest,
            logout: element.dataset.logout,
        };
    }

    async function loadBox(name, endpoint) {
        const box = document.querySelector(`[data-dashboard-box="${name}"]`);
        if (!box || !endpoint) {
            return;
        }
        box.textContent = 'Loading...';
        try {
            const result = await request(endpoint);
            box.textContent = `${result.msg || ''}\n${pretty(result.data)}`.trim();
        } catch (error) {
            box.textContent = error.message;
        }
    }

    function wireDashboard() {
        const endpointElement = document.querySelector('[data-dashboard-endpoints]');
        if (!endpointElement) {
            return;
        }
        const endpoints = endpointMap(endpointElement);

        ['vip', 'balance', 'ledger', 'withdrawals', 'invite', 'inviteRecords'].forEach((name) => {
            loadBox(name, endpoints[name]);
        });

        document.querySelectorAll('[data-refresh]').forEach((button) => {
            button.addEventListener('click', () => loadBox(button.dataset.refresh, endpoints[button.dataset.refresh]));
        });

        const activationForm = document.querySelector('[data-dashboard-form="activation"]');
        if (activationForm) {
            activationForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const status = activationForm.querySelector('[data-form-status]');
                try {
                    const result = await request(endpoints.activation, {
                        method: 'POST',
                        body: new FormData(activationForm),
                    });
                    const ok = Number(result.code) === 1;
                    setStatus(status, result.msg || (ok ? 'Redeemed' : 'Failed'), ok);
                    if (ok) {
                        loadBox('vip', endpoints.vip);
                        loadBox('balance', endpoints.balance);
                    }
                } catch (error) {
                    setStatus(status, error.message, false);
                }
            });
        }

        const withdrawalForm = document.querySelector('[data-dashboard-form="withdrawal"]');
        if (withdrawalForm) {
            withdrawalForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const status = withdrawalForm.querySelector('[data-form-status]');
                try {
                    const result = await request(endpoints.withdrawalRequest, {
                        method: 'POST',
                        body: new FormData(withdrawalForm),
                    });
                    const ok = Number(result.code) === 1;
                    setStatus(status, result.msg || (ok ? 'Requested' : 'Failed'), ok);
                    if (ok) {
                        loadBox('withdrawals', endpoints.withdrawals);
                        loadBox('balance', endpoints.balance);
                    }
                } catch (error) {
                    setStatus(status, error.message, false);
                }
            });
        }

        document.querySelector('[data-portal-logout]')?.addEventListener('click', async () => {
            const status = document.querySelector('[data-dashboard-status]');
            try {
                const result = await request(endpoints.logout, { method: 'POST', body: new FormData() });
                const ok = Number(result.code) === 1;
                setStatus(status, result.msg || (ok ? 'Logged out' : 'Logout failed'), ok);
                if (ok) {
                    window.location.href = '/u/login';
                }
            } catch (error) {
                setStatus(status, error.message, false);
            }
        });
    }

    wirePortalForms();
    wireDashboard();
}());
