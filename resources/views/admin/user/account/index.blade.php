@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <div class="layui-card" data-user-status-admin
             data-status-endpoint="{{ __url('user/account/modify') }}"
             data-status-values="pending,active,disabled,frozen">
            <div class="layui-card-header">账号状态管理</div>
            <div class="layui-card-body">
                <span class="layui-badge layui-bg-gray">待审核 pending</span>
                <span class="layui-badge layui-bg-green">正常 active</span>
                <span class="layui-badge">已禁用 disabled</span>
                <span class="layui-badge layui-bg-orange">已冻结 frozen</span>
                <p class="layui-font-12" style="margin-top: 8px;">
                    仅支持通过列表操作修改账号状态；其他账号资料保持只读。
                </p>
            </div>
        </div>
        <table id="currentTable" class="layui-table layui-hide"
               data-auth-detail="{{auths('user/account/detail')}}"
               data-auth-modify="{{auths('user/account/modify')}}"
               lay-filter="currentTable">
        </table>
        <script type="text/html" id="userStatusTpl">
            @{{#  if(d.status === 'active'){ }}
            <span class="layui-badge layui-bg-green">正常</span>
            @{{#  } else if(d.status === 'disabled'){ }}
            <span class="layui-badge">已禁用</span>
            @{{#  } else if(d.status === 'frozen'){ }}
            <span class="layui-badge layui-bg-orange">已冻结</span>
            @{{#  } else { }}
            <span class="layui-badge layui-bg-gray">待审核</span>
            @{{#  } }}
        </script>
    </div>
</div>
@include('admin.layout.foot')
