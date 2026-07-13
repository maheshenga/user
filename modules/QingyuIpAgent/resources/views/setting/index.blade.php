@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <form class="layui-form" method="post" action="{{ __url('qingyu_ip_agent/setting/save') }}">
            @csrf
            <div class="layui-form-item">
                <label class="layui-form-label">默认套餐 ID</label>
                <div class="layui-input-block">
                    <input type="number" name="default_vip_plan_id" value="{{ $row['default_vip_plan_id'] ?? '' }}" class="layui-input">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">批次前缀</label>
                <div class="layui-input-block">
                    <input type="text" name="activation_batch_prefix" value="{{ $row['activation_batch_prefix'] ?? '' }}" class="layui-input">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">备注</label>
                <div class="layui-input-block">
                    <textarea name="module_enabled_note" class="layui-textarea">{{ $row['module_enabled_note'] ?? '' }}</textarea>
                </div>
            </div>
            <div class="layui-form-item">
                <div class="layui-input-block">
                    <button class="layui-btn" lay-submit>保存</button>
                </div>
            </div>
        </form>
    </div>
</div>
@include('admin.layout.foot')
