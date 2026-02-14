(function () {
    if (typeof AirportFID === 'undefined') {
        return;
    }

    function fetchJson(url) {
        return fetch(url, {
            credentials: 'same-origin',
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed');
            }
            return response.json();
        });
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': (AirportFID && AirportFID.nonce) || '',
            },
            body: JSON.stringify(body || {}),
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('Request failed');
            }
            return response.json();
        });
    }

    function clearNode(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function renderTable(wrapper, flights, showDestination, lastMap) {
        clearNode(wrapper);

        if (!flights.length) {
            var empty = document.createElement('div');
            empty.className = 'airport-fid-empty';
            empty.textContent = 'No flights found.';
            wrapper.appendChild(empty);
            return;
        }

        var table = document.createElement('table');
        table.className = 'airport-fid-table';

        var thead = document.createElement('thead');
        var headerRow = document.createElement('tr');

        var isCompact = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
        var headers = isCompact ? [''] : ['', 'Airline', 'Airport', ''];

        headers.forEach(function (label) {
            var th = document.createElement('th');
            th.textContent = label;
            headerRow.appendChild(th);
        });

        thead.appendChild(headerRow);
        table.appendChild(thead);

        var tbody = document.createElement('tbody');

        function createFlipSpan(text, className, animate, columnIndex) {
            var span = document.createElement('span');
            span.className = 'airport-fid-flip';
            if (className) {
                span.classList.add(className);
            }
            if (animate) {
                span.classList.add('is-animate');
            }
            span.style.setProperty('--fid-col', columnIndex || 0);

            var content = text || '';
            if (!content) {
                span.textContent = '';
                return span;
            }

            for (var i = 0; i < content.length; i++) {
                var ch = content.charAt(i);
                var letter = document.createElement('span');
                letter.className = 'airport-fid-letter';
                letter.style.setProperty('--fid-letter', i);
                if (ch === ' ') {
                    letter.classList.add('space');
                    letter.textContent = '';
                } else if (animate) {
                    letter.textContent = 'X';
                } else {
                    letter.textContent = ch;
                }
                if (animate && ch.trim()) {
                    scheduleScramble(letter, ch, columnIndex || 0, i);
                }
                span.appendChild(letter);
            }

            return span;
        }

        function scheduleScramble(letterEl, finalChar, columnIndex, letterIndex) {
            var baseDelay = columnIndex * 200 + letterIndex * 40;
            var ticks = 8 + Math.floor(Math.random() * 6);
            var interval = 80;
            var charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

            setTimeout(function () {
                letterEl.textContent = 'X';
                var count = 0;
                var timer = setInterval(function () {
                    count += 1;
                    letterEl.classList.remove('is-hidden');
                    letterEl.classList.add('is-flipping');
                    letterEl.textContent = charset.charAt(Math.floor(Math.random() * charset.length));
                    void letterEl.offsetWidth;
                    letterEl.classList.remove('is-flipping');

                    if (count >= ticks) {
                        clearInterval(timer);
                        letterEl.classList.add('is-flipping');
                        letterEl.textContent = finalChar;
                        void letterEl.offsetWidth;
                        letterEl.classList.remove('is-flipping');
                    }
                }, interval);
            }, baseDelay);
        }

        var nextMap = {};

        function formatTime12h(timeValue) {
            if (!timeValue) return '';
            var parts = String(timeValue).trim().split(':');
            if (parts.length < 2) return timeValue;
            var hours = parseInt(parts[0], 10);
            var minutes = parts[1];
            if (isNaN(hours)) return timeValue;
            var suffix = hours >= 12 ? 'PM' : 'AM';
            var displayHour = hours % 12;
            if (displayHour === 0) displayHour = 12;
            return displayHour + ':' + minutes + ' ' + suffix;
        }

        flights.forEach(function (flight) {
            var row = document.createElement('tr');
            row.className = 'airport-fid-row';

            var key =
                (flight.flight_number || '') +
                '|' +
                (flight.departure_time || '') +
                '|' +
                (flight.destination || '');

            var departureCell = document.createElement('td');
            var departureText = formatTime12h(flight.departure_time || '');
            var previous = lastMap && lastMap[key] ? lastMap[key] : null;
            var animateDeparture = !previous || previous.departure_time !== departureText;
            var arrivalText = formatTime12h(flight.arrival_time || '');
            var animateArrival = !previous || previous.arrival_time !== arrivalText;
            var rangeLine = document.createElement('div');
            rangeLine.className = 'airport-fid-time-range';
            rangeLine.appendChild(createFlipSpan(departureText, '', animateDeparture, 0));
            var sep = document.createElement('span');
            sep.className = 'airport-fid-time-sep';
            sep.textContent = ' - ';
            rangeLine.appendChild(sep);
            rangeLine.appendChild(createFlipSpan(arrivalText, 'airport-fid-flip-muted', animateArrival, 0));
            departureCell.appendChild(rangeLine);
            if (!isCompact) {
                departureCell.appendChild(createFlipSpan(departureText, '', animateDeparture, 0));
                var arrivalLine = document.createElement('div');
                arrivalLine.className = 'airport-fid-arrival-line';
                arrivalLine.appendChild(createFlipSpan(arrivalText, 'airport-fid-flip-muted', animateArrival, 0));
                departureCell.appendChild(arrivalLine);
            } else {
                departureCell.className = 'airport-fid-stack';
            }

            var logoCell = document.createElement('td');
            logoCell.className = 'airport-fid-logo-cell';
            if (flight.airline_code) {
                var img = document.createElement('img');
                img.className = 'airport-fid-logo';
                img.alt = flight.airline || flight.airline_code;
                img.src =
                    'https://img.wway.io/pics/root/' +
                    encodeURIComponent(flight.airline_code) +
                    '@png?exar=1&rs=fit:400:100';
                img.addEventListener('error', function () {
                    logoCell.textContent = flight.airline || flight.airline_code;
                    logoCell.classList.add('airport-fid-logo-fallback');
                });
                var airlineWrap = document.createElement('div');
                airlineWrap.className = 'airport-fid-airline';
                airlineWrap.appendChild(img);
                var airlineName = document.createElement('span');
                airlineName.className = 'airport-fid-airline-name';
                var airlineText = flight.airline || flight.airline_code || '';
                var animateAirline = !previous || previous.airline !== airlineText;
                airlineName.appendChild(createFlipSpan(airlineText, '', animateAirline, 1));
                if (!isCompact) {
                    airlineWrap.appendChild(airlineName);
                }
                logoCell.appendChild(airlineWrap);
            } else {
                logoCell.textContent = flight.airline || '';
            }

            var airportCell = document.createElement('td');
            var airportName = shortenAirportName(flight.destination_name || '');
            var airportCode = flight.destination || '';
            if (!airportName && flight.origin_name && showDestination === false) {
                airportName = shortenAirportName(flight.origin_name);
                airportCode = flight.origin_code || airportCode;
            }
            var airportLabel = airportName && airportCode ? airportName + ' (' + airportCode + ')' : airportCode;
            var flightLabel = flight.flight_number || '';
            if (flight.equipment) {
                flightLabel = flightLabel + ' (' + flight.equipment + ')';
            }
            if (flight.duration_label) {
                flightLabel = flightLabel + ' • ' + String(flight.duration_label).toUpperCase();
            }
            var airportLine = document.createElement('div');
            airportLine.className = 'airport-fid-airport';
            var animateAirport = !previous || previous.airport_label !== airportLabel;
            airportLine.appendChild(createFlipSpan(airportLabel, '', animateAirport, 2));
            var flightLine = document.createElement('div');
            flightLine.className = 'airport-fid-flight';
            var animateFlight = !previous || previous.flight_label !== flightLabel;
            flightLine.appendChild(createFlipSpan(flightLabel, 'airport-fid-flip-muted', animateFlight, 2));
            airportCell.appendChild(airportLine);
            airportCell.appendChild(flightLine);
            var toggleCell = document.createElement('td');
            var toggleButton = document.createElement('button');
            toggleButton.type = 'button';
            toggleButton.className = 'airport-fid-toggle';
            toggleButton.setAttribute('aria-expanded', 'false');
            toggleButton.setAttribute('aria-label', 'Toggle details');
            toggleButton.innerHTML =
                '<svg class="airport-fid-chevron" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
                '<path d="M5 7.5l5 5 5-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>' +
                '</svg>';
            toggleCell.appendChild(toggleButton);
            if (isCompact) {
                var stackAirline = document.createElement('div');
                stackAirline.className = 'airport-fid-stack-airline';
                stackAirline.appendChild(logoCell);
                if (airlineName) {
                    stackAirline.appendChild(airlineName);
                }
                toggleButton.innerHTML =
                    '<span class="airport-fid-more-text">See more details</span>' +
                    '<svg class="airport-fid-chevron" viewBox="0 0 20 20" aria-hidden="true" focusable="false">' +
                    '<path d="M5 7.5l5 5 5-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>' +
                    '</svg>';
                departureCell.appendChild(stackAirline);
                departureCell.appendChild(airportCell);
                departureCell.appendChild(toggleCell);
                row.appendChild(departureCell);
            } else {
                row.appendChild(departureCell);
                row.appendChild(logoCell);
                row.appendChild(airportCell);
                row.appendChild(toggleCell);
            }

            tbody.appendChild(row);

            var detailRow = document.createElement('tr');
            detailRow.className = 'airport-fid-details-row';
            var detailCell = document.createElement('td');
            detailCell.colSpan = headers.length;

            var details = document.createElement('div');
            details.className = 'airport-fid-details';

            function addDetail(label, value) {
                var item = document.createElement('div');
                var title = document.createElement('span');
                title.className = 'airport-fid-detail-label';
                title.textContent = label;
                item.appendChild(title);
                item.appendChild(document.createTextNode(value || ''));
                details.appendChild(item);
            }

            var originLabel =
                (flight.origin_name || '') && flight.origin_code
                    ? flight.origin_code + ' - ' + flight.origin_name
                    : flight.origin_code || '';
            var destinationLabel =
                (flight.destination_name || '') && flight.destination
                    ? flight.destination + ' - ' + flight.destination_name
                    : flight.destination || '';

            var equipmentLabel = flight.equipment_name
                ? flight.equipment_name + ' (' + (flight.equipment || '') + ')'
                : flight.equipment || '';

            var departureDateLabel = '';
            if (flight.departure_date) {
                departureDateLabel = flight.departure_date;
            }
            var arrivalDateLabel = '';
            if (flight.arrival_date) {
                arrivalDateLabel = flight.arrival_date;
            }

            function formatTime12h(timeValue) {
                if (!timeValue) return '';
                var parts = String(timeValue).trim().split(':');
                if (parts.length < 2) return timeValue;
                var hours = parseInt(parts[0], 10);
                var minutes = parts[1];
                if (isNaN(hours)) return timeValue;
                var suffix = hours >= 12 ? 'PM' : 'AM';
                var displayHour = hours % 12;
                if (displayHour === 0) displayHour = 12;
                return displayHour + ':' + minutes + ' ' + suffix;
            }

            var timeline = document.createElement('div');
            timeline.className = 'airport-fid-timeline';

            var left = document.createElement('div');
            left.className = 'airport-fid-timeline-side';
            var leftDate = document.createElement('div');
            leftDate.className = 'airport-fid-timeline-date';
            leftDate.textContent = departureDateLabel || '';
            var leftTime = document.createElement('div');
            leftTime.className = 'airport-fid-timeline-time';
            leftTime.textContent = formatTime12h(flight.departure_time || '');
            var leftAirport = document.createElement('div');
            leftAirport.className = 'airport-fid-timeline-airport';
            leftAirport.textContent = originLabel;

            left.appendChild(leftDate);
            left.appendChild(leftTime);
            left.appendChild(leftAirport);

            var right = document.createElement('div');
            right.className = 'airport-fid-timeline-side airport-fid-timeline-right';
            var rightDate = document.createElement('div');
            rightDate.className = 'airport-fid-timeline-date';
            rightDate.textContent = arrivalDateLabel || departureDateLabel || '';
            var rightTime = document.createElement('div');
            rightTime.className = 'airport-fid-timeline-time';
            rightTime.textContent = formatTime12h(flight.arrival_time || '');
            var rightDay = document.createElement('div');
            rightDay.className = 'airport-fid-timeline-day';
            var rightAirport = document.createElement('div');
            rightAirport.className = 'airport-fid-timeline-airport';
            rightAirport.textContent = destinationLabel;
            var dayLabel = '';
            var indicatorValue = String(flight.day_indicator || '').trim();
            if (indicatorValue) {
                var dayMatch = indicatorValue.match(/[-+]?\d+/);
                if (dayMatch) {
                    var dayValue = parseInt(dayMatch[0], 10);
                    if (!isNaN(dayValue) && dayValue > 0) {
                        dayLabel = dayValue === 1 ? 'NEXT DAY' : 'NEXT ' + dayValue + ' DAYS';
                    }
                }
            }
            right.appendChild(rightDate);
            right.appendChild(rightTime);
            rightDay.textContent = dayLabel || ' ';
            right.appendChild(rightDay);
            right.appendChild(rightAirport);

            var line = document.createElement('div');
            line.className = 'airport-fid-timeline-line';
            line.innerHTML =
                '<span class=\"airport-fid-timeline-dot\"></span>' +
                '<span class=\"airport-fid-timeline-dash\"></span>' +
                '<span class=\"airport-fid-timeline-plane\">✈</span>' +
                '<span class=\"airport-fid-timeline-dash\"></span>' +
                '<span class=\"airport-fid-timeline-dot\"></span>';

            timeline.appendChild(left);
            timeline.appendChild(line);
            timeline.appendChild(right);
            details.appendChild(timeline);

            addDetail('Duration', flight.duration_label || '');
            addDetail('Equipment', equipmentLabel);
            addDetail('Terminal', flight.terminal || '');

            detailCell.appendChild(details);
            detailRow.appendChild(detailCell);
            tbody.appendChild(detailRow);

            toggleButton.addEventListener('click', function () {
                var expanded = toggleButton.getAttribute('aria-expanded') === 'true';
                if (!expanded) {
                    var openRows = tbody.querySelectorAll('.airport-fid-details-row.is-open');
                    openRows.forEach(function (openRow) {
                        openRow.classList.remove('is-open');
                    });
                    var openButtons = tbody.querySelectorAll('.airport-fid-toggle[aria-expanded="true"]');
                    openButtons.forEach(function (openButton) {
                        openButton.setAttribute('aria-expanded', 'false');
                    });
                    var openParents = tbody.querySelectorAll('.airport-fid-row.is-open');
                    openParents.forEach(function (openParent) {
                        openParent.classList.remove('is-open');
                    });
                }
                toggleButton.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                detailRow.classList.toggle('is-open', !expanded);
                row.classList.toggle('is-open', !expanded);
            });

            row.addEventListener('click', function (event) {
                var target = event.target;
                if (
                    target.closest('button') ||
                    target.closest('a') ||
                    target.closest('input') ||
                    target.closest('select') ||
                    target.closest('textarea')
                ) {
                    return;
                }
                toggleButton.click();
            });
        });

        table.appendChild(tbody);
        wrapper.appendChild(table);

        flights.forEach(function (flight) {
            var key =
                (flight.flight_number || '') +
                '|' +
                (flight.departure_time || '') +
                '|' +
                (flight.destination || '');
            var airportName = shortenAirportName(flight.destination_name || '');
            var airportCode = flight.destination || '';
            if (!airportName && flight.origin_name && showDestination === false) {
                airportName = shortenAirportName(flight.origin_name);
                airportCode = flight.origin_code || airportCode;
            }
            var airportLabel = airportName && airportCode ? airportName + ' (' + airportCode + ')' : airportCode;
            var flightLabel = flight.flight_number || '';
            if (flight.equipment) {
                flightLabel = flightLabel + ' (' + flight.equipment + ')';
            }
            if (flight.duration_label) {
                flightLabel = flightLabel + ' • ' + String(flight.duration_label).toUpperCase();
            }
            var originLabel =
                (flight.origin_name || '') && flight.origin_code
                    ? flight.origin_name + ' (' + flight.origin_code + ')'
                    : flight.origin_code || '';
            var destinationLabel =
                (flight.destination_name || '') && flight.destination
                    ? flight.destination_name + ' (' + flight.destination + ')'
                    : flight.destination || '';
            nextMap[key] = {
                departure_time: flight.departure_time || '',
                arrival_time: flight.arrival_time || '',
                airline: flight.airline || flight.airline_code || '',
                airport_label: airportLabel,
                flight_label: flightLabel,
                status: flight.status || '',
                details: {
                    Airline: flight.airline || flight.airline_code || '',
                    Arrival: flight.arrival_time || '',
                    Terminal: flight.terminal || '',
                    Origin: originLabel,
                    Destination: destinationLabel,
                },
                departure_date: flight.departure_date || '',
                duration_label: flight.duration_label || '',
            };
        });

        return nextMap;
    }

    function getLocalDateString() {
        var now = new Date();
        var year = now.getFullYear();
        var month = String(now.getMonth() + 1).padStart(2, '0');
        var day = String(now.getDate()).padStart(2, '0');
        return '' + year + month + day;
    }

    function shortenAirportName(name) {
        var cleaned = (name || '').trim();
        cleaned = cleaned.replace(/\s+International Airport$/i, '');
        cleaned = cleaned.replace(/\s+International$/i, '');
        cleaned = cleaned.replace(/\s+Airport$/i, '');
        return cleaned;
    }

    function toInputDate(value) {
        if (!value || value.length !== 8) {
            return '';
        }
        return value.slice(0, 4) + '-' + value.slice(4, 6) + '-' + value.slice(6, 8);
    }

    function fromInputDate(value) {
        if (!value) {
            return '';
        }
        return value.replace(/-/g, '');
    }

    function initBoard(board) {
        var useGeo = board.dataset.useGeolocation === '1';
        var showDestination = board.dataset.showDestination === '1';
        var defaultAirport = (board.dataset.airport || AirportFID.defaultAirport || '').toUpperCase();
        var pageSize = 5;

        var statusEl = board.querySelector('.airport-fid-status');
        var wrapper = board.querySelector('.airport-fid-table-wrapper');
        var input = board.querySelector('.airport-fid-input');
        var button = board.querySelector('.airport-fid-load');
        var geoButton = board.querySelector('.airport-fid-geo-inline');
        var suggestBox = board.querySelector('.airport-fid-suggest');
        var dateInput = board.querySelector('.airport-fid-date');
        var dateButton = board.querySelector('.airport-fid-date-button');
        var sortSelect = board.querySelector('.airport-fid-sort');
        var orderSelect = board.querySelector('.airport-fid-order');
        var loadMoreButton = board.querySelector('.airport-fid-load-more');
        var pageNotice = board.querySelector('.airport-fid-page-notice');
        var loadingOverlay = board.querySelector('.airport-fid-loading');
        var themeToggle = board.querySelector('.airport-fid-theme-toggle');

        var allFlights = [];
        var visibleCount = 0;
        var lastFlightMap = {};
        var suggestTimer = null;
        var lastQuery = '';
        var pendingRequests = 0;
        var activeToken = 0;
        var isFetchingMore = false;
        var currentLabel = '';
        var labelIsCode = false;

        function updateStatus(message) {
            if (statusEl) {
                statusEl.textContent = message;
            }
        }

        function setTheme(mode) {
            if (!board) {
                return;
            }
            var isLight = mode === 'light';
            board.classList.toggle('is-light', isLight);
            board.classList.toggle('is-dark', !isLight);
            if (themeToggle) {
                themeToggle.textContent = isLight ? 'Dark mode' : 'Light mode';
                themeToggle.setAttribute('aria-pressed', isLight ? 'true' : 'false');
            }
            try {
                window.localStorage.setItem('airport_fid_theme', isLight ? 'light' : 'dark');
            } catch (e) {
                // no-op
            }
        }

        function initTheme() {
            var stored = null;
            try {
                stored = window.localStorage.getItem('airport_fid_theme');
            } catch (e) {
                stored = null;
            }
            if (stored === 'light' || stored === 'dark') {
                setTheme(stored);
                return;
            }
            setTheme('dark');
        }

        function showLoading() {
            if (loadingOverlay) {
                loadingOverlay.classList.add('is-active');
                loadingOverlay.setAttribute('aria-hidden', 'false');
            }
        }

        function hideLoading() {
            if (loadingOverlay) {
                loadingOverlay.classList.remove('is-active');
                loadingOverlay.setAttribute('aria-hidden', 'true');
            }
        }

        function clearSuggestions() {
            if (!suggestBox) {
                return;
            }
            suggestBox.innerHTML = '';
            suggestBox.classList.remove('is-open');
        }

        function renderSuggestions(items) {
            if (!suggestBox) {
                return;
            }
            suggestBox.innerHTML = '';
            if (!items.length) {
                suggestBox.classList.remove('is-open');
                return;
            }

            items.forEach(function (item) {
                var buttonEl = document.createElement('button');
                buttonEl.type = 'button';
                buttonEl.className = 'airport-fid-suggest-item';
                buttonEl.textContent = item.name + ' (' + item.code + ')';
                buttonEl.addEventListener('click', function () {
                    if (input) {
                        input.value = item.code;
                    }
                    clearSuggestions();
                    loadBoard(item.code);
                });
                suggestBox.appendChild(buttonEl);
            });
            suggestBox.classList.add('is-open');
        }

        function fetchSuggestions(query) {
            if (!query || query.length < 3) {
                clearSuggestions();
                return;
            }
            if (query === lastQuery) {
                return;
            }
            lastQuery = query;
            var url = AirportFID.restUrl + '/airports?query=' + encodeURIComponent(query);
            fetchJson(url)
                .then(function (items) {
                    renderSuggestions(items || []);
                })
                .catch(function () {
                    clearSuggestions();
                });
        }

        function updatePagination() {
            if (!loadMoreButton || !pageNotice) {
                return;
            }
            if (!allFlights.length) {
                loadMoreButton.style.display = 'none';
                pageNotice.textContent = isFetchingMore ? 'Fetching more results...' : '';
                return;
            }

            if (visibleCount >= allFlights.length) {
                loadMoreButton.style.display = 'none';
                if (isFetchingMore) {
                    pageNotice.textContent = 'Fetching more results...';
                } else {
                    pageNotice.textContent = 'No more results.';
                }
            } else {
                loadMoreButton.style.display = 'inline-flex';
                pageNotice.textContent = isFetchingMore ? 'Fetching more results...' : '';
            }
        }

        function renderPage() {
            var slice = allFlights.slice(0, visibleCount);
            lastFlightMap = renderTable(wrapper, slice, showDestination, lastFlightMap);
            updatePagination();
        }

        function normalizeAirport(value) {
            return (value || '').trim().toUpperCase();
        }

        function sortFlights(list, mode, order) {
            var key = mode || 'departure_time';
            var direction = order === 'desc' ? -1 : 1;
            list.sort(function (a, b) {
                function compareNumber(aVal, bVal) {
                    if (aVal === bVal) {
                        return 0;
                    }
                    return aVal < bVal ? -1 : 1;
                }

                function compareString(aVal, bVal) {
                    if (aVal === bVal) {
                        return 0;
                    }
                    return aVal < bVal ? -1 : 1;
                }

                var aDep = a.departure_ts || 0;
                var bDep = b.departure_ts || 0;
                var aArr = a.arrival_ts || 0;
                var bArr = b.arrival_ts || 0;
                var aDur = a.duration_minutes || 0;
                var bDur = b.duration_minutes || 0;
                var aAirport = (a.destination_name || a.destination || '').toUpperCase();
                var bAirport = (b.destination_name || b.destination || '').toUpperCase();
                var aAirline = (a.airline || a.airline_code || '').toUpperCase();
                var bAirline = (b.airline || b.airline_code || '').toUpperCase();

                if (key === 'airline') {
                    return (
                        compareString(aAirline, bAirline) ||
                        compareNumber(aDep, bDep) ||
                        compareString(aAirport, bAirport)
                    ) * direction;
                }
                if (key === 'airport') {
                    return (
                        compareString(aAirport, bAirport) ||
                        compareNumber(aDep, bDep) ||
                        compareString(aAirline, bAirline)
                    ) * direction;
                }
                if (key === 'arrival_time') {
                    return (
                        compareNumber(aArr, bArr) ||
                        compareString(aAirport, bAirport) ||
                        compareString(aAirline, bAirline)
                    ) * direction;
                }
                if (key === 'departure_time') {
                    return (
                        compareNumber(aDep, bDep) ||
                        compareString(aAirport, bAirport) ||
                        compareString(aAirline, bAirline)
                    ) * direction;
                }
                if (key === 'duration') {
                    return (
                        compareNumber(aDur, bDur) ||
                        compareString(aAirport, bAirport) ||
                        compareString(aAirline, bAirline)
                    ) * direction;
                }

                return compareNumber(aDep, bDep) * direction;
            });
        }

        function fetchTimetableBatch(iata, destinations, dateValue, sortValue, token) {
            var concurrency = 6;
            var index = 0;
            pendingRequests = 0;

            function scheduleNext() {
                if (token !== activeToken) {
                    return;
                }
                while (pendingRequests < concurrency && index < destinations.length) {
                    var destination = destinations[index++];
                    pendingRequests++;
                    var url =
                        AirportFID.restUrl +
                        '/timetable?airport=' +
                        encodeURIComponent(iata) +
                        '&destination=' +
                        encodeURIComponent(destination) +
                        '&date=' +
                        encodeURIComponent(dateValue || getLocalDateString());
                    fetchJson(url)
                        .then(function (data) {
                            if (token !== activeToken) {
                                return;
                            }
                            var flights = (data && data.flights) || [];
                            if (flights.length) {
                                allFlights = allFlights.concat(flights);
                                if (labelIsCode) {
                                    var first = flights[0];
                                    if (first && first.origin_name) {
                                        currentLabel = first.origin_name;
                                        labelIsCode = false;
                                        updateStatus('Showing flights for ' + currentLabel + '.');
                                    }
                                }
                                sortFlights(allFlights, sortValue || 'departure_time', orderSelect && orderSelect.value);
                                visibleCount = Math.min(Math.max(visibleCount, pageSize), allFlights.length);
                                renderPage();
                            }
                            hideLoading();
                        })
                        .catch(function () {
                            // ignore per-destination failures
                        })
                        .finally(function () {
                            pendingRequests--;
                            if (token !== activeToken) {
                                return;
                            }
                            if (index >= destinations.length && pendingRequests === 0) {
                                isFetchingMore = false;
                                updateStatus('Showing flights for ' + currentLabel + '.');
                                updatePagination();
                                postJson(AirportFID.restUrl + '/cache', {
                                    airport: iata,
                                    date: dateValue || getLocalDateString(),
                                    sort: sortValue || 'departure_time',
                                    airport_name: currentLabel,
                                    flights: allFlights,
                                }).catch(function () {
                                    // ignore cache save errors
                                });
                                return;
                            }
                            isFetchingMore = true;
                            updatePagination();
                            scheduleNext();
                        });
                }
            }

            scheduleNext();
        }

        function loadBoard(airport) {
            var cleaned = normalizeAirport(airport);
            var match = cleaned.match(/[A-Z]{3}$/);
            var iata = match ? match[0] : cleaned;
            if (!iata || iata.length !== 3) {
                updateStatus('Enter a valid 3-letter IATA code.');
                return;
            }

            updateStatus('Loading flights for ' + iata + '...');
            showLoading();
            activeToken += 1;
            var token = activeToken;
            allFlights = [];
            visibleCount = 0;
            lastFlightMap = {};
            isFetchingMore = false;
            pendingRequests = 0;
            currentLabel = iata;
            labelIsCode = true;
            renderPage();

            var dateValue = dateInput ? fromInputDate(dateInput.value) : '';
            var sortValue = sortSelect && sortSelect.value ? sortSelect.value : 'departure_time';
            var sortOrder = orderSelect && orderSelect.value ? orderSelect.value : 'asc';

            var cacheUrl =
                AirportFID.restUrl +
                '/cache?airport=' +
                encodeURIComponent(iata) +
                '&date=' +
                encodeURIComponent(dateValue || getLocalDateString()) +
                '&sort=' +
                encodeURIComponent(sortValue);

            fetchJson(cacheUrl)
                .then(function (cacheData) {
                    if (token !== activeToken) {
                        return { skipRefresh: true };
                    }
                    if (cacheData && cacheData.cached && cacheData.flights && cacheData.flights.length) {
                        currentLabel = cacheData.airport_name || iata;
                        labelIsCode = currentLabel.length === 3;
                        allFlights = cacheData.flights;
                        sortFlights(allFlights, sortValue, sortOrder);
                        visibleCount = Math.min(pageSize, allFlights.length);
                        renderPage();
                        if (!cacheData.stale) {
                            updateStatus('Showing flights for ' + currentLabel + '.');
                            hideLoading();
                            return { skipRefresh: true };
                        }
                        updateStatus('Fetching latest flights for ' + currentLabel + '...');
                    }
                    return { skipRefresh: false };
                })
                .catch(function () {
                    return { skipRefresh: false };
                })
                .then(function (state) {
                    if (!state || state.skipRefresh || token !== activeToken) {
                        return;
                    }

                    var url = AirportFID.restUrl + '/routes?airport=' + encodeURIComponent(iata);
                    fetchJson(url)
                        .then(function (data) {
                            if (token !== activeToken) {
                                return;
                            }
                            currentLabel = data.airport_name || data.airport || iata;
                            labelIsCode = currentLabel.length === 3;
                            updateStatus('Fetching flights for ' + currentLabel + '...');
                            var destinations = (data && data.destinations) || [];
                            if (!destinations.length) {
                                updateStatus('No destinations found for ' + currentLabel + '.');
                                hideLoading();
                                return;
                            }
                            isFetchingMore = true;
                            updatePagination();
                            fetchTimetableBatch(iata, destinations, dateValue, sortValue, token);
                        })
                        .catch(function () {
                            updateStatus('Unable to load flight data.');
                            hideLoading();
                        });
                });
        }

        if (input) {
            input.value = defaultAirport;
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    loadBoard(input.value);
                }
            });
            input.addEventListener('input', function () {
                var value = input.value.trim();
                if (suggestTimer) {
                    clearTimeout(suggestTimer);
                }
                suggestTimer = setTimeout(function () {
                    fetchSuggestions(value);
                }, 200);
            });
            input.addEventListener('blur', function () {
                setTimeout(clearSuggestions, 150);
            });
        }

        if (button) {
            button.addEventListener('click', function () {
                if (input) {
                    loadBoard(input.value);
                }
            });
        }

        if (dateInput) {
            dateInput.value = toInputDate(getLocalDateString());
            dateInput.addEventListener('change', function () {
                loadBoard(input ? input.value : defaultAirport);
            });
        }

        if (sortSelect) {
            sortSelect.addEventListener('change', function () {
                if (!allFlights.length) {
                    return;
                }
                sortFlights(allFlights, sortSelect.value, orderSelect && orderSelect.value);
                renderPage();
            });
        }

        if (orderSelect) {
            orderSelect.addEventListener('change', function () {
                if (!allFlights.length) {
                    return;
                }
                sortFlights(allFlights, sortSelect ? sortSelect.value : 'departure_time', orderSelect.value);
                renderPage();
            });
        }

        if (dateButton && dateInput) {
            dateButton.addEventListener('click', function () {
                if (typeof dateInput.showPicker === 'function') {
                    dateInput.showPicker();
                } else {
                    dateInput.focus();
                }
            });
        }

        if (loadMoreButton) {
            loadMoreButton.addEventListener('click', function () {
                if (visibleCount < allFlights.length) {
                    visibleCount = Math.min(allFlights.length, visibleCount + pageSize);
                    renderPage();
                }
            });
        }

        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                var isLight = board.classList.contains('is-light');
                setTheme(isLight ? 'dark' : 'light');
            });
            initTheme();
        } else {
            setTheme('dark');
        }

        function locateNearestAirport() {
            if (!navigator.geolocation) {
                updateStatus('Geolocation not available.');
                loadBoard(defaultAirport);
                return;
            }

            updateStatus('Finding nearest airport...');
            navigator.geolocation.getCurrentPosition(
                function (position) {
                    var lat = position.coords.latitude;
                    var lon = position.coords.longitude;

                    var url =
                        AirportFID.restUrl +
                        '/nearest?lat=' +
                        encodeURIComponent(lat) +
                        '&lon=' +
                        encodeURIComponent(lon);

                    fetchJson(url)
                        .then(function (data) {
                            var airport = (data.code || defaultAirport || '').toUpperCase();
                            board.dataset.airport = airport;
                            if (input) {
                                input.value = airport;
                            }
                            loadBoard(airport);
                        })
                        .catch(function () {
                            loadBoard(defaultAirport);
                        });
                },
                function (error) {
                    if (error && error.code === 1 && geoButton) {
                        geoButton.style.display = 'none';
                    }
                    loadBoard(defaultAirport);
                },
                {
                    timeout: 8000,
                }
            );
        }

        if (geoButton) {
            if (!navigator.geolocation) {
                geoButton.style.display = 'none';
            } else {
                geoButton.addEventListener('click', function () {
                    locateNearestAirport();
                });
            }
        }

        if (useGeo) {
            locateNearestAirport();
        } else {
            loadBoard(defaultAirport);
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var boards = document.querySelectorAll('.airport-fid-board');
        boards.forEach(initBoard);
    });
})();
