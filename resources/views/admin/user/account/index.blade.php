@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <table id="currentTable" class="layui-table layui-hide"
               data-auth-detail="{{auths('user/account/detail')}}"
               lay-filter="currentTable">
        </table>
    </div>
</div>
@include('admin.layout.foot')
