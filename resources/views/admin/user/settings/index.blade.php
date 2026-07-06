@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <fieldset class="table-search-fieldset">
            <legend>用户运营设置</legend>
            <form id="app-form" class="layui-form layuimini-form">
                <div class="layui-form-item">
                    <label class="layui-form-label">邀请次数</label>
                    <div class="layui-input-block">
                        <input type="number" min="0" name="invite_default_max_uses" class="layui-input" value="{{$settings['invite_default_max_uses']}}">
                        <tip>自动创建邀请码的默认可用次数，0 表示不限次数。</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">邀请天数</label>
                    <div class="layui-input-block">
                        <input type="number" min="0" name="invite_default_expires_days" class="layui-input" value="{{$settings['invite_default_expires_days']}}">
                        <tip>自动创建邀请码的默认有效天数，0 表示长期有效。</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">重置分钟</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="password_reset_expires_minutes" class="layui-input" value="{{$settings['password_reset_expires_minutes']}}">
                        <tip>找回密码令牌有效时长，范围 1 到 1440 分钟。</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">邀请阈值</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="risk_invite_burst_threshold" class="layui-input" value="{{$settings['risk_invite_burst_threshold']}}">
                        <tip>同一邀请来源触发集中注册风控的注册数量阈值。</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">邀请窗口</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="risk_invite_burst_window_hours" class="layui-input" value="{{$settings['risk_invite_burst_window_hours']}}">
                        <tip>集中注册风控的回看窗口，单位为小时。</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">失败阈值</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="risk_activation_failure_threshold" class="layui-input" value="{{$settings['risk_activation_failure_threshold']}}">
                        <tip>激活码失败达到该次数后提升风控等级。</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">失败窗口</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="risk_activation_failure_window_minutes" class="layui-input" value="{{$settings['risk_activation_failure_window_minutes']}}">
                        <tip>激活码失败统计窗口，单位为分钟。</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">最小提现</label>
                    <div class="layui-input-block">
                        <input type="number" min="0" step="0.01" name="withdrawal_min_amount" class="layui-input" value="{{$settings['withdrawal_min_amount']}}">
                        <tip>用户单笔提现最小金额。</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">最大提现</label>
                    <div class="layui-input-block">
                        <input type="number" min="0" step="0.01" name="withdrawal_max_amount" class="layui-input" value="{{$settings['withdrawal_max_amount']}}">
                        <tip>用户单笔提现最大金额，0 表示不限制上限。</tip>
                    </div>
                </div>

                <div class="hr-line"></div>
                <div class="layui-form-item text-center">
                    <button type="submit" class="layui-btn layui-btn-normal layui-btn-sm" lay-submit="user/settings/save" data-refresh="false">保存</button>
                    <button type="reset" class="layui-btn layui-btn-primary layui-btn-sm">重置</button>
                </div>
            </form>
        </fieldset>
    </div>
</div>
@include('admin.layout.foot')
