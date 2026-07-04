@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table id="currentTable" class="layui-table layui-hide" data-module="{{ $module }}" lay-filter="currentTable"></table>
    </div>
</div>
@include('admin.layout.foot')
