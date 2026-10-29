/*
 * A booking website's booking box and checkout form.
 *
 * Plain script, no build step: the property page works without it (the date
 * form asks the server for a price), and with it the guest picks nights on a
 * calendar of what is open and sees the price as they go.
 *
 * Dates travel as YYYY-MM-DD strings and are worked on in UTC, so a guest's
 * own time zone never moves a night.
 */
(function () {
    'use strict';

    var WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    document.querySelectorAll('[data-booking-box]').forEach(bookingBox);
    document.querySelectorAll('[data-checkout-form]').forEach(checkoutForm);

    // ------------------------------------------------------------------
    // The booking box
    // ------------------------------------------------------------------

    function bookingBox(box) {
        var form = box.querySelector('form');
        var inputs = { checkIn: form.elements.checkin, checkOut: form.elements.checkout, guests: form.elements.guests };
        var calendar = box.querySelector('[data-calendar]');
        var quoteArea = box.querySelector('[data-quote]');
        var notice = box.querySelector('[data-booking-notice]');
        var head = box.querySelector('[data-price-head]');
        var days = {};
        var fetched = {};
        var asked = 0;
        var state = { checkIn: inputs.checkIn.value || null, checkOut: inputs.checkOut.value || null };
        var view = monthOf(state.checkIn || today());

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            state.checkIn = inputs.checkIn.value || null;
            state.checkOut = inputs.checkOut.value || null;

            if (state.checkIn) {
                view = monthOf(state.checkIn);
            }

            load(view).then(render);
            priceIfReady();
        });

        [inputs.checkIn, inputs.checkOut].forEach(function (input) {
            input.addEventListener('change', function () {
                form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit', { cancelable: true }));
            });
        });

        inputs.guests.addEventListener('change', priceIfReady);

        calendar.hidden = false;
        load(view).then(render);
        priceIfReady();

        function load(month) {
            // Each answer covers two months, so every other month is a request.
            var keys = [month, addMonths(month, 1)];

            if (keys.every(function (key) { return fetched[key]; })) {
                return Promise.resolve();
            }

            return getJson(box.dataset.calendarUrl + '?month=' + month.slice(0, 7)).then(function (body) {
                (body.days || []).forEach(function (day) { days[day.date] = day; });
                keys.forEach(function (key) { fetched[key] = true; });
            }).catch(function () {
                say('The calendar could not be loaded. You can still type your dates.');
            });
        }

        function render() {
            calendar.textContent = '';

            var head = el('div', 'cal__head');
            var previous = button('‹', 'Previous month', function () { move(-1); });
            previous.disabled = view <= monthOf(today());
            head.appendChild(previous);
            head.appendChild(el('div', 'cal__title', monthName(view)));
            head.appendChild(button('›', 'Next month', function () { move(1); }));
            calendar.appendChild(head);

            var grid = el('div', 'cal__grid');
            grid.setAttribute('role', 'grid');
            WEEKDAYS.forEach(function (name) { grid.appendChild(el('div', 'cal__weekday', name)); });

            var first = parse(view);
            var lead = (first.getUTCDay() + 6) % 7;

            for (var i = 0; i < lead; i++) {
                grid.appendChild(el('div', 'cal__blank'));
            }

            for (var date = view; date.slice(0, 7) === view.slice(0, 7); date = addDays(date, 1)) {
                grid.appendChild(dayCell(date));
            }

            calendar.appendChild(grid);

            var hint = el('p', 'cal__hint muted small');
            if (state.checkIn && state.checkOut) {
                hint.textContent = plural(nightsBetween(state.checkIn, state.checkOut), 'night') + '. Choose another date to start again.';
            } else if (state.checkIn) {
                hint.textContent = 'Now choose your check-out date.';
            } else {
                hint.textContent = 'Choose your check-in date.';
            }
            calendar.appendChild(hint);
        }

        function dayCell(date) {
            var cell = el('button', 'cal__day', String(parse(date).getUTCDate()));
            cell.type = 'button';
            cell.setAttribute('aria-label', longDate(date));

            var choosable = choosingCheckOut() ? canLeaveOn(date) : canArriveOn(date);
            cell.disabled = !choosable;

            if (days[date] && !days[date].available) {
                cell.classList.add('is-taken');
            }

            if (date === state.checkIn) {
                cell.classList.add('is-start');
            }

            if (date === state.checkOut) {
                cell.classList.add('is-end');
            }

            if (state.checkIn && state.checkOut && date > state.checkIn && date < state.checkOut) {
                cell.classList.add('is-between');
            }

            cell.setAttribute('aria-pressed', date === state.checkIn || date === state.checkOut ? 'true' : 'false');
            cell.addEventListener('click', function () { choose(date); });

            return cell;
        }

        function choosingCheckOut() {
            return state.checkIn !== null && state.checkOut === null;
        }

        function canArriveOn(date) {
            var day = days[date];

            return date >= today() && day !== undefined && day.available && !day.closed_to_arrival;
        }

        // A guest can leave on any morning after arrival as long as every
        // night in between is open; the morning they leave can be taken.
        function canLeaveOn(date) {
            if (date <= state.checkIn) {
                return canArriveOn(date);
            }

            for (var night = state.checkIn; night < date; night = addDays(night, 1)) {
                if (!days[night] || !days[night].available) {
                    return false;
                }
            }

            return true;
        }

        function choose(date) {
            say('');

            if (!choosingCheckOut() || date <= state.checkIn) {
                state.checkIn = date;
                state.checkOut = null;
                quoteArea.textContent = '';
                forgetServerAnswer();
                showFrom();
            } else {
                var minimum = (days[state.checkIn] && days[state.checkIn].min_nights) || 1;

                if (nightsBetween(state.checkIn, date) < minimum) {
                    say('Stays from ' + longDate(state.checkIn) + ' are at least ' + minimum + ' nights.');

                    return;
                }

                state.checkOut = date;
            }

            inputs.checkIn.value = state.checkIn || '';
            inputs.checkOut.value = state.checkOut || '';
            render();
            priceIfReady();
        }

        function move(step) {
            view = addMonths(view, step);
            render();
            load(view).then(render);
        }

        function priceIfReady() {
            if (!state.checkIn || !state.checkOut) {
                return;
            }

            var mine = ++asked;
            quoteArea.textContent = '';
            forgetServerAnswer();
            quoteArea.appendChild(el('p', 'muted', 'Working out the price…'));

            postJson(box.dataset.quoteUrl, {
                check_in: state.checkIn,
                check_out: state.checkOut,
                adults: Math.max(1, parseInt(inputs.guests.value, 10) || 1),
            }).then(function (answer) {
                if (mine !== asked) {
                    return;
                }

                quoteArea.textContent = '';

                if (answer.status === 200) {
                    showQuote(answer.body);
                } else {
                    showFrom();
                    say(problem(answer));
                }
            });
        }

        // What the page arrived with answered the dates in its address; once
        // the guest picks others, only the answer for those is shown.
        function forgetServerAnswer() {
            box.querySelectorAll('[data-server-notice]').forEach(function (node) { node.hidden = true; });
        }

        function showFrom() {
            if (!head) {
                return;
            }

            head.textContent = '';

            if (head.dataset.fromAmount) {
                head.appendChild(document.createTextNode('From '));
                head.appendChild(el('strong', '', head.dataset.fromAmount));
                head.appendChild(el('span', 'muted', ' a night'));
            }
        }

        function showTotal(quote) {
            if (!head) {
                return;
            }

            head.textContent = '';
            head.appendChild(el('strong', '', quote.totals.grand_total.display));
            head.appendChild(el('span', 'muted', ' for ' + plural(quote.nights.length, 'night')));
        }

        function showQuote(quote) {
            showTotal(quote);

            var list = el('dl', 'quote');
            var nights = quote.nights.length;
            var rates = quote.nights.map(function (night) { return night.amount.amount; });
            var even = rates.every(function (rate) { return rate === rates[0]; });

            line(list, even && nights > 0
                ? quote.nights[0].amount.display + ' × ' + plural(nights, 'night')
                : plural(nights, 'night'), quote.totals.accommodation.display);

            quote.fees.forEach(function (fee) { line(list, fee.label, fee.amount.display); });
            quote.discounts.forEach(function (discount) { line(list, discount.label, '−' + discount.amount.display); });
            quote.taxes.forEach(function (tax) { line(list, tax.label, tax.amount.display); });
            line(list, 'Total', quote.totals.grand_total.display, 'quote__total');
            quoteArea.appendChild(list);

            if (quote.due_now.amount < quote.totals.grand_total.amount) {
                quoteArea.appendChild(el('p', 'muted small', quote.due_now.display + ' is due now and the rest before you arrive.'));
            }

            var book = el('a', 'button button--wide', quote.mode === 'instant' ? 'Book now' : 'Request to book');
            book.href = box.dataset.checkoutUrl + '/' + encodeURIComponent(quote.quote_id);
            quoteArea.appendChild(book);

            if (quote.mode === 'request') {
                quoteArea.appendChild(el('p', 'muted small', 'The host answers your request before anything is charged.'));
            }
        }

        function say(text) {
            notice.textContent = text;
            notice.hidden = text === '';
        }
    }

    // ------------------------------------------------------------------
    // The checkout form
    // ------------------------------------------------------------------

    function checkoutForm(form) {
        var submit = form.querySelector('[type="submit"]');
        var message = form.querySelector('[data-form-message]');

        // A field's complaint goes as soon as the guest changes it.
        form.addEventListener('input', function (event) {
            var slot = event.target.name ? form.querySelector('[data-error-for="' + event.target.name + '"]') : null;

            if (slot) {
                slot.textContent = '';
                slot.hidden = true;
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            form.querySelectorAll('[data-error-for]').forEach(function (slot) {
                slot.textContent = '';
                slot.hidden = true;
            });
            message.hidden = true;
            submit.disabled = true;

            var data = {};
            ['quote_id', 'first_name', 'last_name', 'email', 'phone', 'message'].forEach(function (name) {
                data[name] = form.elements[name] ? form.elements[name].value : null;
            });
            data.accept_policies = form.elements.accept_policies.checked;

            postJson(form.dataset.endpoint, data).then(function (answer) {
                if (answer.status === 201) {
                    window.location.assign(answer.body.booking_url);

                    return;
                }

                submit.disabled = false;

                var errors = (answer.body && answer.body.errors) || {};
                var shown = false;

                Object.keys(errors).forEach(function (field) {
                    var slot = form.querySelector('[data-error-for="' + field + '"]');

                    if (slot) {
                        slot.textContent = errors[field][0];
                        slot.hidden = false;
                        shown = true;
                    }
                });

                if (!shown) {
                    message.textContent = problem(answer);
                    message.hidden = false;
                }
            });
        });
    }

    // ------------------------------------------------------------------
    // Shared
    // ------------------------------------------------------------------

    function problem(answer) {
        if (answer.status === 429 && !(answer.body && answer.body.message && answer.body.message.indexOf('waiting for payment') !== -1)) {
            return 'That was a lot of requests at once. Please wait a moment and try again.';
        }

        if (answer.body && answer.body.message) {
            return answer.body.message;
        }

        return 'Something went wrong. Please try again, or get in touch with us.';
    }

    function getJson(url) {
        return fetch(url, { headers: { Accept: 'application/json' } }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        });
    }

    function postJson(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(data),
        }).then(function (response) {
            return response.json().catch(function () { return null; }).then(function (body) {
                return { status: response.status, body: body };
            });
        }).catch(function () {
            return { status: 0, body: null };
        });
    }

    function line(list, label, amount, extra) {
        var row = el('div', 'quote__line' + (extra ? ' ' + extra : ''));
        row.appendChild(el('dt', '', label));
        row.appendChild(el('dd', '', amount));
        list.appendChild(row);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (text !== undefined) {
            node.textContent = text;
        }

        return node;
    }

    function button(text, label, onClick) {
        var node = el('button', 'cal__nav', text);
        node.type = 'button';
        node.setAttribute('aria-label', label);
        node.addEventListener('click', onClick);

        return node;
    }

    function plural(count, word) {
        return count + ' ' + word + (count === 1 ? '' : 's');
    }

    function today() {
        var now = new Date();

        return format(new Date(Date.UTC(now.getFullYear(), now.getMonth(), now.getDate())));
    }

    function parse(date) {
        var parts = date.split('-');

        return new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2]));
    }

    function format(date) {
        return date.toISOString().slice(0, 10);
    }

    function addDays(date, count) {
        var next = parse(date);
        next.setUTCDate(next.getUTCDate() + count);

        return format(next);
    }

    function addMonths(month, count) {
        var next = parse(month);

        return format(new Date(Date.UTC(next.getUTCFullYear(), next.getUTCMonth() + count, 1)));
    }

    function monthOf(date) {
        return date.slice(0, 7) + '-01';
    }

    function nightsBetween(from, to) {
        return Math.round((parse(to) - parse(from)) / 86400000);
    }

    function monthName(month) {
        return parse(month).toLocaleDateString('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });
    }

    function longDate(date) {
        return parse(date).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' });
    }
})();
