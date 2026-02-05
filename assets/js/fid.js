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
            var departureText = flight.departure_time || '';
            var previous = lastMap && lastMap[key] ? lastMap[key] : null;
            var animateDeparture = !previous || previous.departure_time !== departureText;
            var arrivalText = flight.arrival_time || '';
            var animateArrival = !previous || previous.arrival_time !== arrivalText;
            var rangeLine = document.createElement('div');
            rangeLine.className = 'airport-fid-time-range';
            var clockIcon = document.createElement('span');
            clockIcon.className = 'airport-fid-icon';
            clockIcon.setAttribute('aria-hidden', 'true');
            clockIcon.innerHTML =
                '<svg viewBox="0 0 20 20" focusable="false" aria-hidden="true">' +
                '<circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="2" fill="none"></circle>' +
                '<path d="M10 6v4l3 2" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"></path>' +
                '</svg>';
            rangeLine.appendChild(clockIcon);
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
            var airportLine = document.createElement('div');
            airportLine.className = 'airport-fid-airport';
            var animateAirport = !previous || previous.airport_label !== airportLabel;
            var planeIcon = document.createElement('span');
            planeIcon.className = 'airport-fid-icon';
            planeIcon.setAttribute('aria-hidden', 'true');
            planeIcon.innerHTML =
                '<svg viewBox="0 0 24 24" focusable="false" aria-hidden="true">' +
                '<path d="M2 12l9-2 7-7 3 3-7 7-2 9-3-4-4-2z" fill="currentColor"></path>' +
                '</svg>';
            airportLine.appendChild(planeIcon);
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
                var moreLabel = document.createElement('span');
                moreLabel.className = 'airport-fid-more-label';
                moreLabel.textContent = 'See more details';
                toggleCell.insertBefore(moreLabel, toggleButton);
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
                    ? flight.origin_name + ' (' + flight.origin_code + ')'
                    : flight.origin_code || '';
            var destinationLabel =
                (flight.destination_name || '') && flight.destination
                    ? flight.destination_name + ' (' + flight.destination + ')'
                    : flight.destination || '';

            var equipmentLabel = flight.equipment_name
                ? flight.equipment_name + ' (' + (flight.equipment || '') + ')'
                : flight.equipment || '';

            addDetail('Equipment', equipmentLabel);
            addDetail('Departure', flight.departure_time || '');
            addDetail('Arrival', flight.arrival_time || '');
            addDetail('Terminal', flight.terminal || '');
            addDetail('Origin', originLabel);
            addDetail('Destination', destinationLabel);

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
        var loadMoreButton = board.querySelector('.airport-fid-load-more');
        var pageNotice = board.querySelector('.airport-fid-page-notice');
        var loadingOverlay = board.querySelector('.airport-fid-loading');
        var themeToggle = board.querySelector('.airport-fid-theme-toggle');

        var allFlights = [];
        var visibleCount = 0;
        var lastFlightMap = {};
        var suggestTimer = null;
        var lastQuery = '';

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
                pageNotice.textContent = '';
                return;
            }

            if (visibleCount >= allFlights.length) {
                loadMoreButton.style.display = 'none';
                pageNotice.textContent = 'No more results.';
            } else {
                loadMoreButton.style.display = 'inline-flex';
                pageNotice.textContent = '';
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

            var url = AirportFID.restUrl + '/board?airport=' + encodeURIComponent(iata);
            var dateValue = dateInput ? fromInputDate(dateInput.value) : '';
            url += '&date=' + encodeURIComponent(dateValue || getLocalDateString());
            url += '&limit=0';

            fetchJson(url)
                .then(function (data) {
                    var label = data.airport_name || data.airport;
                    updateStatus('Showing flights for ' + label + '.');
                    allFlights = data.flights || [];
                    visibleCount = Math.min(pageSize, allFlights.length);
                    renderPage();
                    hideLoading();
                })
                .catch(function () {
                    updateStatus('Unable to load flight data.');
                    hideLoading();
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
