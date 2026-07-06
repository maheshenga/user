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

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function valueOrDash(value) {
        if (value === null || value === undefined || value === '') {
            return '-';
        }
        return escapeHtml(value);
    }

    function row(label, value) {
        return `<div class="summary-row"><span>${escapeHtml(label)}</span><strong>${valueOrDash(value)}</strong></div>`;
    }

    function rawFallback(data) {
        return `<details class="raw-payload"><summary>Raw data</summary><pre>${escapeHtml(pretty(data))}</pre></details>`;
    }

    function renderList(items, emptyText, renderer) {
        if (!Array.isArray(items) || items.length === 0) {
            return `<div class="empty-state">${escapeHtml(emptyText)}</div>`;
        }

        return `<div class="summary-list">${items.map(renderer).join('')}</div>`;
    }

    function listFromPayload(data, keys) {
        if (Array.isArray(data)) {
            return data;
        }

        for (const key of keys) {
            if (Array.isArray(data?.[key])) {
                return data[key];
            }
        }

        return [];
    }

    const renderers = {
        vip(data) {
            const user = data.user || data.vip || data;
            return [
                row('VIP Level', user.vip_level ?? user.level),
                row('Status', user.vip_status ?? user.status ?? (data.active === true ? 'active' : data.active === false ? 'inactive' : undefined)),
                row('Started At', user.vip_started_at ?? user.started_at),
                row('Expired At', user.vip_expired_at ?? user.expired_at ?? data.vip_expires_at),
                row('Active Records', data.record_count),
                rawFallback(data),
            ].join('');
        },
        balance(data) {
            return [
                row('Available', data.available_balance ?? data.available),
                row('Frozen', data.frozen_balance ?? data.frozen),
                row('Total Earned', data.total_earned),
                row('Total Withdrawn', data.total_withdrawn),
                rawFallback(data),
            ].join('');
        },
        ledger(data) {
            const rows = listFromPayload(data, ['rows', 'list', 'ledger']);
            return renderList(rows, 'No balance ledger records.', (item) => [
                '<article class="summary-item">',
                row('Amount', item.amount),
                row('Type', item.type ?? item.direction),
                row('Reason', item.reason ?? item.remark),
                row('Time', item.create_time ?? item.created_at),
                '</article>',
            ].join('')) + rawFallback(data);
        },
        invite(data) {
            const code = data.invite_code || data.default_code || data.code || {};
            return [
                row('Invite Code', code.code ?? data.code),
                row('Invite URL', code.url ?? data.invite_url),
                row('Level 1 Total', data.direct_count ?? data.level1_total ?? data.first_level_total),
                row('Level 2 Total', data.second_level_count ?? data.level2_total ?? data.second_level_total),
                rawFallback(data),
            ].join('');
        },
        inviteRecords(data) {
            const rows = listFromPayload(data, ['rows', 'list', 'records']);
            return renderList(rows, 'No invite records.', (item) => [
                '<article class="summary-item">',
                row('User', item.email ?? item.mobile ?? item.nickname ?? item.user_id),
                row('Status', item.status),
                row('Path', item.level_path ?? item.level),
                row('Registered At', item.registered_at ?? item.create_time),
                '</article>',
            ].join('')) + rawFallback(data);
        },
        withdrawals(data) {
            const rows = listFromPayload(data, ['rows', 'list', 'withdrawals']);
            return renderList(rows, 'No withdrawal records.', (item) => [
                '<article class="summary-item">',
                row('No.', item.withdrawal_no ?? item.id),
                row('Amount', item.amount),
                row('Status', item.status),
                row('Account', item.account_no ?? item.account?.account_no ?? item.account_snapshot_json?.account_no),
                row('Review Reason', item.reason),
                row('Audited At', item.audited_at ?? item.approved_at),
                row('Payout Method', item.payout_method),
                row('Payout Transaction', item.payout_transaction_id),
                row('Payout Error', item.payout_error),
                row('Paid At', item.paid_at),
                row('Requested At', item.create_time ?? item.created_at),
                '</article>',
            ].join('')) + rawFallback(data);
        },
    };

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
            session: element.dataset.session,
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
            if (Number(result.code) !== 1) {
                box.textContent = result.msg || 'Request failed.';
                return;
            }

            const rendererName = box.dataset.dashboardRender || name;
            const renderer = renderers[rendererName];
            if (renderer) {
                box.innerHTML = renderer(result.data || {});
                return;
            }

            box.textContent = `${result.msg || ''}\n${pretty(result.data)}`.trim();
        } catch (error) {
            box.textContent = error.message;
        }
    }

    function setDashboardControlsEnabled(enabled) {
        document.querySelectorAll('[data-dashboard-protected]').forEach((element) => {
            element.disabled = !enabled;
        });
    }

    async function ensureDashboardSession(endpoints) {
        const status = document.querySelector('[data-dashboard-status]');

        if (!endpoints.session) {
            setDashboardControlsEnabled(true);
            return true;
        }

        try {
            const result = await request(endpoints.session);
            const ok = Number(result.code) === 1;

            if (!ok) {
                setStatus(status, result.msg || 'User login required.', false);
                setDashboardControlsEnabled(false);
                return false;
            }

            const user = result.data?.user || {};
            const label = document.querySelector('[data-current-user-label]');
            if (label) {
                label.textContent = user.nickname || user.email || user.mobile || `User #${user.id}`;
            }
            setStatus(status, '', null);
            setDashboardControlsEnabled(true);

            return true;
        } catch (error) {
            setStatus(status, error.message, false);
            setDashboardControlsEnabled(false);
            return false;
        }
    }

    function wireDashboard() {
        const endpointElement = document.querySelector('[data-dashboard-endpoints]');
        if (!endpointElement) {
            return;
        }
        const endpoints = endpointMap(endpointElement);
        setDashboardControlsEnabled(false);

        ensureDashboardSession(endpoints).then((loggedIn) => {
            if (!loggedIn) {
                return;
            }

            ['vip', 'balance', 'ledger', 'withdrawals', 'invite', 'inviteRecords'].forEach((name) => {
                loadBox(name, endpoints[name]);
            });
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
