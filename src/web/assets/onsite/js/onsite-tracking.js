(function () {
  var config = window.__commerceKlaviyoOnsite;
  if (!config || !config.publicApiKey) {
    return;
  }

  window._learnq = window._learnq || [];

  function track(metric, properties) {
    window._learnq.push(['track', metric, properties]);
  }

  function loadKlaviyoScript() {
    if (document.querySelector('script[data-commerce-klaviyo-onsite]')) {
      return;
    }

    var script = document.createElement('script');
    script.async = true;
    script.setAttribute('data-commerce-klaviyo-onsite', '1');
    script.src =
      'https://static.klaviyo.com/onsite/js/' +
      encodeURIComponent(config.publicApiKey) +
      '/klaviyo.js';
    document.head.appendChild(script);
  }

  loadKlaviyoScript();

  if (config.viewedProduct) {
    track('Viewed Product', config.viewedProduct);
  }

  if (config.pendingAddedToCart) {
    track('Added to Cart', config.pendingAddedToCart);
  }

  if (typeof window.fetch !== 'function') {
    return;
  }

  var originalFetch = window.fetch;

  window.fetch = function () {
    return originalFetch.apply(this, arguments).then(function (response) {
      if (!response || typeof response.clone !== 'function') {
        return response;
      }

      var contentType = response.headers.get('content-type') || '';

      if (contentType.indexOf('application/json') === -1) {
        return response;
      }

      return response
        .clone()
        .json()
        .then(function (data) {
          var payload =
            data &&
            data.commerceKlaviyo &&
            data.commerceKlaviyo.addedToCart;

          if (payload) {
            track('Added to Cart', payload);
          }
        })
        .catch(function () {
          // Ignore malformed cart JSON — the original response is still returned.
        })
        .then(function () {
          return response;
        });
    });
  };
})();
