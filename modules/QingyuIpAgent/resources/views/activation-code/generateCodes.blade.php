@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <form id="app-form" class="layui-form layuimini-form">
            <div class="layui-form-item">
                <label class="layui-form-label required">批次ID</label>
                <div class="layui-input-block">
                    <input type="number" name="batch_id" class="layui-input" lay-verify="required" min="1" value="{{ $batchId ?: '' }}" placeholder="请输入批次ID">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label required">生成数量</label>
                <div class="layui-input-block">
                    <input type="number" name="count" class="layui-input" lay-verify="required" min="1" value="1">
                </div>
            </div>
            <div class="layui-form-item layui-form-text">
                <label class="layui-form-label">生成结果</label>
                <div class="layui-input-block">
                    <textarea id="generatedCodes" class="layui-textarea" readonly placeholder="提交后会在这里显示本次生成的明文激活码，请及时复制保存。"></textarea>
                </div>
            </div>
            <div class="layui-form-item text-center">
                <button type="submit" class="layui-btn layui-btn-normal layui-btn-sm" lay-submit>确认生成</button>
            </div>
        </form>
    </div>
</div>
@include('admin.layout.foot')
