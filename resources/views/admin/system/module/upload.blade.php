@include('admin.layout.head')
<div class="layuimini-container">
    <div class="layuimini-main">
        <form id="app-form" class="layui-form layuimini-form">
            <div class="layui-form-item">
                <label class="layui-form-label">模块名称</label>
                <div class="layui-input-block">
                    <input type="text" name="name" class="layui-input" placeholder="可选，用于校验升级包模块名称">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label required">ZIP 文件</label>
                <div class="layui-input-block">
                    <button type="button" class="layui-btn layui-btn-normal layui-btn-sm" id="moduleZip">
                        <i class="layui-icon layui-icon-upload"></i>选择文件
                    </button>
                    <span id="moduleZipName" style="margin-left: 10px;"></span>
                </div>
            </div>
            <div class="hr-line"></div>
            <div class="layui-form-item text-center">
                <button type="button" class="layui-btn layui-btn-normal layui-btn-sm" id="moduleZipSubmit">确认上传</button>
            </div>
        </form>
    </div>
</div>
@include('admin.layout.foot')
