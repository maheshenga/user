define(["jquery", "easy-admin"], function ($, ea) {
    var init = {
        stats_url: 'user/dashboard/index'
    };

    function setMetric(key, value) {
        $('[data-metric="' + key + '"]').text(value);
    }

    return {
        index: function () {
            ea.request.get({
                url: ea.url(init.stats_url)
            }, function (response) {
                var data = response.data || {};
                Object.keys(data).forEach(function (key) {
                    setMetric(key, data[key]);
                });
            });

            $('body').on('click', '[data-open-tab]', function () {
                var href = $(this).data('open-tab');
                parent.layui.element.tabAdd('layuiminiTab', {
                    title: $(this).closest('tr').find('td:first').text(),
                    content: '<iframe width="100%" height="100%" frameborder="0" src="' + ea.url(href) + '"></iframe>',
                    id: href
                });
                parent.layui.element.tabChange('layuiminiTab', href);
            });
        }
    };
});
