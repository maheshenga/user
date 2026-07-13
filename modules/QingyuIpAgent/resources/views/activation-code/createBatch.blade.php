@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <form id="app-form" class="layui-form layuimini-form">
            <div class="layui-form-item">
                <label class="layui-form-label required">批次名称</label>
                <div class="layui-input-block">
                    <input type="text" name="name" class="layui-input" lay-verify="required" placeholder="请输入批次名称">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label required">VIP套餐ID</label>
                <div class="layui-input-block">
                    <input type="number" name="vip_plan_id" class="layui-input" lay-verify="required" min="1" placeholder="请输入VIP套餐ID">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label required">总数量</label>
                <div class="layui-input-block">
                    <input type="number" name="total_count" class="layui-input" lay-verify="required" min="1" value="100">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">有效天数</label>
                <div class="layui-input-block">
                    <input type="number" name="duration_days" class="layui-input" min="0" value="30">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">批次状态</label>
                <div class="layui-input-block">
                    <select name="status">
                        <option value="active">启用</option>
                        <option value="draft">草稿</option>
                    </select>
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">参与分销</label>
                <div class="layui-input-block">
                    <input type="checkbox" name="is_commissionable" value="1" lay-skin="switch" lay-text="是|否">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">一级奖励</label>
                <div class="layui-input-block">
                    <input type="number" name="first_level_reward" class="layui-input" min="0" step="0.01" value="0">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">二级奖励</label>
                <div class="layui-input-block">
                    <input type="number" name="second_level_reward" class="layui-input" min="0" step="0.01" value="0">
                </div>
            </div>
            <div class="layui-form-item text-center">
                <button type="submit" class="layui-btn layui-btn-normal layui-btn-sm" lay-submit>确认</button>
            </div>
        </form>
    </div>
</div>
@include('admin.layout.foot')
