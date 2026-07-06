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

    function setFormBusy(form, busy) {
        form.querySelectorAll('button, input[type="submit"]').forEach((button) => {
            if (button.tagName === 'BUTTON') {
                if (!button.dataset.originalText) {
                    button.dataset.originalText = button.textContent;
                }

                if (busy) {
                    button.textContent = form.dataset.loadingText || button.dataset.originalText;
                } else {
                    button.textContent = button.dataset.originalText;
                }
            }

            button.disabled = busy;
        });
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
        return `<details class="raw-payload"><summary>原始数据</summary><pre>${escapeHtml(pretty(data))}</pre></details>`;
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
                row('VIP 等级', user.vip_level ?? user.level),
                row('状态', user.vip_status ?? user.status ?? (data.active === true ? '有效' : data.active === false ? '无效' : undefined)),
                row('开始时间', user.vip_started_at ?? user.started_at),
                row('到期时间', user.vip_expired_at ?? user.expired_at ?? data.vip_expires_at),
                row('有效记录数', data.record_count),
                rawFallback(data),
            ].join('');
        },
        balance(data) {
            return [
                row('可用余额', data.available_balance ?? data.available),
                row('冻结余额', data.frozen_balance ?? data.frozen),
                row('累计收益', data.total_earned),
                row('累计提现', data.total_withdrawn),
                rawFallback(data),
            ].join('');
        },
        ledger(data) {
            const rows = listFromPayload(data, ['rows', 'list', 'ledger']);
            return renderList(rows, '暂无余额流水记录。', (item) => [
                '<article class="summary-item">',
                row('金额', item.amount),
                row('类型', item.type ?? item.direction),
                row('原因', item.reason ?? item.remark),
                row('时间', item.create_time ?? item.created_at),
                '</article>',
            ].join('')) + rawFallback(data);
        },
        invite(data) {
            const code = data.invite_code || data.default_code || data.code || {};
            return [
                row('邀请码', code.code ?? data.code),
                row('邀请链接', code.url ?? data.invite_url),
                row('一级人数', data.direct_count ?? data.level1_total ?? data.first_level_total),
                row('二级人数', data.second_level_count ?? data.level2_total ?? data.second_level_total),
                rawFallback(data),
            ].join('');
        },
        inviteRecords(data) {
            const rows = listFromPayload(data, ['rows', 'list', 'records']);
            return renderList(rows, '暂无邀请记录。', (item) => [
                '<article class="summary-item">',
                row('用户', item.email ?? item.mobile ?? item.nickname ?? item.user_id),
                row('状态', item.status),
                row('层级路径', item.level_path ?? item.level),
                row('注册时间', item.registered_at ?? item.create_time),
                '</article>',
            ].join('')) + rawFallback(data);
        },
        withdrawals(data) {
            const rows = listFromPayload(data, ['rows', 'list', 'withdrawals']);
            return renderList(rows, '暂无提现记录。', (item) => [
                '<article class="summary-item">',
                row('单号', item.withdrawal_no ?? item.id),
                row('金额', item.amount),
                row('状态', item.status),
                row('账号', item.account_no ?? item.account?.account_no ?? item.account_snapshot_json?.account_no),
                row('审核原因', item.reason),
                row('审核时间', item.audited_at ?? item.approved_at),
                row('打款方式', item.payout_method),
                row('打款流水号', item.payout_transaction_id),
                row('打款错误', item.payout_error),
                row('打款时间', item.paid_at),
                '</article>',
            ].join('')) + rawFallback(data);
        },
    };

    if (typeof window !== 'undefined') {
        window.UserPortalDashboardRenderers = {
            render(name, data) {
                return renderers[name](data);
            },
        };
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
                setStatus(status, '提交中...', null);
                setFormBusy(form, true);

                try {
                    const result = await request(form.dataset.endpoint, {
                        method: 'POST',
                        body: new FormData(form),
                    });
                    const ok = Number(result.code) === 1;
                    setStatus(status, result.msg || (ok ? '操作成功' : '操作失败'), ok);

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
                                setStatus(status, loginResult.msg || '注册成功，请手动登录。', false);
                                return;
                            }
                        }

                        window.location.href = form.dataset.successRedirect;
                    }
                } catch (error) {
                    setStatus(status, error.message, false);
                } finally {
                    setFormBusy(form, false);
                }
            });
        });
    }

    function endpointMap(element) {
        if (!element) {
            return {};
        }
        return {
            summary: element.dataset.summary,
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

    function renderSummaryBox(name, data) {
        const box = document.querySelector(`[data-dashboard-box="${name}"]`);
        if (!box) {
            return;
        }

        const rendererName = box.dataset.dashboardRender || name;
        const renderer = renderers[rendererName];
        if (renderer) {
            box.innerHTML = renderer(data || {});
            return;
        }

        box.textContent = pretty(data || {});
    }

    async function loadDashboardSummary(endpoints) {
        if (!endpoints.summary) {
            return false;
        }

        try {
            const result = await request(endpoints.summary);
            if (Number(result.code) !== 1) {
                return false;
            }

            const data = result.data || {};
            renderSummaryBox('vip', data.vip);
            renderSummaryBox('balance', data.balance);
            renderSummaryBox('ledger', data.ledger);
            renderSummaryBox('withdrawals', data.withdrawals);
            renderSummaryBox('invite', data.invite);
            renderSummaryBox('inviteRecords', data.inviteRecords);

            return true;
        } catch (error) {
            return false;
        }
    }

    async function loadBox(name, endpoint) {
        const box = document.querySelector(`[data-dashboard-box="${name}"]`);
        if (!box || !endpoint) {
            return;
        }
        box.textContent = '加载中...';
        try {
            const result = await request(endpoint);
            if (Number(result.code) !== 1) {
                box.textContent = result.msg || '请求失败。';
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
                setStatus(status, result.msg || '请先登录用户账号。', false);
                setDashboardControlsEnabled(false);
                return false;
            }

            const user = result.data?.user || {};
            const label = document.querySelector('[data-current-user-label]');
            if (label) {
                label.textContent = user.nickname || user.email || user.mobile || `用户 #${user.id}`;
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

            loadDashboardSummary(endpoints).then((loaded) => {
                if (loaded) {
                    return;
                }

                ['vip', 'balance', 'ledger', 'withdrawals', 'invite', 'inviteRecords'].forEach((name) => {
                    loadBox(name, endpoints[name]);
                });
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
                setFormBusy(activationForm, true);
                try {
                    const result = await request(endpoints.activation, {
                        method: 'POST',
                        body: new FormData(activationForm),
                    });
                    const ok = Number(result.code) === 1;
                    setStatus(status, result.msg || (ok ? '兑换成功' : '兑换失败'), ok);
                    if (ok) {
                        loadBox('vip', endpoints.vip);
                        loadBox('balance', endpoints.balance);
                    }
                } catch (error) {
                    setStatus(status, error.message, false);
                } finally {
                    setFormBusy(activationForm, false);
                }
            });
        }

        const withdrawalForm = document.querySelector('[data-dashboard-form="withdrawal"]');
        if (withdrawalForm) {
            withdrawalForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const status = withdrawalForm.querySelector('[data-form-status]');
                setFormBusy(withdrawalForm, true);
                try {
                    const result = await request(endpoints.withdrawalRequest, {
                        method: 'POST',
                        body: new FormData(withdrawalForm),
                    });
                    const ok = Number(result.code) === 1;
                    setStatus(status, result.msg || (ok ? '申请已提交' : '申请失败'), ok);
                    if (ok) {
                        loadBox('withdrawals', endpoints.withdrawals);
                        loadBox('balance', endpoints.balance);
                    }
                } catch (error) {
                    setStatus(status, error.message, false);
                } finally {
                    setFormBusy(withdrawalForm, false);
                }
            });
        }

        document.querySelector('[data-portal-logout]')?.addEventListener('click', async () => {
            const status = document.querySelector('[data-dashboard-status]');
            try {
                const result = await request(endpoints.logout, { method: 'POST', body: new FormData() });
                const ok = Number(result.code) === 1;
                setStatus(status, result.msg || (ok ? '已退出登录' : '退出失败'), ok);
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
