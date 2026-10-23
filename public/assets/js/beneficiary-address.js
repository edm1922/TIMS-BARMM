document.addEventListener('DOMContentLoaded', function () {
    var widget = document.querySelector('[data-address-widget]');
    if (!widget) {
        return;
    }

    var input = widget.querySelector('[data-address-input]');
    var code = widget.querySelector('[data-address-code]');
    var results = widget.querySelector('[data-address-results]');
    var city = document.querySelector('[data-address-city]');
    var province = document.querySelector('[data-address-province]');
    var endpoint = widget.getAttribute('data-endpoint') || '/locations/search';
    var timer = null;

    function hide() {
        results.classList.add('d-none');
        results.innerHTML = '';
    }

    function render(items) {
        if (!items.length) {
            hide();
            return;
        }

        results.innerHTML = '';
        items.forEach(function (item) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action py-1 small';
            button.textContent = item.full_address;
            button.addEventListener('click', function () {
                input.value = item.full_address;
                code.value = item.psgc_code;
                if (city) {
                    city.value = item.city_municipality;
                }
                if (province) {
                    province.value = item.province;
                }
                hide();
            });
            results.appendChild(button);
        });
        results.classList.remove('d-none');
    }

    input.addEventListener('input', function () {
        code.value = '';
        window.clearTimeout(timer);
        var term = input.value.trim();
        if (term.length < 2) {
            hide();
            return;
        }

        timer = window.setTimeout(function () {
            fetch(endpoint + '?q=' + encodeURIComponent(term), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then(function (response) { return response.ok ? response.json() : { results: [] }; })
                .then(function (data) { render(data.results || []); })
                .catch(function () { hide(); });
        }, 250);
    });

    document.addEventListener('click', function (event) {
        if (!widget.contains(event.target)) {
            hide();
        }
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            hide();
        }
    });
});
