@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <fieldset class="table-search-fieldset">
            <legend>User Operations Settings</legend>
            <form id="app-form" class="layui-form layuimini-form">
                <div class="layui-form-item">
                    <label class="layui-form-label">Invite Uses</label>
                    <div class="layui-input-block">
                        <input type="number" min="0" name="invite_default_max_uses" class="layui-input" value="{{$settings['invite_default_max_uses']}}">
                        <tip>Default max uses for auto-created invite codes. Zero means unlimited.</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">Invite Days</label>
                    <div class="layui-input-block">
                        <input type="number" min="0" name="invite_default_expires_days" class="layui-input" value="{{$settings['invite_default_expires_days']}}">
                        <tip>Default invite expiry in days. Zero means no expiry.</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">Reset Minutes</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="password_reset_expires_minutes" class="layui-input" value="{{$settings['password_reset_expires_minutes']}}">
                        <tip>Password reset token validity, from 1 to 1440 minutes.</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">Invite Burst</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="risk_invite_burst_threshold" class="layui-input" value="{{$settings['risk_invite_burst_threshold']}}">
                        <tip>Registration count threshold for invite burst risk detection.</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">Burst Hours</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="risk_invite_burst_window_hours" class="layui-input" value="{{$settings['risk_invite_burst_window_hours']}}">
                        <tip>Invite burst detection lookback window in hours.</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">Fail Limit</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="risk_activation_failure_threshold" class="layui-input" value="{{$settings['risk_activation_failure_threshold']}}">
                        <tip>Activation failure count that raises risk severity.</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">Fail Minutes</label>
                    <div class="layui-input-block">
                        <input type="number" min="1" name="risk_activation_failure_window_minutes" class="layui-input" value="{{$settings['risk_activation_failure_window_minutes']}}">
                        <tip>Activation failure lookback window in minutes.</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">Min Withdraw</label>
                    <div class="layui-input-block">
                        <input type="number" min="0" step="0.01" name="withdrawal_min_amount" class="layui-input" value="{{$settings['withdrawal_min_amount']}}">
                        <tip>Minimum withdrawal amount.</tip>
                    </div>
                </div>

                <div class="layui-form-item">
                    <label class="layui-form-label">Max Withdraw</label>
                    <div class="layui-input-block">
                        <input type="number" min="0" step="0.01" name="withdrawal_max_amount" class="layui-input" value="{{$settings['withdrawal_max_amount']}}">
                        <tip>Maximum withdrawal amount. Zero means no upper limit.</tip>
                    </div>
                </div>

                <div class="hr-line"></div>
                <div class="layui-form-item text-center">
                    <button type="submit" class="layui-btn layui-btn-normal layui-btn-sm" lay-submit="user/settings/save" data-refresh="false">Save</button>
                    <button type="reset" class="layui-btn layui-btn-primary layui-btn-sm">Reset</button>
                </div>
            </form>
        </fieldset>
    </div>
</div>
@include('admin.layout.foot')
