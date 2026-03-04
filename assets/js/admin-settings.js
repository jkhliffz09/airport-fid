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

    function csvEscape(value) {
        var text = value == null ? '' : String(value);
        if (/[",\n]/.test(text)) {
            return '"' + text.replace(/"/g, '""') + '"';
        }
        return text;
    }

    function downloadCsv(filename, content) {
        var blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function parseCsv(text) {
        var rows = [];
        var row = [];
        var value = '';
        var inQuotes = false;
        for (var i = 0; i < text.length; i++) {
            var ch = text.charAt(i);
            var next = text.charAt(i + 1);
            if (inQuotes) {
                if (ch === '"' && next === '"') {
                    value += '"';
                    i++;
                } else if (ch === '"') {
                    inQuotes = false;
                } else {
                    value += ch;
                }
            } else if (ch === '"') {
                inQuotes = true;
            } else if (ch === ',') {
                row.push(value);
                value = '';
            } else if (ch === '\n') {
                row.push(value);
                rows.push(row);
                row = [];
                value = '';
            } else if (ch !== '\r') {
                value += ch;
            }
        }
        if (value.length > 0 || row.length > 0) {
            row.push(value);
            rows.push(row);
        }
        return rows;
    }

    function csvRowsToObjects(rows) {
        if (!rows.length) return [];
        var headers = rows[0];
        var items = [];
        for (var i = 1; i < rows.length; i++) {
            if (!rows[i].length) continue;
            var obj = {};
            for (var j = 0; j < headers.length; j++) {
                obj[headers[j]] = rows[i][j] != null ? rows[i][j] : '';
            }
            items.push(obj);
        }
        return items;
    }

    function objectsToCsv(items) {
        if (!items.length) return '';
        var headers = [];
        items.forEach(function (item) {
            Object.keys(item || {}).forEach(function (key) {
                if (headers.indexOf(key) === -1) {
                    headers.push(key);
                }
            });
        });
        var lines = [];
        lines.push(headers.map(csvEscape).join(','));
        items.forEach(function (item) {
            var line = headers.map(function (key) {
                return csvEscape(item && item[key] != null ? item[key] : '');
            });
            lines.push(line.join(','));
        });
        return lines.join('\n');
    }

    document.querySelectorAll('.airport-fid-export-csv').forEach(function (button) {
        button.addEventListener('click', function () {
            var targetId = button.getAttribute('data-target');
            var textarea = targetId ? document.getElementById(targetId) : null;
            if (!textarea) return;
            try {
                var payload = JSON.parse(textarea.value || '{}');
                var flights = Array.isArray(payload.flights) ? payload.flights : [];
                var csv = objectsToCsv(flights);
                if (!csv) {
                    window.alert('No flights found in payload.');
                    return;
                }
                var airport = button.getAttribute('data-airport') || 'cache';
                var date = button.getAttribute('data-date') || '';
                var filename = airport + (date ? '-' + date : '') + '-flights.csv';
                downloadCsv(filename, csv);
            } catch (e) {
                window.alert('Invalid JSON payload. Fix JSON before export.');
            }
        });
    });

    document.querySelectorAll('.airport-fid-import-csv').forEach(function (button) {
        button.addEventListener('click', function () {
            var inputId = button.getAttribute('data-input');
            var input = inputId ? document.getElementById(inputId) : null;
            if (input) {
                input.click();
            }
        });
    });

    document.querySelectorAll('.airport-fid-import-csv-input').forEach(function (input) {
        input.addEventListener('change', function () {
            var targetId = input.getAttribute('data-target');
            var textarea = targetId ? document.getElementById(targetId) : null;
            if (!textarea || !input.files || !input.files[0]) return;
            var reader = new FileReader();
            reader.onload = function () {
                try {
                    var payload = JSON.parse(textarea.value || '{}');
                    var csvText = String(reader.result || '');
                    var rows = parseCsv(csvText);
                    var flights = csvRowsToObjects(rows);
                    payload.flights = flights;
                    textarea.value = JSON.stringify(payload, null, 2);
                    window.alert('CSV imported. Click "Update JSON" to save to database.');
                } catch (e) {
                    window.alert('Import failed. Ensure CSV and JSON payload are valid.');
                }
            };
            reader.readAsText(input.files[0]);
            input.value = '';
        });
    });
})();
