(function () {
    var tabs = document.querySelectorAll('.airport-fid-admin-tab');
    var panels = document.querySelectorAll('.airport-fid-admin-panel');
    if (!tabs.length || !panels.length) {
        return;
    }

    function activate(tabName) {
        tabs.forEach(function (tab) {
            tab.classList.toggle('is-active', tab.dataset.tab === tabName);
        });
        panels.forEach(function (panel) {
            panel.classList.toggle('is-active', panel.dataset.panel === tabName);
        });
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            activate(tab.dataset.tab);
        });
    });
})();
