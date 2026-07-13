@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <div class="layui-row layui-col-space15">
            <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">会员数</div><div class="layui-card-body">{{ $summary['member_count'] ?? 0 }}</div></div></div>
            <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">VIP 记录</div><div class="layui-card-body">{{ $summary['vip_record_count'] ?? 0 }}</div></div></div>
            <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">激活码批次</div><div class="layui-card-body">{{ $summary['activation_batch_count'] ?? 0 }}</div></div></div>
            <div class="layui-col-md3"><div class="layui-card"><div class="layui-card-header">兑换记录</div><div class="layui-card-body">{{ $summary['redemption_count'] ?? 0 }}</div></div></div>
        </div>
    </div>
</div>
@include('admin.layout.foot')
