/*
 * Live preview for the quote builder. Mirrors App\Booking\QuoteCalculator and
 * PaymentSchedule::depositAmount(); the server recalculates on save and its
 * numbers are the ones stored. Without JavaScript the form still works.
 */
(function () {
  "use strict";

  var form = document.querySelector("[data-quote-builder]");
  if (!form) {
    return;
  }

  var MAX_AMOUNT = 100000000000;
  var depositPercent = parseInt(form.getAttribute("data-deposit-percent"), 10) || 0;
  var excludeId = form.getAttribute("data-exclude");
  var formatter = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 0 });

  function rupiah(amount) {
    return (amount < 0 ? "-" : "") + "Rp " + formatter.format(Math.abs(amount));
  }

  // Same rules as App\Support\Money::parse().
  function parseMoney(value) {
    var clean = String(value).replace(/rp/i, "").replace(/[\s ]/g, "");
    if (clean === "") {
      return null;
    }
    if (/^\d{1,3}([.,]\d{3})+$/.test(clean)) {
      clean = clean.replace(/[.,]/g, "");
    }
    if (!/^\d{1,12}$/.test(clean)) {
      return NaN;
    }
    var amount = parseInt(clean, 10);
    return amount <= MAX_AMOUNT ? amount : NaN;
  }

  function el(tag, text, className) {
    var node = document.createElement(tag);
    if (text !== undefined) {
      node.textContent = text;
    }
    if (className) {
      node.className = className;
    }
    return node;
  }

  function collectLines() {
    var lines = [];
    var invalid = false;
    var pkg = form.querySelector('input[name="package_id"]:checked');
    if (pkg) {
      var price = parseInt(pkg.getAttribute("data-price"), 10);
      var overrideInput = form.querySelector("[data-package-price]");
      if (overrideInput) {
        var override = parseMoney(overrideInput.value);
        if (Number.isNaN(override)) {
          invalid = true;
        } else if (override !== null) {
          price = override;
        }
      }
      lines.push({ name: pkg.getAttribute("data-name"), qty: 1, unit: price });
    }

    form.querySelectorAll("[data-addon-row]").forEach(function (row) {
      var qtyInput = row.querySelector("[data-qty]");
      var qty = parseInt(qtyInput.value, 10);
      var priceInput = row.querySelector("[data-unit-price]");
      var staticPrice = row.querySelector("[data-unit-price-static]");
      var unit = priceInput ? parseMoney(priceInput.value) : parseInt(staticPrice.getAttribute("data-unit-price-static"), 10);
      var out = row.querySelector("[data-line-total]");

      if (Number.isNaN(unit) || unit === null) {
        invalid = invalid || (qty > 0);
        out.textContent = "—";
        return;
      }
      if (!(qty > 0)) {
        out.textContent = "—";
        return;
      }
      if (qty > 1000) {
        invalid = true;
      }
      out.textContent = rupiah(unit * qty);
      lines.push({ name: qtyInput.getAttribute("data-name"), qty: qty, unit: unit });
    });

    return { lines: lines, invalid: invalid };
  }

  function recalculate() {
    var collected = collectLines();
    var subtotal = collected.lines.reduce(function (sum, line) {
      return sum + line.unit * line.qty;
    }, 0);
    var discount = parseMoney(form.querySelector("[data-discount]").value);
    var error = "";

    if (collected.invalid) {
      error = "Some prices or quantities are not valid. They will be flagged when you save.";
    }
    if (Number.isNaN(discount)) {
      error = "The discount must be a whole rupiah amount.";
      discount = 0;
    }
    discount = discount || 0;
    if (discount > subtotal) {
      error = "The discount cannot be larger than the subtotal.";
    }

    var total = Math.max(0, subtotal - discount);
    var list = form.querySelector("[data-summary-lines]");
    list.replaceChildren();
    if (collected.lines.length === 0) {
      list.appendChild(el("li", "Pick a package to start.", "muted"));
    }
    collected.lines.forEach(function (line) {
      var li = el("li");
      li.appendChild(el("span", line.qty > 1 ? line.name + " × " + line.qty : line.name));
      li.appendChild(el("span", rupiah(line.unit * line.qty), "money"));
      list.appendChild(li);
    });

    form.querySelector("[data-subtotal]").textContent = rupiah(subtotal);
    form.querySelector("[data-discount-out]").textContent = discount > 0 ? "−" + rupiah(discount) : rupiah(0);
    form.querySelector("[data-total]").textContent = rupiah(total);
    form.querySelector("[data-deposit]").textContent = rupiah(Math.floor((total * depositPercent + 99) / 100));
    form.querySelector("[data-summary-error]").textContent = error;
  }

  var availabilityTimer = null;
  var availabilityRequest = 0;

  function setResourceState(option, text, state) {
    option.classList.remove("is-free", "is-busy");
    if (state) {
      option.classList.add(state);
    }
    option.querySelector("[data-resource-state]").textContent = text;
  }

  function checkAvailability() {
    var start = form.querySelector("#setup_start").value;
    var end = form.querySelector("#teardown_end").value;
    var status = form.querySelector("[data-availability-status]");
    if (!start || !end) {
      status.textContent = "Set the setup and teardown times to check availability.";
      return;
    }

    var params = new URLSearchParams({ start: start, end: end });
    if (excludeId) {
      params.set("exclude", excludeId);
    }
    var requestId = ++availabilityRequest;
    status.textContent = "Checking availability…";

    fetch("/api/availability?" + params.toString(), {
      headers: { Accept: "application/json" },
      credentials: "same-origin"
    })
      .then(function (response) {
        return response.json().then(function (body) {
          return { ok: response.ok, body: body };
        });
      })
      .then(function (result) {
        if (requestId !== availabilityRequest) {
          return;
        }
        if (!result.ok) {
          var fields = result.body.fields || {};
          status.textContent = result.body.error && !result.body.fields
            ? result.body.error
            : Object.keys(fields).map(function (k) { return fields[k]; }).join(" ") || "Check the setup and teardown times.";
          return;
        }
        var free = 0;
        result.body.resources.forEach(function (resource) {
          var option = form.querySelector('[data-resource="' + resource.id + '"]');
          if (!option) {
            return;
          }
          if (option.querySelector("input").hasAttribute("data-held")) {
            setResourceState(option, resource.available ? "Held by this booking" : "Held, but now conflicts", resource.available ? "is-free" : "is-busy");
            return;
          }
          if (resource.available) {
            free++;
            setResourceState(option, "Free for this window", "is-free");
          } else {
            var first = resource.conflicts[0];
            setResourceState(option, "Busy: " + first.code + " (" + first.window + ")", "is-busy");
          }
        });
        status.textContent = "Window " + result.body.window + ": " + free + " free, including a " + result.body.buffer_minutes + "-minute changeover.";
      })
      .catch(function () {
        if (requestId === availabilityRequest) {
          status.textContent = "Could not check availability. Saving will still check every resource.";
        }
      });
  }

  form.addEventListener("input", function (event) {
    recalculate();
    if (event.target.hasAttribute("data-window")) {
      window.clearTimeout(availabilityTimer);
      availabilityTimer = window.setTimeout(checkAvailability, 350);
    }
  });
  form.addEventListener("change", recalculate);

  recalculate();
  checkAvailability();
})();
